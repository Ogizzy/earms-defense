<?php
// config/actions.php — EARMS Defense & Evaluation: shared domain operations.
// Pure-ish functions used by BOTH the JSON API (api/index.php) and the UI pages,
// so business rules (conflict detection, locking, weighting) are defined once.
// Each returns ['ok'=>bool, 'data'=>..., 'error'=>..., 'code'=>int].

require_once __DIR__ . '/db.php';

function ok($data = [], int $code = 200): array  { return ['ok' => true,  'data' => $data, 'code' => $code]; }
function err(string $msg, int $code = 400): array { return ['ok' => false, 'error' => $msg, 'code' => $code]; }

/* ───────────────────────── SCHEDULING ───────────────────────── */

function act_schedule_defense(PDO $db, array $in): array
{
    $projectId   = (int)($in['project_id'] ?? 0);
    $scheduledAt = trim($in['scheduled_at'] ?? '');
    $venue       = trim($in['venue'] ?? '');
    $mode        = $in['mode'] ?? 'physical';
    $externalId  = (int)($in['external_examiner_id'] ?? 0) ?: null;
    $internalIds = array_filter(array_map('intval', (array)($in['internal_examiner_ids'] ?? [])));

    if (!$projectId || !$scheduledAt) return err('project_id and scheduled_at are required', 422);
    if (!array_key_exists($mode, DEFENSE_MODES)) return err('Invalid mode', 422);

    $project = qrow($db, "SELECT * FROM projects WHERE id=?", [$projectId]);
    if (!$project) return err('Project not found', 404);

    // Spec: project must be completed before a defense can be scheduled.
    if ($project['status'] !== 'completed')
        return err('Project must be in completed status before scheduling a defense', 409);

    if (qval($db, "SELECT COUNT(*) FROM defenses WHERE project_id=? AND status!='cancelled'", [$projectId]))
        return err('An active defense already exists for this project', 409);

    $supervisorId = (int)$project['supervisor_id'];

    // Conflict detection: examiner double-booking + venue availability (±2h window).
    $examiners = array_merge([$supervisorId], $internalIds, $externalId ? [$externalId] : []);
    foreach ($examiners as $uid) {
        $clash = qval($db,
            "SELECT COUNT(*) FROM defense_participants p JOIN defenses d ON d.id=p.defense_id
             WHERE p.user_id=? AND d.status IN ('scheduled','ongoing')
               AND ABS(TIMESTAMPDIFF(MINUTE, d.scheduled_at, ?)) < 120", [$uid, $scheduledAt]);
        if ($clash) {
            $name = qval($db, "SELECT name FROM users WHERE id=?", [$uid]);
            return err("Scheduling conflict: $name is already booked within 2 hours of this slot", 409);
        }
    }
    if ($mode === 'physical' && $venue && strtolower($venue) !== 'virtual') {
        $venueClash = qval($db,
            "SELECT COUNT(*) FROM defenses WHERE venue=? AND status IN ('scheduled','ongoing')
               AND ABS(TIMESTAMPDIFF(MINUTE, scheduled_at, ?)) < 120", [$venue, $scheduledAt]);
        if ($venueClash) return err("Venue '$venue' is unavailable within 2 hours of this slot", 409);
    }

    $ref     = generateRef('DEF');
    if ($mode === 'virtual') {
        $meeting = MEETING_PROVIDER === 'jitsi'
            ? 'https://' . JITSI_DOMAIN . '/' . JITSI_ROOM_PREFIX . '-' . str_replace('-', '', $ref)
            : wings_join_url(wings_room_name($ref));
    } else {
        $meeting = null;
    }

    $db->prepare("INSERT INTO defenses (reference,project_id,student_id,supervisor_id,external_examiner_id,scheduled_at,venue,mode,status,meeting_url)
                  VALUES (?,?,?,?,?,?,?,?,'scheduled',?)")
       ->execute([$ref, $projectId, $project['student_id'], $supervisorId, $externalId, $scheduledAt, $venue, $mode, $meeting]);
    $defenseId = (int)$db->lastInsertId();

    // Participant records
    $ip = $db->prepare("INSERT INTO defense_participants (defense_id,user_id,role) VALUES (?,?,?)");
    $ip->execute([$defenseId, $project['student_id'], 'student']);
    if ($supervisorId) $ip->execute([$defenseId, $supervisorId, 'supervisor']);
    foreach ($internalIds as $iid) {
        try { $ip->execute([$defenseId, $iid, 'internal_examiner']); } catch (PDOException $e) {}
    }
    if ($externalId) {
        try { $ip->execute([$defenseId, $externalId, 'external_examiner']); } catch (PDOException $e) {}
    }

    audit($db, $defenseId, 'defense.scheduled', "Defense $ref scheduled at " . ($venue ?: $mode));

    // Notify all participants.
    $partIds = array_column(qrows($db, "SELECT user_id FROM defense_participants WHERE defense_id=?", [$defenseId]), 'user_id');
    $when = date('d M Y, H:i', strtotime($scheduledAt));
    notify($db, $partIds, 'defense.scheduled',
        "Defense scheduled — $ref",
        "Dear {name},\n\nA project defense ($ref) has been scheduled for $when" .
        ($venue ? " at $venue" : '') . " (" . DEFENSE_MODES[$mode] . ").\n\nPlease ensure your availability.\n\n— EARMS Defense & Evaluation",
        $defenseId);

    return ok(act_get_defense($db, $defenseId)['data'], 201);
}

function act_get_defense(PDO $db, int $id): array
{
    $d = qrow($db, "SELECT * FROM defenses WHERE id=?", [$id]);
    if (!$d) return err('Defense not found', 404);
    $d['project']      = qrow($db, "SELECT id,title,status,department FROM projects WHERE id=?", [$d['project_id']]);
    $d['student']      = qrow($db, "SELECT id,name,email FROM users WHERE id=?", [$d['student_id']]);
    $d['participants'] = qrows($db, "SELECT p.*, u.name, u.email FROM defense_participants p JOIN users u ON u.id=p.user_id WHERE p.defense_id=? ORDER BY FIELD(p.role,'student','supervisor','internal_examiner','external_examiner')", [$id]);
    $d['materials']    = qrows($db, "SELECT m.*, f.name, f.file_type, f.size_bytes, f.storage_path FROM defense_materials m JOIN files f ON f.id=m.file_id WHERE m.defense_id=? ORDER BY m.created_at DESC", [$id]);
    $d['recordings']   = qrows($db, "SELECT * FROM defense_recordings WHERE defense_id=? ORDER BY created_at DESC", [$id]);
    $d['scores']       = qrows($db, "SELECT s.*, u.name AS evaluator_name FROM defense_scores s JOIN users u ON u.id=s.evaluator_id WHERE s.defense_id=? ORDER BY FIELD(s.evaluator_role,'supervisor','internal_examiner','external_examiner')", [$id]);
    return ok($d);
}

function act_reschedule(PDO $db, int $id, array $in): array
{
    $d = qrow($db, "SELECT * FROM defenses WHERE id=?", [$id]);
    if (!$d) return err('Defense not found', 404);
    if (in_array($d['status'], ['completed', 'cancelled'])) return err('Cannot reschedule a ' . $d['status'] . ' defense', 409);

    $scheduledAt = trim($in['scheduled_at'] ?? $d['scheduled_at']);
    $venue       = trim($in['venue'] ?? $d['venue']);

    // Re-check conflicts for this defense's examiners.
    $examiners = qrows($db, "SELECT user_id FROM defense_participants WHERE defense_id=? AND role!='student'", [$id]);
    foreach ($examiners as $row) {
        $clash = qval($db,
            "SELECT COUNT(*) FROM defense_participants p JOIN defenses d ON d.id=p.defense_id
             WHERE p.user_id=? AND d.id!=? AND d.status IN ('scheduled','ongoing')
               AND ABS(TIMESTAMPDIFF(MINUTE, d.scheduled_at, ?)) < 120", [$row['user_id'], $id, $scheduledAt]);
        if ($clash) {
            $name = qval($db, "SELECT name FROM users WHERE id=?", [$row['user_id']]);
            return err("Conflict on reschedule: $name is booked within 2 hours of the new slot", 409);
        }
    }
    $db->prepare("UPDATE defenses SET scheduled_at=?, venue=? WHERE id=?")->execute([$scheduledAt, $venue, $id]);
    audit($db, $id, 'defense.rescheduled', "Rescheduled to $scheduledAt" . ($venue ? " @ $venue" : ''));
    $partIds = array_column(qrows($db, "SELECT user_id FROM defense_participants WHERE defense_id=?", [$id]), 'user_id');
    notify($db, $partIds, 'defense.rescheduled',
        "Defense rescheduled — {$d['reference']}",
        "Dear {name},\n\nThe defense {$d['reference']} has been rescheduled to " .
        date('d M Y, H:i', strtotime($scheduledAt)) . ($venue ? " at $venue" : '') .
        ".\n\nPlease update your calendar.\n\n— EARMS Defense & Evaluation",
        $id);
    return ok(act_get_defense($db, $id)['data']);
}

function act_cancel(PDO $db, int $id): array
{
    $d = qrow($db, "SELECT * FROM defenses WHERE id=?", [$id]);
    if (!$d) return err('Defense not found', 404);
    if ($d['status'] === 'completed') return err('Cannot cancel a completed defense', 409);
    $db->prepare("UPDATE defenses SET status='cancelled' WHERE id=?")->execute([$id]);
    audit($db, $id, 'defense.cancelled', 'Defense cancelled (soft delete)');
    $partIds = array_column(qrows($db, "SELECT user_id FROM defense_participants WHERE defense_id=?", [$id]), 'user_id');
    notify($db, $partIds, 'defense.cancelled',
        "Defense cancelled — {$d['reference']}",
        "Dear {name},\n\nThe defense {$d['reference']} scheduled for " .
        date('d M Y, H:i', strtotime($d['scheduled_at'])) . " has been cancelled.\n\n— EARMS Defense & Evaluation",
        $id);
    return ok(['id' => $id, 'status' => 'cancelled']);
}

/* ───────────────────────── PARTICIPANTS ───────────────────────── */

function act_add_participant(PDO $db, int $id, array $in): array
{
    $d = qrow($db, "SELECT id FROM defenses WHERE id=?", [$id]);
    if (!$d) return err('Defense not found', 404);
    $userId = (int)($in['user_id'] ?? 0);
    $role   = $in['role'] ?? '';
    if (!$userId || !array_key_exists($role, PARTICIPANT_ROLES)) return err('user_id and a valid role are required', 422);
    if (!qrow($db, "SELECT id FROM users WHERE id=?", [$userId])) return err('User not found', 404);
    if (qval($db, "SELECT COUNT(*) FROM defense_participants WHERE defense_id=? AND user_id=?", [$id, $userId]))
        return err('Participant already added to this defense', 409);
    if ($role === 'external_examiner' &&
        qval($db, "SELECT COUNT(*) FROM defense_participants WHERE defense_id=? AND role='external_examiner'", [$id]))
        return err('Only one external examiner is allowed per session', 409);

    $db->prepare("INSERT INTO defense_participants (defense_id,user_id,role) VALUES (?,?,?)")->execute([$id, $userId, $role]);
    audit($db, $id, 'participant.added', "Added " . qval($db, "SELECT name FROM users WHERE id=?", [$userId]) . " as $role");
    return ok(['defense_id' => $id, 'user_id' => $userId, 'role' => $role], 201);
}

function act_remove_participant(PDO $db, int $id, int $userId): array
{
    $p = qrow($db, "SELECT * FROM defense_participants WHERE defense_id=? AND user_id=?", [$id, $userId]);
    if (!$p) return err('Participant not found', 404);
    if ($p['role'] === 'student') return err('Cannot remove the student after scheduling', 409);
    if (in_array($p['role'], ['supervisor', 'internal_examiner', 'external_examiner'])
        && qval($db, "SELECT COUNT(*) FROM defense_scores WHERE defense_id=?", [$id]))
        return err('Cannot remove examiners once scoring has begun', 409);
    $db->prepare("DELETE FROM defense_participants WHERE defense_id=? AND user_id=?")->execute([$id, $userId]);
    audit($db, $id, 'participant.removed', "Removed user #$userId");
    return ok(['removed' => true]);
}

function act_set_attendance(PDO $db, int $id, array $in): array
{
    $userId = (int)($in['user_id'] ?? 0);
    $status = $in['attendance'] ?? '';
    if (!$userId || !in_array($status, ['present', 'absent', 'pending'])) return err('user_id and valid attendance required', 422);
    $aff = $db->prepare("UPDATE defense_participants SET attendance=? WHERE defense_id=? AND user_id=?");
    $aff->execute([$status, $id, $userId]);
    if (!$aff->rowCount()) return err('Participant not found', 404);
    audit($db, $id, 'attendance.recorded', "User #$userId marked $status");
    return ok(['user_id' => $userId, 'attendance' => $status]);
}

/* ───────────────────────── SESSIONS & RECORDINGS ───────────────────────── */

function act_start_session(PDO $db, int $id): array
{
    $d = qrow($db, "SELECT * FROM defenses WHERE id=?", [$id]);
    if (!$d) return err('Defense not found', 404);
    if ($d['status'] === 'cancelled') return err('Defense is cancelled', 409);
    if ($d['status'] === 'completed') return err('Defense already completed', 409);

    // Spec: validate the current time aligns with the scheduled session or
    // falls within an acceptable window (opt-in via ENFORCE_START_WINDOW).
    if (ENFORCE_START_WINDOW && !empty($d['scheduled_at'])) {
        $earliest = strtotime($d['scheduled_at']) - SESSION_START_GRACE_MIN * 60;
        if (time() < $earliest) {
            return err('Too early to start: the session opens ' . SESSION_START_GRACE_MIN .
                       ' minutes before ' . date('d M Y H:i', strtotime($d['scheduled_at'])), 409);
        }
    }

    $db->prepare("UPDATE defenses SET status='ongoing' WHERE id=?")->execute([$id]);
    audit($db, $id, 'session.started', 'Session started' . ($d['mode'] === 'virtual' ? ' (virtual room initialised)' : ''));
    return ok(['id' => $id, 'status' => 'ongoing', 'meeting_url' => $d['meeting_url']]);
}

function act_end_session(PDO $db, int $id): array
{
    $d = qrow($db, "SELECT * FROM defenses WHERE id=?", [$id]);
    if (!$d) return err('Defense not found', 404);
    // Stop any active recording, then mark completed.
    $db->prepare("UPDATE defense_recordings SET status='stopped', stopped_at=NOW() WHERE defense_id=? AND status='recording'")->execute([$id]);
    $db->prepare("UPDATE defenses SET status='completed' WHERE id=?")->execute([$id]);
    audit($db, $id, 'session.ended', 'Session ended; defense marked completed');
    return ok(['id' => $id, 'status' => 'completed']);
}

function act_recording_start(PDO $db, int $id): array
{
    if (!qrow($db, "SELECT id FROM defenses WHERE id=?", [$id])) return err('Defense not found', 404);
    if (qval($db, "SELECT COUNT(*) FROM defense_recordings WHERE defense_id=? AND status='recording'", [$id]))
        return err('A recording is already in progress', 409);
    $db->prepare("INSERT INTO defense_recordings (defense_id,status,started_at) VALUES (?, 'recording', NOW())")->execute([$id]);
    $recId = (int)$db->lastInsertId();
    audit($db, $id, 'recording.started', "Recording #$recId started");
    return ok(['recording_id' => $recId, 'status' => 'recording'], 201);
}

function act_recording_stop(PDO $db, int $id): array
{
    $rec = qrow($db, "SELECT * FROM defense_recordings WHERE defense_id=? AND status='recording' ORDER BY id DESC LIMIT 1", [$id]);
    if (!$rec) return err('No active recording for this defense', 404);
    $dur = max(1, time() - strtotime($rec['started_at']));
    $db->prepare("UPDATE defense_recordings SET status='stopped', stopped_at=NOW(), duration_sec=? WHERE id=?")->execute([$dur, $rec['id']]);
    audit($db, $id, 'recording.stopped', "Recording #{$rec['id']} stopped ({$dur}s)");
    return ok(['recording_id' => (int)$rec['id'], 'status' => 'stopped', 'duration_sec' => $dur]);
}

function act_recording_save(PDO $db, int $id, array $in): array
{
    $rec = qrow($db, "SELECT * FROM defense_recordings WHERE defense_id=? AND status='stopped' ORDER BY id DESC LIMIT 1", [$id]);
    if (!$rec) return err('No stopped recording to save', 404);
    $size = (int)($in['size_bytes'] ?? 0);
    $uid  = fileUid();
    $path = 'storage_files/' . $uid;
    // Persist as a storage file too (links recording → Storage microservice).
    $db->prepare("INSERT INTO files (file_uid,defense_id,name,file_type,mime,size_bytes,access_level,storage_path,uploaded_by)
                  VALUES (?,?,?,?,?,?,?,?,?)")
       ->execute([$uid, $id, 'Defense_Recording.mp4', 'recording', 'video/mp4', $size, 'department_only', $path, 1]);
    $fileId = (int)$db->lastInsertId();
    $db->prepare("UPDATE defense_recordings SET status='saved', file_id=?, size_bytes=?, storage_path=? WHERE id=?")
       ->execute([$fileId, $size, $path, $rec['id']]);
    audit($db, $id, 'recording.saved', "Recording #{$rec['id']} persisted to storage as $uid");
    return ok(['recording_id' => (int)$rec['id'], 'file_id' => $fileId, 'file_uid' => $uid, 'status' => 'saved']);
}

/* ───────────────────────── MATERIALS ───────────────────────── */

function act_upload_material(PDO $db, int $id, array $in): array
{
    $d = qrow($db, "SELECT * FROM defenses WHERE id=?", [$id]);
    if (!$d) return err('Defense not found', 404);
    $name = trim($in['name'] ?? '');
    if ($name === '') return err('File name required', 422);
    $type = $in['file_type'] ?? 'slides';
    $size = (int)($in['size_bytes'] ?? 0);
    $uid  = fileUid();
    $ver  = 1 + (int)qval($db, "SELECT COUNT(*) FROM defense_materials WHERE defense_id=?", [$id]);
    $db->prepare("INSERT INTO files (file_uid,project_id,defense_id,name,file_type,mime,size_bytes,access_level,version,storage_path,uploaded_by)
                  VALUES (?,?,?,?,?,?,?, 'department_only', ?, ?, ?)")
       ->execute([$uid, $d['project_id'], $id, $name, $type, $in['mime'] ?? '', $size, $ver, 'storage_files/' . $uid, $d['student_id']]);
    $fileId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO defense_materials (defense_id,file_id,version,uploaded_by) VALUES (?,?,?,?)")
       ->execute([$id, $fileId, $ver, $d['student_id']]);
    audit($db, $id, 'material.uploaded', "Material '$name' v$ver uploaded");
    return ok(['file_id' => $fileId, 'file_uid' => $uid, 'version' => $ver], 201);
}

function act_delete_material(PDO $db, int $materialFileId): array
{
    $m = qrow($db, "SELECT m.*, d.status FROM defense_materials m JOIN defenses d ON d.id=m.defense_id WHERE m.file_id=?", [$materialFileId]);
    if (!$m) return err('Material not found', 404);
    if (in_array($m['status'], ['ongoing', 'completed'])) return err('Materials can only be deleted before the session begins', 409);
    $db->prepare("DELETE FROM defense_materials WHERE file_id=?")->execute([$materialFileId]);
    $db->prepare("UPDATE files SET is_deleted=1 WHERE id=?")->execute([$materialFileId]);
    audit($db, (int)$m['defense_id'], 'material.deleted', "Material file #$materialFileId deleted");
    return ok(['deleted' => true]);
}

/* Persist a REAL uploaded material file (bytes already stored via store_uploaded_file). */
function act_store_material_file(PDO $db, int $id, array $stored, string $type = 'slides'): array
{
    $d = qrow($db, "SELECT * FROM defenses WHERE id=?", [$id]);
    if (!$d) return err('Defense not found', 404);
    $ver = 1 + (int)qval($db, "SELECT COUNT(*) FROM defense_materials WHERE defense_id=?", [$id]);
    $mime = mime_from_ext($stored['ext']);
    $db->prepare("INSERT INTO files (file_uid,project_id,defense_id,name,original_name,file_type,mime,size_bytes,access_level,version,storage_path,checksum,is_stored,uploaded_by)
                  VALUES (?,?,?,?,?,?,?,?, 'department_only', ?, ?, ?, 1, ?)")
       ->execute([$stored['uid'], $d['project_id'], $id, $stored['client_name'], $stored['client_name'],
                  $type, $mime, $stored['size'], $ver, $stored['path'], $stored['checksum'], $d['student_id']]);
    $fileId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO defense_materials (defense_id,file_id,version,uploaded_by) VALUES (?,?,?,?)")
       ->execute([$id, $fileId, $ver, $d['student_id']]);
    audit($db, $id, 'material.uploaded', "Material '{$stored['client_name']}' v$ver uploaded ({$stored['size']} bytes)");
    return ok(['file_id' => $fileId, 'file_uid' => $stored['uid'], 'version' => $ver], 201);
}

/* Persist a REAL uploaded storage file. */
function act_store_file(PDO $db, array $stored, array $meta): array
{
    $access = $meta['access_level'] ?? 'department_only';
    if (!array_key_exists($access, ACCESS_LEVELS)) return err('Invalid access_level', 422);
    $projectId = (int)($meta['project_id'] ?? 0) ?: null;
    $mime = mime_from_ext($stored['ext']);
    $type = $meta['file_type'] ?? 'document';
    $db->prepare("INSERT INTO files (file_uid,project_id,name,original_name,file_type,mime,size_bytes,access_level,version,storage_path,checksum,is_stored,uploaded_by)
                  VALUES (?,?,?,?,?,?,?,?, 1, ?, ?, 1, ?)")
       ->execute([$stored['uid'], $projectId, $stored['client_name'], $stored['client_name'], $type, $mime,
                  $stored['size'], $access, $stored['path'], $stored['checksum'], (int)($meta['uploaded_by'] ?? 0) ?: null]);
    $fileId = (int)$db->lastInsertId();
    audit($db, null, 'file.uploaded', "File '{$stored['client_name']}' ({$stored['uid']}) uploaded to storage");
    return ok(['file_id' => $fileId, 'file_uid' => $stored['uid'], 'access_level' => $access], 201);
}

function mime_from_ext(string $ext): string
{
    static $m = [
        'pdf'=>'application/pdf','doc'=>'application/msword',
        'docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'ppt'=>'application/vnd.ms-powerpoint',
        'pptx'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'xls'=>'application/vnd.ms-excel',
        'xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'csv'=>'text/csv','txt'=>'text/plain','md'=>'text/markdown','zip'=>'application/zip',
        'png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif',
        'mp4'=>'video/mp4','webm'=>'video/webm','mov'=>'video/quicktime','ipynb'=>'application/x-ipynb+json',
    ];
    return $m[strtolower($ext)] ?? 'application/octet-stream';
}

/* ───────────────────────── SCORING ───────────────────────── */

function act_submit_score(PDO $db, int $id, array $in): array
{
    $d = qrow($db, "SELECT * FROM defenses WHERE id=?", [$id]);
    if (!$d) return err('Defense not found', 404);
    if ($d['finalized']) return err('Defense is finalized; scores are locked', 409);

    $evaluatorId = (int)($in['evaluator_id'] ?? 0);
    $role        = $in['evaluator_role'] ?? '';
    if (!$evaluatorId || !in_array($role, ['supervisor', 'internal_examiner', 'external_examiner']))
        return err('evaluator_id and a valid evaluator_role are required', 422);

    $cq = (float)($in['content_quality']  ?? 0);
    $pr = (float)($in['presentation']      ?? 0);
    $og = (float)($in['originality']       ?? 0);
    $dr = (float)($in['defense_response']  ?? 0);
    foreach ([['content_quality',$cq],['presentation',$pr],['originality',$og],['defense_response',$dr]] as $c) {
        $max = RUBRIC[$c[0]]['max'];
        if ($c[1] < 0 || $c[1] > $max) return err("{$c[0]} must be between 0 and $max", 422);
    }
    $total = $cq + $pr + $og + $dr;

    $existing = qrow($db, "SELECT * FROM defense_scores WHERE defense_id=? AND evaluator_id=?", [$id, $evaluatorId]);
    if ($existing && $existing['locked']) return err('This evaluator\'s score is already locked', 409);

    if ($existing) {
        $db->prepare("UPDATE defense_scores SET evaluator_role=?,content_quality=?,presentation=?,originality=?,defense_response=?,total=?,comments=?,locked=1 WHERE id=?")
           ->execute([$role, $cq, $pr, $og, $dr, $total, $in['comments'] ?? '', $existing['id']]);
    } else {
        $db->prepare("INSERT INTO defense_scores (defense_id,evaluator_id,evaluator_role,content_quality,presentation,originality,defense_response,total,comments,locked)
                      VALUES (?,?,?,?,?,?,?,?,?,1)")
           ->execute([$id, $evaluatorId, $role, $cq, $pr, $og, $dr, $total, $in['comments'] ?? '']);
    }
    audit($db, $id, 'score.submitted', "Score $total/100 submitted by " . qval($db, "SELECT name FROM users WHERE id=?", [$evaluatorId]), qval($db, "SELECT name FROM users WHERE id=?", [$evaluatorId]) ?: null);
    return ok(['defense_id' => $id, 'evaluator_id' => $evaluatorId, 'total' => $total], 201);
}

function act_update_score(PDO $db, int $id, int $scoreId, array $in): array
{
    $s = qrow($db, "SELECT s.*, d.finalized FROM defense_scores s JOIN defenses d ON d.id=s.defense_id WHERE s.id=? AND s.defense_id=?", [$scoreId, $id]);
    if (!$s) return err('Score not found', 404);
    if ($s['finalized']) return err('Cannot modify score after finalization', 409);

    $cq = (float)($in['content_quality']  ?? $s['content_quality']);
    $pr = (float)($in['presentation']      ?? $s['presentation']);
    $og = (float)($in['originality']       ?? $s['originality']);
    $dr = (float)($in['defense_response']  ?? $s['defense_response']);
    $total = $cq + $pr + $og + $dr;

    $db->prepare("UPDATE defense_scores SET content_quality=?,presentation=?,originality=?,defense_response=?,total=?,comments=? WHERE id=?")
       ->execute([$cq, $pr, $og, $dr, $total, $in['comments'] ?? $s['comments'], $scoreId]);
    audit($db, $id, 'score.updated', "Score #$scoreId changed: {$s['total']} → $total (by " . (function_exists('actor_name') ? actor_name() : 'system') . ")");
    return ok(['score_id' => $scoreId, 'old_total' => (float)$s['total'], 'new_total' => $total]);
}

/* ───────────────────────── AGGREGATION & RESULTS ───────────────────────── */

function act_aggregate(PDO $db, int $id): array
{
    if (!qrow($db, "SELECT id FROM defenses WHERE id=?", [$id])) return err('Defense not found', 404);
    $agg = computeAggregate($db, $id);
    if ($agg === null) return err('No scores available to aggregate', 409);
    $db->prepare("UPDATE defenses SET aggregate_score=? WHERE id=?")->execute([$agg, $id]);
    audit($db, $id, 'defense.aggregated', "Weighted aggregate computed ($agg)");
    return ok(['defense_id' => $id, 'aggregate_score' => $agg, 'weights' => SCORE_WEIGHTS]);
}

function act_finalize(PDO $db, int $id): array
{
    $d = qrow($db, "SELECT * FROM defenses WHERE id=?", [$id]);
    if (!$d) return err('Defense not found', 404);
    if ($d['finalized']) return err('Defense already finalized', 409);

    // Policy: require a quorum of submitted scores before finalizing.
    $scoreCount = (int) qval($db, "SELECT COUNT(*) FROM defense_scores WHERE defense_id=?", [$id]);
    if ($scoreCount < SCORE_QUORUM)
        return err('Cannot finalize: ' . SCORE_QUORUM . ' evaluator scores required, only ' . $scoreCount . ' submitted', 409);

    // Policy: the student must have been marked present.
    if (REQUIRE_STUDENT_PRESENT) {
        $att = qval($db, "SELECT attendance FROM defense_participants WHERE defense_id=? AND role='student' LIMIT 1", [$id]);
        if ($att !== 'present')
            return err('Cannot finalize: the student was not marked present at the defense', 409);
    }

    $agg = $d['aggregate_score'] !== null ? (float)$d['aggregate_score'] : computeAggregate($db, $id);
    if ($agg === null) return err('Cannot finalize: no scores to aggregate', 409);
    [$grade, $passFail] = gradeFromScore($agg);
    $db->prepare("UPDATE defenses SET aggregate_score=?, final_grade=?, result_status=?, finalized=1, status='completed' WHERE id=?")
       ->execute([$agg, $grade, $passFail, $id]);
    $db->prepare("UPDATE defense_scores SET locked=1 WHERE defense_id=?")->execute([$id]);
    audit($db, $id, 'defense.finalized', "Finalized: $agg → grade $grade ($passFail); scores locked");
    return ok(['defense_id' => $id, 'aggregate_score' => $agg, 'grade' => $grade, 'result_status' => $passFail]);
}

function act_result(PDO $db, int $id): array
{
    $d = qrow($db, "SELECT * FROM defenses WHERE id=?", [$id]);
    if (!$d) return err('Defense not found', 404);
    if (!$d['finalized']) return err('Result not finalized yet', 409);
    return ok([
        'defense_id'      => $id,
        'reference'       => $d['reference'],
        'aggregate_score' => (float)$d['aggregate_score'],
        'grade'           => $d['final_grade'],
        'result_status'   => $d['result_status'],
        'published'       => (bool)$d['published'],
        'comments'        => qrows($db, "SELECT u.name AS evaluator, s.evaluator_role, s.comments FROM defense_scores s JOIN users u ON u.id=s.evaluator_id WHERE s.defense_id=?", [$id]),
    ]);
}

function act_publish(PDO $db, int $id): array
{
    $d = qrow($db, "SELECT * FROM defenses WHERE id=?", [$id]);
    if (!$d) return err('Defense not found', 404);
    if (!$d['finalized']) return err('Finalize the result before publishing', 409);
    $db->prepare("UPDATE defenses SET published=1 WHERE id=?")->execute([$id]);
    audit($db, $id, 'defense.published', 'Result published to stakeholders');
    // Notify the student and the full panel (supervisor + examiners).
    $recipients = array_column(qrows($db, "SELECT user_id FROM defense_participants WHERE defense_id=?", [$id]), 'user_id');
    if (!in_array((int)$d['student_id'], array_map('intval', $recipients), true)) $recipients[] = (int)$d['student_id'];
    notify($db, $recipients, 'defense.published',
        "Defense result published — {$d['reference']}",
        "Dear {name},\n\nThe result for defense {$d['reference']} has been published.\n" .
        "Grade: {$d['final_grade']}  |  Aggregate: {$d['aggregate_score']}/100  |  Outcome: " . strtoupper((string)$d['result_status']) .
        "\n\nLog in to EARMS for the full result sheet.\n\n— EARMS Defense & Evaluation",
        $id);
    return ok(['defense_id' => $id, 'published' => true]);
}

function act_send_to_exam_officer(PDO $db, int $id): array
{
    $d = qrow($db, "SELECT * FROM defenses WHERE id=?", [$id]);
    if (!$d) return err('Defense not found', 404);
    if (!$d['finalized']) return err('Result must be finalized first', 409);
    $db->prepare("UPDATE defenses SET sent_to_exam_officer=1 WHERE id=?")->execute([$id]);
    audit($db, $id, 'result.sent_exam_officer', 'Final grade forwarded to examination office');
    $officers = array_column(qrows($db, "SELECT id FROM users WHERE role='exam_officer' AND is_active=1"), 'id');
    notify($db, $officers, 'result.sent_exam_officer',
        "Result forwarded for processing — {$d['reference']}",
        "Dear {name},\n\nA finalized defense result ({$d['reference']}) has been forwarded to the examination office.\n" .
        "Grade: {$d['final_grade']}  |  Aggregate: {$d['aggregate_score']}/100  |  Outcome: " . strtoupper((string)$d['result_status']) .
        "\n\n— EARMS Defense & Evaluation",
        $id);
    return ok(['defense_id' => $id, 'sent_to_exam_officer' => true]);
}

function act_audit_log(PDO $db, int $id): array
{
    if (!qrow($db, "SELECT id FROM defenses WHERE id=?", [$id])) return err('Defense not found', 404);
    return ok(qrows($db, "SELECT id,action,detail,actor,created_at FROM audit_logs WHERE defense_id=? ORDER BY id DESC", [$id]));
}

/* ───────────────────────── STORAGE MICROSERVICE ───────────────────────── */

function act_file_upload(PDO $db, array $in): array
{
    $name = trim($in['name'] ?? '');
    if ($name === '') return err('File name required', 422);
    $access = $in['access_level'] ?? 'department_only';
    if (!array_key_exists($access, ACCESS_LEVELS)) return err('Invalid access_level', 422);
    $projectId = (int)($in['project_id'] ?? 0) ?: null;
    $uid  = fileUid();
    $db->prepare("INSERT INTO files (file_uid,project_id,name,file_type,mime,size_bytes,access_level,version,storage_path,uploaded_by)
                  VALUES (?,?,?,?,?,?,?, ?, ?, ?)")
       ->execute([$uid, $projectId, $name, $in['file_type'] ?? 'document', $in['mime'] ?? '',
                  (int)($in['size_bytes'] ?? 0), $access, (int)($in['version'] ?? 1),
                  'storage_files/' . $uid, (int)($in['uploaded_by'] ?? 0) ?: null]);
    $fileId = (int)$db->lastInsertId();
    audit($db, null, 'file.uploaded', "File '$name' ($uid) uploaded");
    return ok(['file_id' => $fileId, 'file_uid' => $uid, 'access_level' => $access], 201);
}

function act_file_get(PDO $db, int $id): array
{
    $f = qrow($db, "SELECT * FROM files WHERE id=? AND is_deleted=0", [$id]);
    if (!$f) return err('File not found', 404);
    $f['download_url'] = BASE_URL . '/api/index.php/files/' . $f['id'] . '/download';
    return ok($f);
}

function act_file_delete(PDO $db, int $id): array
{
    $f = qrow($db, "SELECT * FROM files WHERE id=? AND is_deleted=0", [$id]);
    if (!$f) return err('File not found', 404);
    $db->prepare("UPDATE files SET is_deleted=1 WHERE id=?")->execute([$id]);
    audit($db, null, 'file.deleted', "File #{$id} ({$f['file_uid']}) deleted");
    return ok(['deleted' => true]);
}

function act_project_files(PDO $db, int $projectId): array
{
    if (!qrow($db, "SELECT id FROM projects WHERE id=?", [$projectId])) return err('Project not found', 404);
    return ok(qrows($db, "SELECT id,file_uid,name,file_type,size_bytes,access_level,version,created_at FROM files WHERE project_id=? AND is_deleted=0 ORDER BY created_at DESC", [$projectId]));
}
