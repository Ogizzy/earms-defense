<?php
// api/index.php — EARMS Defense & Evaluation: REST API front controller
// Implements the documented endpoint contract. JSON in / JSON out. No login
// (auth handled upstream by the IAM Service + API Gateway in the full platform).

require_once __DIR__ . '/../config/actions.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

$db = getDB();

// Method (supports override for clients without PUT/DELETE)
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$override = $_POST['_method'] ?? ($_GET['_method'] ?? null);
if ($method === 'POST' && $override) $method = strtoupper($override);

// Path after the script name, e.g. /defenses/3/score
$path = $_SERVER['PATH_INFO'] ?? '';
if ($path === '' && isset($_SERVER['REQUEST_URI'])) {
    $uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '';
    $path = preg_replace('#^.*/api/index\.php#', '', $uri);
}
$path = '/' . trim($path, '/');

// Parse input body (JSON or form)
$raw = file_get_contents('php://input');
$body = [];
if ($raw) {
    $json = json_decode($raw, true);
    $body = is_array($json) ? $json : [];
}
$input = array_merge($_GET, $_POST, $body);

// Route helper: returns matches or false
function route(string $method, string $pattern, string $reqMethod, string $reqPath, &$m): bool {
    if ($method !== $reqMethod) return false;
    $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
    return (bool) preg_match($regex, $reqPath, $m);
}

function send(array $r): void {
    if ($r['ok']) jsonOut(['success' => true,  'data' => $r['data'] ?? null], $r['code'] ?? 200);
    jsonOut(['success' => false, 'error' => $r['error'] ?? 'Error'], $r['code'] ?? 400);
}

$m = [];

/* ───── API index ───── */
if ($method === 'GET' && $path === '/') {
    jsonOut([
        'service' => 'EARMS Defense & Evaluation + Storage',
        'version' => APP_VERSION,
        'endpoints' => [
            'POST   /defenses/schedule', 'GET /defenses', 'GET /defenses/{id}',
            'PUT    /defenses/{id}/reschedule', 'DELETE /defenses/{id}',
            'POST   /defenses/{id}/participants', 'GET /defenses/{id}/participants',
            'DELETE /defenses/{id}/participants/{userId}', 'PUT /defenses/{id}/attendance',
            'POST   /defenses/{id}/materials/upload', 'GET /defenses/{id}/materials', 'DELETE /materials/{id}',
            'POST   /defenses/{id}/start-session', 'POST /defenses/{id}/end-session',
            'POST   /defenses/{id}/recordings/start', 'POST /defenses/{id}/recordings/stop',
            'POST   /defenses/{id}/recordings/save', 'GET /defenses/{id}/recordings',
            'DELETE /defenses/{id}/recordings/{recordingId}',
            'POST   /defenses/{id}/score', 'GET /defenses/{id}/scores', 'PUT /defenses/{id}/score/{scoreId}',
            'POST   /defenses/{id}/aggregate', 'POST /defenses/{id}/finalize',
            'GET    /defenses/{id}/result', 'POST /defenses/{id}/publish',
            'POST   /defenses/{id}/send-to-exam-officer', 'GET /defenses/{id}/audit-log',
            'POST   /files/upload', 'GET /files/{id}', 'DELETE /files/{id}', 'GET /projects/{id}/files',
        ],
    ]);
}

/* ───── SCHEDULING ───── */
if (route('POST', '/defenses/schedule', $method, $path, $m))   send(act_schedule_defense($db, $input));
if (route('GET',  '/defenses', $method, $path, $m)) {
    // Search/filter: projectId, date, status, participant
    $w = "WHERE 1=1"; $p = [];
    if (!empty($input['projectId'])) { $w .= " AND project_id=?";   $p[] = (int)$input['projectId']; }
    if (!empty($input['status']))    { $w .= " AND status=?";        $p[] = $input['status']; }
    if (!empty($input['date']))      { $w .= " AND DATE(scheduled_at)=?"; $p[] = $input['date']; }
    if (!empty($input['participant'])) {
        $w .= " AND id IN (SELECT defense_id FROM defense_participants WHERE user_id=?)"; $p[] = (int)$input['participant'];
    }
    send(ok(qrows($db, "SELECT * FROM defenses $w ORDER BY scheduled_at DESC", $p)));
}
if (route('GET',    '/defenses/{id}', $method, $path, $m))            send(act_get_defense($db, (int)$m['id']));
if (route('PUT',    '/defenses/{id}/reschedule', $method, $path, $m)) send(act_reschedule($db, (int)$m['id'], $input));
if (route('DELETE', '/defenses/{id}', $method, $path, $m))            send(act_cancel($db, (int)$m['id']));

/* ───── PARTICIPANTS ───── */
if (route('POST',   '/defenses/{id}/participants', $method, $path, $m)) send(act_add_participant($db, (int)$m['id'], $input));
if (route('GET',    '/defenses/{id}/participants', $method, $path, $m))
    send(ok(qrows($db, "SELECT p.*, u.name, u.email FROM defense_participants p JOIN users u ON u.id=p.user_id WHERE p.defense_id=?", [(int)$m['id']])));
if (route('DELETE', '/defenses/{id}/participants/{userId}', $method, $path, $m)) send(act_remove_participant($db, (int)$m['id'], (int)$m['userId']));
if (route('PUT',    '/defenses/{id}/attendance', $method, $path, $m))  send(act_set_attendance($db, (int)$m['id'], $input));

/* ───── MATERIALS ───── */
if (route('POST',   '/defenses/{id}/materials/upload', $method, $path, $m)) send(act_upload_material($db, (int)$m['id'], $input));
if (route('GET',    '/defenses/{id}/materials', $method, $path, $m))
    send(ok(qrows($db, "SELECT m.*, f.name, f.file_type, f.size_bytes, f.storage_path FROM defense_materials m JOIN files f ON f.id=m.file_id WHERE m.defense_id=?", [(int)$m['id']])));
if (route('DELETE', '/materials/{id}', $method, $path, $m)) send(act_delete_material($db, (int)$m['id']));

/* ───── SESSIONS ───── */
if (route('POST', '/defenses/{id}/start-session', $method, $path, $m)) send(act_start_session($db, (int)$m['id']));
if (route('POST', '/defenses/{id}/end-session', $method, $path, $m))   send(act_end_session($db, (int)$m['id']));

/* ───── RECORDINGS ───── */
if (route('POST',   '/defenses/{id}/recordings/start', $method, $path, $m)) send(act_recording_start($db, (int)$m['id']));
if (route('POST',   '/defenses/{id}/recordings/stop', $method, $path, $m))  send(act_recording_stop($db, (int)$m['id']));
if (route('POST',   '/defenses/{id}/recordings/save', $method, $path, $m))  send(act_recording_save($db, (int)$m['id'], $input));
if (route('GET',    '/defenses/{id}/recordings', $method, $path, $m))
    send(ok(qrows($db, "SELECT * FROM defense_recordings WHERE defense_id=? ORDER BY created_at DESC", [(int)$m['id']])));
if (route('DELETE', '/defenses/{id}/recordings/{recordingId}', $method, $path, $m)) {
    $aff = $db->prepare("DELETE FROM defense_recordings WHERE id=? AND defense_id=?");
    $aff->execute([(int)$m['recordingId'], (int)$m['id']]);
    if (!$aff->rowCount()) send(err('Recording not found', 404));
    audit($db, (int)$m['id'], 'recording.deleted', "Recording #{$m['recordingId']} deleted");
    send(ok(['deleted' => true]));
}

/* ───── SCORING ───── */
if (route('POST', '/defenses/{id}/score', $method, $path, $m))           send(act_submit_score($db, (int)$m['id'], $input));
if (route('GET',  '/defenses/{id}/scores', $method, $path, $m))
    send(ok(qrows($db, "SELECT s.*, u.name AS evaluator_name FROM defense_scores s JOIN users u ON u.id=s.evaluator_id WHERE s.defense_id=?", [(int)$m['id']])));
if (route('PUT',  '/defenses/{id}/score/{scoreId}', $method, $path, $m)) send(act_update_score($db, (int)$m['id'], (int)$m['scoreId'], $input));

/* ───── AGGREGATION & RESULTS ───── */
if (route('POST', '/defenses/{id}/aggregate', $method, $path, $m)) send(act_aggregate($db, (int)$m['id']));
if (route('POST', '/defenses/{id}/finalize', $method, $path, $m))  send(act_finalize($db, (int)$m['id']));
if (route('GET',  '/defenses/{id}/result', $method, $path, $m))    send(act_result($db, (int)$m['id']));
if (route('POST', '/defenses/{id}/publish', $method, $path, $m))   send(act_publish($db, (int)$m['id']));

/* ───── INTEGRATION & AUDIT ───── */
if (route('POST', '/defenses/{id}/send-to-exam-officer', $method, $path, $m)) send(act_send_to_exam_officer($db, (int)$m['id']));
if (route('GET',  '/defenses/{id}/audit-log', $method, $path, $m))            send(act_audit_log($db, (int)$m['id']));

/* ───── STORAGE MICROSERVICE ───── */
if (route('POST',   '/files/upload', $method, $path, $m))    send(act_file_upload($db, $input));
if (route('GET',    '/files/{id}', $method, $path, $m))      send(act_file_get($db, (int)$m['id']));
if (route('DELETE', '/files/{id}', $method, $path, $m))      send(act_file_delete($db, (int)$m['id']));
if (route('GET',    '/projects/{id}/files', $method, $path, $m)) send(act_project_files($db, (int)$m['id']));

// No route matched
jsonOut(['success' => false, 'error' => 'Not found: ' . $method . ' ' . $path], 404);
