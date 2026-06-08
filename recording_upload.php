<?php
// recording_upload.php?id=DEFENSE_ID — receive a browser-captured recording blob,
// store the real file, and register a saved recording for the defense.
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/actions.php';
require_login();

header('Content-Type: application/json');

// Only the coordinator (or upstream gateway) may save session recordings.
if (!can('*') && current_role() !== 'coordinator') {
    jsonOut(['success' => false, 'error' => 'Not permitted'], 403);
}
if (!csrf_check()) {
    jsonOut(['success' => false, 'error' => 'Invalid CSRF token'], 419);
}

$db = getDB();
$id = (int)($_GET['id'] ?? $_POST['defense_id'] ?? 0);
$d  = qrow($db, "SELECT * FROM defenses WHERE id=?", [$id]);
if (!$d) jsonOut(['success' => false, 'error' => 'Defense not found'], 404);

if (empty($_FILES['recording']) || ($_FILES['recording']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    jsonOut(['success' => false, 'error' => 'No recording received'], 422);
}

$durationSec = max(0, (int)($_POST['duration_sec'] ?? 0));

// The captured blob arrives as a .webm file; force the extension for storage.
$file = $_FILES['recording'];
if (!preg_match('/\.webm$/i', $file['name'])) $file['name'] = 'defense-recording.webm';

$stored = store_uploaded_file($file);
if (!$stored['ok']) jsonOut(['success' => false, 'error' => $stored['error']], 422);

// Register the stored file (Storage microservice).
$db->prepare("INSERT INTO files (file_uid,project_id,defense_id,name,original_name,file_type,mime,size_bytes,access_level,version,storage_path,checksum,is_stored,uploaded_by)
              VALUES (?,?,?,?,?,?,?,?, 'department_only', 1, ?, ?, 1, ?)")
   ->execute([$stored['uid'], $d['project_id'], $id, 'Defense_Recording.webm', $file['name'],
              'recording', 'video/webm', $stored['size'], $stored['path'], $stored['checksum'],
              current_user()['id'] ?? null]);
$fileId = (int)$db->lastInsertId();

// Register the recording itself.
$db->prepare("INSERT INTO defense_recordings (defense_id,file_id,status,duration_sec,size_bytes,storage_path,started_at,stopped_at)
              VALUES (?,?, 'saved', ?, ?, ?, NOW(), NOW())")
   ->execute([$id, $fileId, $durationSec, $stored['size'], $stored['path']]);
$recId = (int)$db->lastInsertId();

audit($db, $id, 'recording.saved', "Live recording captured & stored ({$stored['size']} bytes, {$durationSec}s)");
jsonOut(['success' => true, 'data' => ['recording_id' => $recId, 'file_id' => $fileId, 'size' => $stored['size']]], 201);
