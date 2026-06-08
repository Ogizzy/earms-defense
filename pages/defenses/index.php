<?php
// pages/defenses/index.php — Defenses list + scheduling
require_once __DIR__ . '/../../config/actions.php';
require_can('view_defenses');
$db = getDB();

// ── POST actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? '';
    if ($do === 'schedule') {
        $in = [
            'project_id'            => $_POST['project_id'] ?? 0,
            'scheduled_at'          => str_replace('T', ' ', $_POST['scheduled_at'] ?? '') . ':00',
            'venue'                 => $_POST['venue'] ?? '',
            'mode'                  => $_POST['mode'] ?? 'physical',
            'external_examiner_id'  => $_POST['external_examiner_id'] ?? 0,
            'internal_examiner_ids' => $_POST['internal_examiner_ids'] ?? [],
        ];
        $r = act_schedule_defense($db, $in);
        flash($r['ok'] ? 'Defense scheduled: ' . ($r['data']['reference'] ?? '') : $r['error'], $r['ok'] ? 'ok' : 'err');
        header('Location: ' . BASE_URL . '/pages/defenses/index.php'); exit;
    }
    if ($do === 'reschedule') {
        $r = act_reschedule($db, (int)$_POST['id'], [
            'scheduled_at' => str_replace('T', ' ', $_POST['scheduled_at'] ?? '') . ':00',
            'venue'        => $_POST['venue'] ?? '',
        ]);
        flash($r['ok'] ? 'Defense rescheduled' : $r['error'], $r['ok'] ? 'ok' : 'err');
        header('Location: ' . BASE_URL . '/pages/defenses/index.php'); exit;
    }
    if ($do === 'cancel') {
        $r = act_cancel($db, (int)$_POST['id']);
        flash($r['ok'] ? 'Defense cancelled' : $r['error'], $r['ok'] ? 'ok' : 'err');
        header('Location: ' . BASE_URL . '/pages/defenses/index.php'); exit;
    }
}

// ── Filters ──
$q = trim($_GET['q'] ?? ''); $status = $_GET['status'] ?? ''; $mode = $_GET['mode'] ?? '';
$w = "WHERE 1=1"; $p = [];
if ($q)      { $w .= " AND (d.reference LIKE ? OR u.name LIKE ? OR pr.title LIKE ?)"; $s="%$q%"; $p=array_merge($p,[$s,$s,$s]); }
if ($status) { $w .= " AND d.status=?"; $p[] = $status; }
if ($mode)   { $w .= " AND d.mode=?"; $p[] = $mode; }

$rows = qrows($db,
    "SELECT d.*, pr.title, u.name AS student_name, sv.name AS supervisor_name
     FROM defenses d
     JOIN projects pr ON pr.id=d.project_id
     JOIN users u ON u.id=d.student_id
     LEFT JOIN users sv ON sv.id=d.supervisor_id
     $w ORDER BY d.scheduled_at DESC", $p);
$total = count($rows);
$pg = paginate($total);
$rows = array_slice($rows, $pg['offset'], $pg['per']);

// Schedule-modal reference data: completed projects with no active defense
$availProjects = qrows($db,
    "SELECT pr.id, pr.title, u.name AS student_name FROM projects pr
     JOIN users u ON u.id=pr.student_id
     WHERE pr.status='completed'
       AND pr.id NOT IN (SELECT project_id FROM defenses WHERE status!='cancelled')
     ORDER BY pr.title");
$internalExaminers = qrows($db, "SELECT id,name FROM users WHERE role='internal_examiner' ORDER BY name");
$externalExaminers = qrows($db, "SELECT id,name FROM users WHERE role='external_examiner' ORDER BY name");

$openNew = ($_GET['action'] ?? '') === 'new';
$pageTitle = 'Defenses'; $activeNav = 'defenses';
require_once __DIR__ . '/../../includes/layout.php';
?>
<div class="welcome-bar">
  <div><h1>Defenses</h1><p><?= $total ?> defense session<?= $total!==1?'s':'' ?> · scheduling &amp; lifecycle management</p></div>
  <button class="btn btn-primary" onclick="openModal('scheduleModal')"><span class="material-symbols-outlined">add</span>Schedule Defense</button>
</div>

<div class="card">
  <div class="card-body" style="padding:14px;">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
      <div class="tb-search" style="flex:1;min-width:200px;"><span class="material-symbols-outlined">search</span><input type="text" name="q" value="<?= e($q) ?>" placeholder="Reference, student, project…"/></div>
      <select name="status" class="form-control" style="width:150px;">
        <option value="">All Status</option>
        <?php foreach (DEFENSE_STATUSES as $k=>$v): ?><option value="<?= $k ?>" <?= $status===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?>
      </select>
      <select name="mode" class="form-control" style="width:140px;">
        <option value="">All Modes</option>
        <?php foreach (DEFENSE_MODES as $k=>$v): ?><option value="<?= $k ?>" <?= $mode===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-primary btn-sm">Filter</button>
      <a href="<?= BASE_URL ?>/pages/defenses/index.php" class="btn btn-outline btn-sm">Reset</a>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead><tr><th>Reference</th><th>Student / Project</th><th>Supervisor</th><th>Mode</th><th>Schedule</th><th>Status</th><th>Result</th><th>Actions</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="8"><div class="empty-state"><span class="material-symbols-outlined">gavel</span><h3>No defenses found</h3><p>Adjust filters or schedule a new defense.</p></div></td></tr>
      <?php else: foreach ($rows as $d): ?>
        <tr>
          <td style="font-family:monospace;font-size:11px;color:var(--muted);"><?= e($d['reference']) ?></td>
          <td>
            <div style="font-size:12.5px;font-weight:600;"><?= e($d['student_name']) ?></div>
            <div style="font-size:11px;color:var(--muted);max-width:260px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= e($d['title']) ?></div>
          </td>
          <td style="font-size:12px;"><?= e($d['supervisor_name'] ?? '—') ?></td>
          <td><?= modeBadge($d['mode']) ?></td>
          <td style="font-size:12px;"><?= date('d M Y', strtotime($d['scheduled_at'])) ?><div style="font-size:11px;color:var(--faint);"><?= date('H:i', strtotime($d['scheduled_at'])) ?> · <?= e($d['venue']) ?></div></td>
          <td><?= statusBadge($d['status']) ?></td>
          <td>
            <?php if ($d['finalized']): ?>
              <span class="badge badge-active"><?= e($d['final_grade']) ?> · <?= e($d['aggregate_score']) ?></span>
            <?php else: ?><span style="color:var(--faint);font-size:11px;">—</span><?php endif; ?>
          </td>
          <td>
            <div style="display:flex;gap:6px;">
              <a href="<?= BASE_URL ?>/pages/defenses/view.php?id=<?= $d['id'] ?>" class="btn btn-outline btn-xs">Open</a>
              <?php if (in_array($d['status'], ['scheduled'])): ?>
                <button class="btn btn-outline btn-xs" onclick='openReschedule(<?= (int)$d['id'] ?>, <?= json_encode(date('Y-m-d\TH:i', strtotime($d['scheduled_at']))) ?>, <?= json_encode($d['venue']) ?>)'>Reschedule</button>
                <button class="btn btn-danger btn-xs" onclick="cancelDefense(<?= (int)$d['id'] ?>)">Cancel</button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pg['pages'] > 1): ?><div class="card-body" style="padding:12px;"><?= pager_html($pg, ['q'=>$q,'status'=>$status,'mode'=>$mode]) ?></div><?php endif; ?>
</div>
<div class="modal-backdrop <?= $openNew?'open':'' ?>" id="scheduleModal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Schedule Defense</div>
      <button class="modal-close" onclick="closeModal('scheduleModal')"><span class="material-symbols-outlined">close</span></button>
    </div>
    <form method="POST"><?= csrf_field() ?>
      <input type="hidden" name="do" value="schedule"/>
      <div class="modal-body">
        <?php if (!$availProjects): ?>
          <div class="alert alert-warn"><span class="material-symbols-outlined">info</span>No completed projects are available to schedule. A project must be marked completed first.</div>
        <?php endif; ?>
        <div class="form-group">
          <label class="form-label">Project (completed only)</label>
          <select name="project_id" class="form-control" required>
            <option value="">Select project…</option>
            <?php foreach ($availProjects as $pr): ?><option value="<?= $pr['id'] ?>"><?= e($pr['title']) ?> — <?= e($pr['student_name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Date &amp; Time</label>
            <input type="datetime-local" name="scheduled_at" class="form-control" required/>
          </div>
          <div class="form-group">
            <label class="form-label">Mode</label>
            <select name="mode" class="form-control" id="modeSel" onchange="toggleVenue()">
              <?php foreach (DEFENSE_MODES as $k=>$v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-group" id="venueGroup">
          <label class="form-label">Venue</label>
          <input type="text" name="venue" class="form-control" placeholder="e.g. Senate Hall A"/>
        </div>
        <div class="form-group">
          <label class="form-label">Internal Examiners</label>
          <div style="display:flex;flex-direction:column;gap:6px;background:var(--surface2);border:1.5px solid var(--border);border-radius:10px;padding:10px 12px;">
            <?php foreach ($internalExaminers as $ie): ?>
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
              <input type="checkbox" name="internal_examiner_ids[]" value="<?= $ie['id'] ?>"/> <?= e($ie['name']) ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">External Examiner</label>
          <select name="external_examiner_id" class="form-control">
            <option value="">None</option>
            <?php foreach ($externalExaminers as $ee): ?><option value="<?= $ee['id'] ?>"><?= e($ee['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('scheduleModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><span class="material-symbols-outlined">event_available</span>Schedule</button>
      </div>
    </form>
  </div>
</div>

<!-- Reschedule modal -->
<div class="modal-backdrop" id="rescheduleModal">
  <div class="modal" style="max-width:440px;">
    <div class="modal-header"><div class="modal-title">Reschedule Defense</div><button class="modal-close" onclick="closeModal('rescheduleModal')"><span class="material-symbols-outlined">close</span></button></div>
    <form method="POST"><?= csrf_field() ?>
      <input type="hidden" name="do" value="reschedule"/><input type="hidden" name="id" id="rs_id"/>
      <div class="modal-body">
        <div class="form-group"><label class="form-label">New Date &amp; Time</label><input type="datetime-local" name="scheduled_at" id="rs_dt" class="form-control" required/></div>
        <div class="form-group" style="margin-bottom:0;"><label class="form-label">Venue</label><input type="text" name="venue" id="rs_venue" class="form-control"/></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('rescheduleModal')">Cancel</button><button type="submit" class="btn btn-primary">Update</button></div>
    </form>
  </div>
</div>

<form method="POST" id="cancelForm" style="display:none;"><?= csrf_field() ?><input type="hidden" name="do" value="cancel"/><input type="hidden" name="id" id="cancel_id"/></form>

<script>
function toggleVenue(){ var m=document.getElementById('modeSel').value; document.getElementById('venueGroup').style.display = m==='virtual'?'none':'block'; }
function openReschedule(id, dt, venue){ document.getElementById('rs_id').value=id; document.getElementById('rs_dt').value=dt; document.getElementById('rs_venue').value=venue||''; openModal('rescheduleModal'); }
function cancelDefense(id){ if(confirm('Cancel this defense?\n\nParticipants will be notified and the session locked.')){ document.getElementById('cancel_id').value=id; document.getElementById('cancelForm').submit(); } }
</script>
<?php require_once __DIR__ . '/../../includes/layout_end.php';
