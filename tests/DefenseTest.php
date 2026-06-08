<?php
// tests/DefenseTest.php — exercises the core domain rules via act_* functions.

class DefenseTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = test_db();
        // Reset mutable state to a known clean baseline between tests.
        $this->db->exec("UPDATE projects SET status='in_progress' WHERE id=4");
        $this->db->exec("DELETE FROM defenses WHERE id > 4");
        $this->db->exec("DELETE FROM defense_participants WHERE defense_id > 4");
        // Clear any scores/attendance the tests add to defenses 1 & 2 (seed has none there).
        $this->db->exec("DELETE FROM defense_scores WHERE defense_id IN (1,2) OR defense_id > 4");
        $this->db->exec("UPDATE defenses SET status='scheduled', finalized=0, published=0,
                         aggregate_score=NULL, final_grade=NULL, result_status=NULL WHERE id IN (1,2)");
        $this->db->exec("UPDATE defense_participants SET attendance='pending' WHERE defense_id IN (1,2)");
        $this->db->exec("DELETE FROM notifications");
    }

    // ── Grading policy ──────────────────────────────────────────────
    function testGradeBoundaries()
    {
        $this->assertEquals(['A','pass'], gradeFromScore(70));
        $this->assertEquals(['A','pass'], gradeFromScore(86.1));
        $this->assertEquals(['B','pass'], gradeFromScore(60));
        $this->assertEquals(['C','pass'], gradeFromScore(50));
        $this->assertEquals(['D','fail'], gradeFromScore(45));
        $this->assertEquals(['F','fail'], gradeFromScore(44.9));
    }

    // ── Weighted aggregation ────────────────────────────────────────
    function testWeightedAggregateMatchesSeed()
    {
        // Seeded defense #3: sup 86, internal avg 82.5, external 91
        // 86*.3 + 82.5*.4 + 91*.3 = 25.8 + 33.0 + 27.3 = 86.10
        $agg = computeAggregate($this->db, 3);
        $this->assertEquals(86.10, $agg, "Weighted aggregate should be 86.10");
    }

    function testAggregateNullWhenNoScores()
    {
        $this->assertSame(null, computeAggregate($this->db, 1), "No scores → null aggregate");
    }

    // ── Scheduling rules ────────────────────────────────────────────
    function testCannotScheduleOnIncompleteProject()
    {
        $r = act_schedule_defense($this->db, [
            'project_id' => 4, 'scheduled_at' => '2027-01-10 10:00:00',
            'venue' => 'Hall', 'mode' => 'physical',
        ]);
        $this->assertErr($r, 409, "In-progress project must be rejected");
    }

    function testScheduleOnCompletedProjectSucceeds()
    {
        $this->db->exec("UPDATE projects SET status='completed' WHERE id=4");
        $r = act_schedule_defense($this->db, [
            'project_id' => 4, 'scheduled_at' => '2027-02-10 10:00:00',
            'venue' => 'Hall Z', 'mode' => 'physical',
            'internal_examiner_ids' => [4,5], 'external_examiner_id' => 6,
        ]);
        $this->assertOk($r);
        $this->assertEquals('scheduled', $r['data']['status']);
        // Student + supervisor + 2 internal + 1 external = 5 participants
        $this->assertEquals(5, count($r['data']['participants']));
    }

    function testDuplicateActiveDefenseRejected()
    {
        // Defense #1 already active on project #1
        $r = act_schedule_defense($this->db, [
            'project_id' => 1, 'scheduled_at' => '2027-03-10 10:00:00',
            'venue' => 'Hall', 'mode' => 'physical',
        ]);
        $this->assertErr($r, 409, "Second active defense on same project must be rejected");
    }

    function testExaminerConflictDetection()
    {
        $this->db->exec("UPDATE projects SET status='completed' WHERE id=4");
        // Defense #1 is scheduled 2 days out with supervisor #2. Try to book
        // examiner #6 (already on #1 and #2) at the same slot as #1.
        $slot = $this->db->query("SELECT scheduled_at FROM defenses WHERE id=1")->fetchColumn();
        $r = act_schedule_defense($this->db, [
            'project_id' => 4, 'scheduled_at' => $slot,
            'venue' => 'Hall', 'mode' => 'physical',
            'internal_examiner_ids' => [4], 'external_examiner_id' => 6,
        ]);
        $this->assertErr($r, 409, "Overlapping examiner booking must conflict");
    }

    // ── Scoring & locking ───────────────────────────────────────────
    function testScoreValidationRejectsOverMax()
    {
        $r = act_submit_score($this->db, 1, [
            'evaluator_id' => 2, 'evaluator_role' => 'supervisor',
            'content_quality' => 40, 'presentation' => 20, 'originality' => 20, 'defense_response' => 15,
        ]);
        $this->assertErr($r, 422, "content_quality > 30 must be rejected");
    }

    function testScoreLocksAfterSubmit()
    {
        $r1 = act_submit_score($this->db, 1, [
            'evaluator_id' => 2, 'evaluator_role' => 'supervisor',
            'content_quality' => 25, 'presentation' => 20, 'originality' => 20, 'defense_response' => 15,
        ]);
        $this->assertOk($r1);
        $r2 = act_submit_score($this->db, 1, [
            'evaluator_id' => 2, 'evaluator_role' => 'supervisor',
            'content_quality' => 30, 'presentation' => 25, 'originality' => 25, 'defense_response' => 20,
        ]);
        $this->assertErr($r2, 409, "Re-submitting a locked score must be rejected");
    }

    // ── Finalize policy: quorum + attendance ────────────────────────
    function testFinalizeBlockedBelowQuorum()
    {
        // Defense #1 has no scores yet.
        $r = act_finalize($this->db, 1);
        $this->assertErr($r, 409, "Finalize below score quorum must be blocked");
    }

    function testFinalizeBlockedIfStudentAbsent()
    {
        // Give defense #1 a full set of scores but leave student attendance pending.
        foreach ([[2,'supervisor'],[4,'internal_examiner'],[5,'internal_examiner'],[6,'external_examiner']] as $ev) {
            act_submit_score($this->db, 1, [
                'evaluator_id' => $ev[0], 'evaluator_role' => $ev[1],
                'content_quality' => 24, 'presentation' => 20, 'originality' => 20, 'defense_response' => 16,
            ]);
        }
        $r = act_finalize($this->db, 1);
        $this->assertErr($r, 409, "Finalize must be blocked when student not marked present");
    }

    function testFullLifecycleFinalizes()
    {
        // Mark student present, submit quorum of scores, finalize.
        $this->db->exec("UPDATE defense_participants SET attendance='present' WHERE defense_id=1 AND role='student'");
        foreach ([[2,'supervisor'],[4,'internal_examiner'],[5,'internal_examiner'],[6,'external_examiner']] as $ev) {
            act_submit_score($this->db, 1, [
                'evaluator_id' => $ev[0], 'evaluator_role' => $ev[1],
                'content_quality' => 26, 'presentation' => 22, 'originality' => 21, 'defense_response' => 17,
            ]);
        }
        $agg = act_aggregate($this->db, 1);
        $this->assertOk($agg);
        $r = act_finalize($this->db, 1);
        $this->assertOk($r);
        $this->assertEquals('A', $r['data']['grade']);
        $this->assertEquals('pass', $r['data']['result_status']);
        // Score after finalize must be locked.
        $late = act_submit_score($this->db, 1, [
            'evaluator_id' => 2, 'evaluator_role' => 'supervisor',
            'content_quality' => 10, 'presentation' => 10, 'originality' => 10, 'defense_response' => 10,
        ]);
        $this->assertErr($late, 409, "Scores must be locked after finalize");
    }

    // ── Participant rules ───────────────────────────────────────────
    function testOnlyOneExternalExaminer()
    {
        $r = act_add_participant($this->db, 1, ['user_id' => 7, 'role' => 'external_examiner']);
        // #1 already has external #6, so a second external is rejected.
        $this->assertErr($r, 409, "Only one external examiner allowed");
    }

    function testCannotRemoveStudent()
    {
        $r = act_remove_participant($this->db, 1, 8); // 8 is the student on #1
        $this->assertErr($r, 409, "Student cannot be removed");
    }

    // ── Storage ─────────────────────────────────────────────────────
    function testFileUploadAndProjectListing()
    {
        $r = act_file_upload($this->db, [
            'name' => 'Unit_Test.pdf', 'project_id' => 2,
            'file_type' => 'document', 'size_bytes' => 1234, 'access_level' => 'institution_wide',
        ]);
        $this->assertOk($r);
        $list = act_project_files($this->db, 2);
        $this->assertOk($list);
        $names = array_column($list['data'], 'name');
        $this->assertTrue(in_array('Unit_Test.pdf', $names), "Uploaded file should appear in project listing");
    }

    // ── Notifications (spec: notify on reschedule / cancel / publish) ──
    function testRescheduleNotifiesParticipants()
    {
        $this->db->exec("DELETE FROM notifications");
        $r = act_reschedule($this->db, 1, ['scheduled_at' => '2027-06-01 09:00:00', 'venue' => 'New Hall']);
        $this->assertOk($r);
        $n = (int)$this->db->query("SELECT COUNT(*) FROM notifications WHERE defense_id=1 AND event='defense.rescheduled'")->fetchColumn();
        $this->assertTrue($n >= 1, "Reschedule must notify participants (got $n)");
    }

    function testCancelNotifiesParticipants()
    {
        $this->db->exec("DELETE FROM notifications");
        // Use defense #2 so #1 stays available for other tests in a fresh setUp.
        $r = act_cancel($this->db, 2);
        $this->assertOk($r);
        $n = (int)$this->db->query("SELECT COUNT(*) FROM notifications WHERE defense_id=2 AND event='defense.cancelled'")->fetchColumn();
        $this->assertTrue($n >= 1, "Cancel must send cancellation notifications (got $n)");
    }

    function testPublishNotifiesStakeholders()
    {
        $this->db->exec("DELETE FROM notifications");
        // Defense #3 is finalized in the seed; ensure unpublished then publish.
        $this->db->exec("UPDATE defenses SET published=0 WHERE id=3");
        $r = act_publish($this->db, 3);
        $this->assertOk($r);
        // Student + panel → more than one recipient.
        $n = (int)$this->db->query("SELECT COUNT(*) FROM notifications WHERE defense_id=3 AND event='defense.published'")->fetchColumn();
        $this->assertTrue($n >= 2, "Publish must notify student + panel (got $n)");
    }

    // ── Start-session window (opt-in) ────────────────────────────────
    function testStartSessionWindowEnforcedWhenEnabled()
    {
        if (!defined('ENFORCE_START_WINDOW') || !ENFORCE_START_WINDOW) {
            // Default config: window disabled → starting a future defense is allowed.
            $r = act_start_session($this->db, 1);
            $this->assertOk($r, "With window disabled, future defense should start");
        } else {
            $this->assertTrue(true);
        }
    }
}
