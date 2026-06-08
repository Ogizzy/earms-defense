<?php
// download.php?id=FILE_ID — stream a stored file with access control
require_once __DIR__ . '/config/db.php';
require_login();
$db = getDB();

$id = (int)($_GET['id'] ?? 0);
$f = qrow($db, "SELECT * FROM files WHERE id=? AND is_deleted=0", [$id]);
if (!$f) { http_response_code(404); echo 'File not found'; exit; }

// Access enforcement by level + role.
$role = current_role();
$level = $f['access_level'];
$allowed = match ($level) {
    'institution_wide' => true,
    'department_only'  => in_array($role, ['coordinator','supervisor','internal_examiner','external_examiner','exam_officer'], true),
    'supervisor_only'  => in_array($role, ['coordinator','supervisor'], true),
    'student_only'     => in_array($role, ['coordinator','student'], true),
    default            => $role === 'coordinator',
};
if (!$allowed) { http_response_code(403); echo 'You do not have access to this file.'; exit; }

if (!$f['is_stored']) {
    // Seed/metadata-only record with no real bytes on disk.
    http_response_code(409);
    echo 'This file record has no stored content (metadata only).';
    exit;
}
audit($db, $f['defense_id'] ? (int)$f['defense_id'] : null, 'file.downloaded', "Downloaded {$f['name']}");
stream_file($f['storage_path'], $f['original_name'] ?: $f['name'], $f['mime'] ?: null);
