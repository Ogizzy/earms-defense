<?php
// tests/fixtures.php — OPTIONAL demo/test fixtures (test-only, NOT system data).
// The single system schema is earms_schema.sql (no seed). These rows are
// applied only by the test bootstrap (and available for local demos).
// Demo login password (standalone mode) for all users: Passw0rd!

function load_test_fixtures(PDO $pdo): void {
    $sql = <<<'SQL'
-- ============================================================
--  EARMS — OPTIONAL demo/test fixtures (NOT loaded in production)
--  Used by the automated test suite, and available for local demos.
--  Production databases start empty; real users come from the IAM
--  Service and projects from the Project Workflow Service.
--  Demo login password (standalone mode) for all users: Passw0rd!
-- ============================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
INSERT INTO users (id, name, email, role, department) VALUES
 (1,'Dr. Amaka Obi',    'a.obi@uni.edu',    'coordinator',       'Computer Science'),
 (2,'Prof. Bello Sani', 'b.sani@uni.edu',   'supervisor',        'Computer Science'),
 (3,'Dr. Chioma Eze',   'c.eze@uni.edu',    'supervisor',        'Computer Science'),
 (4,'Dr. Tunde Bakare', 't.bakare@uni.edu', 'internal_examiner', 'Computer Science'),
 (5,'Dr. Halima Yusuf', 'h.yusuf@uni.edu',  'internal_examiner', 'Computer Science'),
 (6,'Prof. K. Mensah',  'k.mensah@ext.edu', 'external_examiner', 'External'),
 (7,'Mr. James Phiri',  'j.phiri@uni.edu',  'exam_officer',      'Examinations'),
 (8,'Grace Mwale',      'g.mwale@stu.edu',  'student',           'Computer Science'),
 (9,'Daniel Okafor',    'd.okafor@stu.edu', 'student',           'Computer Science'),
 (10,'Fatima Ibrahim',  'f.ibrahim@stu.edu','student',           'Computer Science'),
 (11,'Samuel Tembo',    's.tembo@stu.edu',  'student',           'Computer Science'),
 (12,'Linda Banda',     'l.banda@stu.edu',  'student',           'Computer Science');

INSERT INTO projects (id, title, student_id, supervisor_id, department, status) VALUES
 (1,'AI-Driven Plagiarism Detection for Academic Repositories', 8, 2,'Computer Science','completed'),
 (2,'Blockchain-Based Student Records Verification',             9, 3,'Computer Science','completed'),
 (3,'Microservice Architecture for Campus Payment Systems',     10, 2,'Computer Science','completed'),
 (4,'Real-Time Multilingual Translation in E-Learning',         11, 3,'Computer Science','in_progress'),
 (5,'Predictive Analytics for Student Dropout Prevention',      12, 2,'Computer Science','completed');

-- Defenses: 1,2 upcoming; 3,4 completed (3 fully published, 4 awaiting finalize)
INSERT INTO defenses (id, reference, project_id, student_id, supervisor_id, external_examiner_id, scheduled_at, venue, mode, status, meeting_url, aggregate_score, final_grade, result_status, published, finalized, sent_to_exam_officer) VALUES
 (1,'DEF-2026-A1B2C3', 1, 8, 2, 6, DATE_ADD(NOW(), INTERVAL 2 DAY),  'Senate Hall A',     'physical','scheduled', NULL, NULL, NULL, NULL, 0,0,0),
 (2,'DEF-2026-D4E5F6', 2, 9, 3, 6, DATE_ADD(NOW(), INTERVAL 4 DAY),  'Virtual',           'virtual', 'scheduled', 'https://meet.earms.edu/d4e5f6abcd', NULL, NULL, NULL, 0,0,0),
 (3,'DEF-2026-G7H8I9', 3,10, 2, 6, DATE_SUB(NOW(), INTERVAL 1 DAY),  'CS Defense Room 2', 'physical','completed', NULL, NULL, NULL, NULL, 0,0,0),
 (4,'DEF-2026-J1K2L3', 5,12, 2, 6, DATE_SUB(NOW(), INTERVAL 3 DAY),  'Virtual',           'virtual', 'completed', 'https://meet.earms.edu/j1k2l3mnop', NULL, NULL, NULL, 0,0,0);

-- Participants (student, supervisor, 2 internal examiners (4,5), 1 external (6))
INSERT INTO defense_participants (defense_id, user_id, role, attendance) VALUES
 (1, 8,'student','pending'),(1, 2,'supervisor','pending'),(1,4,'internal_examiner','pending'),(1,5,'internal_examiner','pending'),(1,6,'external_examiner','pending'),
 (2, 9,'student','pending'),(2, 3,'supervisor','pending'),(2,4,'internal_examiner','pending'),(2,5,'internal_examiner','pending'),(2,6,'external_examiner','pending'),
 (3,10,'student','present'),(3, 2,'supervisor','present'),(3,4,'internal_examiner','present'),(3,5,'internal_examiner','present'),(3,6,'external_examiner','present'),
 (4,12,'student','present'),(4, 2,'supervisor','present'),(4,4,'internal_examiner','present'),(4,5,'internal_examiner','present'),(4,6,'external_examiner','present');

-- Scores for completed defenses 3 & 4 (locked)
INSERT INTO defense_scores (defense_id, evaluator_id, evaluator_role, content_quality, presentation, originality, defense_response, total, comments, locked) VALUES
 (3, 2,'supervisor',        26,22,21,17, 86,'Strong technical depth, well-supervised work.',1),
 (3, 4,'internal_examiner', 25,20,22,16, 83,'Good methodology, presentation slightly rushed.',1),
 (3, 5,'internal_examiner', 24,21,20,17, 82,'Solid contribution, defended questions well.',1),
 (3, 6,'external_examiner', 27,23,23,18, 91,'Excellent originality and external relevance.',1),
 (4, 2,'supervisor',        22,19,18,15, 74,'Adequate work, some gaps in evaluation chapter.',1),
 (4, 4,'internal_examiner', 21,18,17,14, 70,'Acceptable, needs stronger validation.',1),
 (4, 5,'internal_examiner', 20,17,16,13, 66,'Borderline on originality.',1),
 (4, 6,'external_examiner', 23,18,19,15, 75,'Reasonable, defended adequately.',1);

-- Defense 3 finalized & published. Weighted aggregate:
--   supervisor 86*.30 + internal avg 82.5*.40 + external 91*.30 = 25.8 + 33.0 + 27.3 = 86.10 → A / pass
UPDATE defenses SET aggregate_score=86.10, final_grade='A', result_status='pass',
       finalized=1, published=1, sent_to_exam_officer=1 WHERE id=3;

-- Recordings for completed defenses
INSERT INTO defense_recordings (defense_id, status, duration_sec, size_bytes, storage_path, started_at, stopped_at) VALUES
 (3,'saved',3920,248500000,'storage_files/rec_def3.mp4', DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(DATE_SUB(NOW(), INTERVAL 1 DAY), INTERVAL 3920 SECOND)),
 (4,'saved',3510,221300000,'storage_files/rec_def4.mp4', DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_ADD(DATE_SUB(NOW(), INTERVAL 3 DAY), INTERVAL 3510 SECOND));

-- Storage files
INSERT INTO files (file_uid, project_id, defense_id, name, file_type, mime, size_bytes, access_level, version, storage_path, uploaded_by) VALUES
 ('FILE-1A2B3C4D5E', 1, NULL, 'Chapter_1_Introduction.pdf', 'document','application/pdf',          842000,    'department_only',  3, 'storage_files/FILE-1A2B3C4D5E', 8),
 ('FILE-2B3C4D5E6F', 1, 3,    'Defense_Slides_v2.pptx',     'slides',  'application/vnd.openxmlformats-officedocument.presentationml.presentation', 5120000, 'supervisor_only', 2, 'storage_files/FILE-2B3C4D5E6F', 8),
 ('FILE-3C4D5E6F7G', 1, NULL, 'Dataset_samples.csv',        'dataset', 'text/csv',                 1240000,   'student_only',     1, 'storage_files/FILE-3C4D5E6F7G', 8),
 ('FILE-4D5E6F7G8H', 2, NULL, 'Full_Project_Report.pdf',    'document','application/pdf',          3650000,   'institution_wide', 1, 'storage_files/FILE-4D5E6F7G8H', 9),
 ('FILE-5E6F7G8H9I', 3, NULL, 'Final_Submission.pdf',       'document','application/pdf',          4010000,   'institution_wide', 2, 'storage_files/FILE-5E6F7G8H9I', 10),
 ('FILE-6F7G8H9I0J', 3, 3,    'Defense_Recording.mp4',      'recording','video/mp4',               248500000, 'department_only',  1, 'storage_files/rec_def3.mp4',    1),
 ('FILE-7G8H9I0J1K', 5, NULL, 'Analytics_Notebook.ipynb',   'dataset', 'application/x-ipynb+json', 680000,    'supervisor_only',  1, 'storage_files/FILE-7G8H9I0J1K', 12);

-- Material linked to defense 3 (the slides file id = 2)
INSERT INTO defense_materials (defense_id, file_id, version, uploaded_by) VALUES (3, 2, 2, 8);

-- Audit trail
INSERT INTO audit_logs (defense_id, action, detail, actor) VALUES
 (3,'defense.scheduled',         'Defense scheduled at CS Defense Room 2',               'Dr. Amaka Obi'),
 (3,'session.started',           'Session started',                                      'Dr. Amaka Obi'),
 (3,'score.submitted',           'Score submitted by external examiner Prof. K. Mensah', 'Prof. K. Mensah'),
 (3,'defense.aggregated',        'Weighted aggregate computed (86.10)',                  'system'),
 (3,'defense.finalized',         'Result finalized and locked',                          'Dr. Amaka Obi'),
 (3,'defense.published',         'Result published to stakeholders',                     'Dr. Amaka Obi'),
 (3,'result.sent_exam_officer',  'Final grade forwarded to examination office',          'Dr. Amaka Obi'),
 (4,'defense.scheduled',         'Defense scheduled (virtual)',                          'Dr. Amaka Obi'),
 (4,'score.submitted',           'All evaluator scores submitted',                       'panel');

SET FOREIGN_KEY_CHECKS = 1;

-- Demo credentials (mirrors the production migration's auth columns).
UPDATE users SET password_hash = '$2y$10$VAjQAfGkX3gEoxco4rpOCuZeqaFYh8haiZtxliZaCTfLxkCNw8WH.', is_active = 1 WHERE password_hash IS NULL;

SQL;
    $sql = preg_replace('/^--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', preg_split('/;\s*[\r\n]/', $sql))) as $stmt) {
        if ($stmt === '') continue;
        try { $pdo->exec($stmt); } catch (PDOException $e) { /* ignore dup/order */ }
    }
}
