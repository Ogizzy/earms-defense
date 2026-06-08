<?php
// meeting_start.php?id=DEFENSE_ID — host a WINGS meeting for a defense.
// Calls the WINGS API server-side (keeping the API key & JWT off the page) and
// redirects the host straight into the moderated room.
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/actions.php';
require_login();

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
$d  = qrow($db, "SELECT * FROM defenses WHERE id=?", [$id]);

$back = BASE_URL . '/pages/defenses/view.php?id=' . $id . '#session';
if (!$d) { flash('Defense not found.', 'err'); header('Location: ' . BASE_URL . '/pages/defenses/index.php'); exit; }
if ($d['mode'] !== 'virtual') { flash('This is not a virtual defense.', 'err'); header('Location: ' . $back); exit; }

// Only the coordinator (or upstream-forwarded coordinator) may host.
if (!can('*') && current_role() !== 'coordinator') {
    flash('Only the Project Coordinator can host the session.', 'err');
    header('Location: ' . $back); exit;
}

$me   = current_user();
$room = wings_room_name($d['reference']);
$r = wings_start_meeting($room, $me['name'] ?? 'Host', $me['email'] ?? 'host@earms.local', $me['id'] ?? 0);

if (!$r['ok']) {
    audit($db, $id, 'meeting.start_failed', $r['error'] . (isset($r['raw']) ? ' | ' . $r['raw'] : ''));
    flash('Could not start the meeting: ' . $r['error'], 'err');
    header('Location: ' . $back); exit;
}

audit($db, $id, 'meeting.started', "Host meeting started for {$d['reference']} (room $room)");
header('Location: ' . $r['adminUrl']);
exit;
