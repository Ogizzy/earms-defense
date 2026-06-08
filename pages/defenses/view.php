<?php
// pages/defenses/view.php — Defense detail & lifecycle workspace
require_once __DIR__ . '/../../config/actions.php';
require_can('view_defense');
$db = getDB();
$id = (int)($_GET['id'] ?? 0);

// Coordinator-only state-changing actions vs panel actions.
$coordinatorOnly = ['add_participant','remove_participant','start_session','end_session',
    'rec_start','rec_stop','rec_save','aggregate','finalize','publish','send_exam','delete_material'];

// ── POST actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? ''; $r = null;
    // Gate privileged lifecycle actions to coordinators; scoring/attendance open to panel.
    if (in_array($do, $coordinatorOnly, true) && !can('*') && current_role() !== 'coordinator') {
        flash('Only the Project Coordinator can perform that action.', 'err');
        header('Location: ' . BASE_URL . '/pages/defenses/view.php?id=' . $id); exit;
    }
    switch ($do) {
        case 'add_participant':   $r = act_add_participant($db, $id, ['user_id'=>$_POST['user_id'],'role'=>$_POST['role']]); break;
        case 'remove_participant':$r = act_remove_participant($db, $id, (int)$_POST['user_id']); break;
        case 'attendance':        $r = act_set_attendance($db, $id, ['user_id'=>$_POST['user_id'],'attendance'=>$_POST['attendance']]); break;
        case 'upload_material':
            if (!empty($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $stored = store_uploaded_file($_FILES['file']);
                $r = $stored['ok'] ? act_store_material_file($db, $id, $stored, $_POST['file_type'] ?? 'slides') : err($stored['error'], 422);
            } else { $r = err('Please choose a file to upload', 422); }
            break;
        case 'delete_material':   $r = act_delete_material($db, (int)$_POST['file_id']); break;
        case 'start_session':     $r = act_start_session($db, $id); break;
        case 'end_session':       $r = act_end_session($db, $id); break;
        case 'rec_start':         $r = act_recording_start($db, $id); break;
        case 'rec_stop':          $r = act_recording_stop($db, $id); break;
        case 'rec_save':          $r = act_recording_save($db, $id, ['size_bytes'=>$_POST['size_bytes']??rand(180,260)*1000000]); break;
        case 'submit_score':      $r = act_submit_score($db, $id, $_POST); break;
        case 'aggregate':         $r = act_aggregate($db, $id); break;
        case 'finalize':          $r = act_finalize($db, $id); break;
        case 'publish':           $r = act_publish($db, $id); break;
        case 'send_exam':         $r = act_send_to_exam_officer($db, $id); break;
    }
    if ($r) flash($r['ok'] ? 'Done.' : $r['error'], $r['ok'] ? 'ok' : 'err');
    header('Location: ' . BASE_URL . '/pages/defenses/view.php?id=' . $id . '#' . ($_POST['tab'] ?? '')); exit;
}

$res = act_get_defense($db, $id);
if (!$res['ok']) { http_response_code(404); $pageTitle='Not found'; $activeNav='defenses'; require_once __DIR__.'/../../includes/layout.php'; echo '<div class="empty-state"><span class="material-symbols-outlined">error</span><h3>Defense not found</h3></div>'; require_once __DIR__.'/../../includes/layout_end.php'; exit; }
$d = $res['data'];

// Evaluators eligible to score (supervisor + examiners on the panel)
$evaluators = array_values(array_filter($d['participants'], fn($p)=>in_array($p['role'],['supervisor','internal_examiner','external_examiner'])));
$scoresByEval = []; foreach ($d['scores'] as $s) $scoresByEval[$s['evaluator_id']] = $s;
$candidateUsers = qrows($db, "SELECT id,name,role FROM users WHERE role IN ('supervisor','internal_examiner','external_examiner','student') ORDER BY name");
$auditRows = qrows($db, "SELECT * FROM audit_logs WHERE defense_id=? ORDER BY id DESC", [$id]);

$pageTitle = 'Defense ' . $d['reference']; $activeNav = 'defenses';
require_once __DIR__ . '/../../includes/layout.php';
?>
<style>
.tabs{display:flex;gap:4px;border-bottom:1.5px solid var(--border);margin-bottom:16px;flex-wrap:wrap;}
.tab{padding:10px 16px;font-size:13px;font-weight:600;color:var(--muted);cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1.5px;}
.tab:hover{color:var(--brand);}
.tab.active{color:var(--brand);border-bottom-color:var(--brand);}
.tabpane{display:none;} .tabpane.active{display:block;}
.kv{display:flex;justify-content:space-between;font-size:12.5px;padding:8px 0;border-bottom:1px solid var(--border-soft);} .kv:last-child{border:none;}
.kv .k{color:var(--muted);} .kv .v{font-weight:600;color:var(--text);}
</style>

<div class="welcome-bar">
  <div>
    <h1><?= e($d['project']['title']) ?></h1>
    <p><span style="font-family:monospace;"><?= e($d['reference']) ?></span> · <strong><?= e($d['student']['name']) ?></strong> · <?= statusBadge($d['status']) ?> <?= modeBadge($d['mode']) ?></p>
  </div>
  <a href="<?= BASE_URL ?>/pages/defenses/index.php" class="btn btn-outline"><span class="material-symbols-outlined">arrow_back</span>Back</a>
</div>

<div class="card"><div class="card-body">
  <div class="tabs">
    <div class="tab active" data-t="overview">Overview &amp; Participants</div>
    <div class="tab" data-t="materials">Materials</div>
    <div class="tab" data-t="session">Session &amp; Recordings</div>
    <div class="tab" data-t="scoring">Scoring</div>
    <div class="tab" data-t="results">Results</div>
    <div class="tab" data-t="audit">Audit</div>
  </div>

  <!-- ── OVERVIEW & PARTICIPANTS ── -->
  <div class="tabpane active" id="t-overview">
    <div class="grid-2">
      <div>
        <div class="card-title" style="margin-bottom:10px;">Session Details</div>
        <div class="kv"><span class="k">Reference</span><span class="v" style="font-family:monospace;"><?= e($d['reference']) ?></span></div>
        <div class="kv"><span class="k">Scheduled</span><span class="v"><?= date('d M Y, H:i', strtotime($d['scheduled_at'])) ?> CAT</span></div>
        <div class="kv"><span class="k">Mode</span><span class="v"><?= modeBadge($d['mode']) ?></span></div>
        <div class="kv"><span class="k">Venue</span><span class="v"><?= e($d['venue'] ?: '—') ?></span></div>
        <?php if ($d['meeting_url']): ?><div class="kv"><span class="k">Meeting</span><span class="v"><a href="<?= e($d['meeting_url']) ?>" style="color:var(--brand);"><?= e($d['meeting_url']) ?></a></span></div><?php endif; ?>
        <div class="kv"><span class="k">Status</span><span class="v"><?= statusBadge($d['status']) ?></span></div>
        <div class="kv"><span class="k">Department</span><span class="v"><?= e($d['project']['department']) ?></span></div>
      </div>
      <div>
        <div class="card-title" style="margin-bottom:10px;">Panel &amp; Participants</div>
        <table>
          <thead><tr><th>Name</th><th>Role</th><th>Attendance</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($d['participants'] as $p): ?>
            <tr>
              <td style="font-size:12.5px;font-weight:600;"><?= e($p['name']) ?></td>
              <td><?= roleBadge($p['role']) ?></td>
              <td>
                <form method="POST" style="display:inline-flex;gap:4px;align-items:center;"><?= csrf_field() ?>
                  <input type="hidden" name="do" value="attendance"><input type="hidden" name="user_id" value="<?= $p['user_id'] ?>"><input type="hidden" name="tab" value="overview">
                  <select name="attendance" class="form-control" style="padding:4px 8px;font-size:11px;width:auto;" onchange="this.form.submit()">
                    <?php foreach (['pending','present','absent'] as $st): ?><option value="<?= $st ?>" <?= $p['attendance']===$st?'selected':'' ?>><?= ucfirst($st) ?></option><?php endforeach; ?>
                  </select>
                </form>
              </td>
              <td><?php if ($p['role']!=='student'): ?>
                <form method="POST" onsubmit="return confirm('Remove participant?')"><?= csrf_field() ?><input type="hidden" name="do" value="remove_participant"><input type="hidden" name="user_id" value="<?= $p['user_id'] ?>"><input type="hidden" name="tab" value="overview"><button class="btn btn-danger btn-xs">Remove</button></form>
              <?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <form method="POST" style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap;"><?= csrf_field() ?>
          <input type="hidden" name="do" value="add_participant"><input type="hidden" name="tab" value="overview">
          <select name="user_id" class="form-control" style="flex:1;min-width:140px;" required><option value="">Add participant…</option><?php foreach ($candidateUsers as $u): ?><option value="<?= $u['id'] ?>"><?= e($u['name']) ?> (<?= e($u['role']) ?>)</option><?php endforeach; ?></select>
          <select name="role" class="form-control" style="width:160px;"><?php foreach (PARTICIPANT_ROLES as $k=>$v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select>
          <button class="btn btn-primary btn-sm">Add</button>
        </form>
      </div>
    </div>
  </div>

  <!-- ── MATERIALS ── -->
  <div class="tabpane" id="t-materials">
    <table>
      <thead><tr><th>File</th><th>Type</th><th>Version</th><th>Size</th><th></th></tr></thead>
      <tbody>
      <?php if (!$d['materials']): ?>
        <tr><td colspan="5"><div class="empty-state"><span class="material-symbols-outlined">slideshow</span><h3>No materials uploaded</h3></div></td></tr>
      <?php else: foreach ($d['materials'] as $m): ?>
        <tr>
          <td style="font-weight:600;font-size:12.5px;"><span class="material-symbols-outlined" style="font-size:16px;color:var(--brand);vertical-align:middle;"><?= fileIcon($m['file_type']) ?></span> <?= e($m['name']) ?></td>
          <td><?= e(ucfirst($m['file_type'])) ?></td>
          <td>v<?= (int)$m['version'] ?></td>
          <td style="font-size:12px;color:var(--muted);"><?= humanSize((int)$m['size_bytes']) ?></td>
          <td style="display:flex;gap:6px;align-items:center;">
            <?php if (!empty($m['is_stored'])): ?><a href="<?= BASE_URL ?>/download.php?id=<?= $m['file_id'] ?>" class="btn btn-outline btn-xs">Download</a><?php endif; ?>
            <?php if (!in_array($d['status'],['ongoing','completed'])): ?><form method="POST" onsubmit="return confirm('Delete material?')"><?= csrf_field() ?><input type="hidden" name="do" value="delete_material"><input type="hidden" name="file_id" value="<?= $m['file_id'] ?>"><input type="hidden" name="tab" value="materials"><button class="btn btn-danger btn-xs">Delete</button></form><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
    <?php if (!in_array($d['status'],['completed','cancelled'])): ?>
    <form method="POST" enctype="multipart/form-data" style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap;align-items:center;"><?= csrf_field() ?>
      <input type="hidden" name="do" value="upload_material"><input type="hidden" name="tab" value="materials">
      <input type="file" name="file" class="form-control" style="flex:1;min-width:200px;" required>
      <select name="file_type" class="form-control" style="width:150px;"><option value="slides">Slides</option><option value="document">Document</option><option value="dataset">Dataset</option></select>
      <button class="btn btn-primary btn-sm"><span class="material-symbols-outlined">upload</span>Upload</button>
    </form>
    <p class="form-hint">Files are registered with the Storage microservice and version-controlled per defense.</p>
    <?php endif; ?>
  </div>

  <!-- ── SESSION & RECORDINGS ── -->
  <div class="tabpane" id="t-session">
    <div class="info-ribbon <?= $d['status']==='ongoing'?'green':'brand' ?>" style="margin-bottom:16px;">
      <div class="ir-icon"><span class="material-symbols-outlined"><?= $d['status']==='ongoing'?'sensors':'meeting_room' ?></span></div>
      <div class="ir-text"><div class="ir-title">Session <?= ucfirst($d['status']) ?></div><div class="ir-sub"><?= $d['mode']==='virtual'?'Virtual session':'Physical session at '.e($d['venue']) ?></div></div>
      <div class="ir-actions">
        <?php if ($d['status']==='scheduled'): ?>
          <form method="POST"><?= csrf_field() ?><input type="hidden" name="do" value="start_session"><input type="hidden" name="tab" value="session"><button class="btn btn-white"><span class="material-symbols-outlined">play_arrow</span>Start Session</button></form>
        <?php elseif ($d['status']==='ongoing'): ?>
          <form method="POST" onsubmit="return confirm('End the session and mark completed?')"><?= csrf_field() ?><input type="hidden" name="do" value="end_session"><input type="hidden" name="tab" value="session"><button class="btn btn-white"><span class="material-symbols-outlined">stop</span>End Session</button></form>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($d['mode']==='virtual' && in_array($d['status'],['scheduled','ongoing'])):
      $isHost = can('*') || current_role()==='coordinator';
      $joinUrl = wings_join_url(wings_room_name($d['reference']), $myName);
    ?>
    <!-- Live WINGS meeting -->
    <div class="card" style="margin-bottom:16px;">
      <div class="card-head"><div><div class="card-title">Virtual Defense Room</div><div class="card-sub">Powered by WINGS conferencing</div></div></div>
      <div class="card-body">
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
          <?php if ($isHost): ?>
            <a href="<?= BASE_URL ?>/meeting_start.php?id=<?= (int)$id ?>" target="_blank" rel="noopener" class="btn btn-primary"><span class="material-symbols-outlined">videocam</span>Start &amp; host meeting</a>
          <?php endif; ?>
          <a href="<?= e($joinUrl) ?>" target="_blank" rel="noopener" class="btn btn-outline"><span class="material-symbols-outlined">login</span>Join meeting</a>
        </div>
        <p class="form-hint" style="margin-top:10px;">
          <?= $isHost ? 'Starting as host opens a moderated room in a new tab.' : 'Join opens the defense room in a new tab.' ?>
          Camera &amp; microphone permission is required (the room runs over HTTPS).
        </p>
      </div>
    </div>
    <?php endif; ?>

    <?php $activeRec = null; foreach ($d['recordings'] as $rr) if ($rr['status']==='recording') $activeRec=$rr; ?>
    <div class="card-title" style="margin:6px 0 10px;">Recordings</div>

    <?php if ($d['status']==='ongoing' && (can('*') || current_role()==='coordinator')): ?>
      <!-- Real in-browser capture → uploads a true recording file -->
      <div style="display:flex;gap:8px;margin-bottom:8px;align-items:center;flex-wrap:wrap;">
        <button id="recStart" class="btn btn-danger btn-sm"><span class="material-symbols-outlined">fiber_manual_record</span>Record Session</button>
        <button id="recStop" class="btn btn-outline btn-sm" style="display:none;"><span class="material-symbols-outlined">stop_circle</span>Stop &amp; Save</button>
        <span id="recTimer" style="display:none;font-weight:700;color:var(--red);font-variant-numeric:tabular-nums;">● 00:00</span>
        <span id="recStatus" class="form-hint"></span>
      </div>
      <p class="form-hint" style="margin-bottom:12px;">Captures the chosen screen/window or camera with audio and stores it directly to the repository.</p>
    <?php endif; ?>

    <table>
      <thead><tr><th>Recording</th><th>Status</th><th>Duration</th><th>Size</th><th></th></tr></thead>
      <tbody>
      <?php if (!$d['recordings']): ?><tr><td colspan="5"><div class="empty-state"><span class="material-symbols-outlined">movie</span><h3>No recordings</h3></div></td></tr>
      <?php else: foreach ($d['recordings'] as $rr): ?>
        <tr>
          <td style="font-size:12.5px;font-weight:600;">Recording #<?= $rr['id'] ?></td>
          <td><?= statusBadge($rr['status']) ?></td>
          <td style="font-size:12px;"><?= $rr['duration_sec']?humanDuration((int)$rr['duration_sec']):'—' ?></td>
          <td style="font-size:12px;color:var(--muted);"><?= $rr['size_bytes']?humanSize((int)$rr['size_bytes']):'—' ?></td>
          <td><?php if ($rr['file_id'] && $rr['status']==='saved'): ?><a href="<?= BASE_URL ?>/download.php?id=<?= $rr['file_id'] ?>" class="btn btn-outline btn-xs">Download</a><?php endif; ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- ── SCORING ── -->
  <div class="tabpane" id="t-scoring">
    <?php if ($d['finalized']): ?><div class="alert alert-info"><span class="material-symbols-outlined">lock</span>Scores are locked — this defense has been finalized.</div><?php endif; ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Evaluator</th><th>Role</th><?php foreach (RUBRIC as $rk=>$rc): ?><th><?= e($rc['label']) ?> /<?= $rc['max'] ?></th><?php endforeach; ?><th>Total</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($evaluators as $ev): $sc = $scoresByEval[$ev['user_id']] ?? null; ?>
        <tr>
          <td style="font-size:12.5px;font-weight:600;"><?= e($ev['name']) ?></td>
          <td><?= roleBadge($ev['role']) ?></td>
          <?php if ($sc): foreach (RUBRIC as $rk=>$rc): ?><td><?= rtrim(rtrim(number_format((float)$sc[$rk],1),'0'),'.') ?></td><?php endforeach; ?>
            <td><span class="badge badge-brand"><?= rtrim(rtrim(number_format((float)$sc['total'],1),'0'),'.') ?></span></td>
            <td><span class="material-symbols-outlined" style="font-size:16px;color:var(--green-dark);" title="Locked">lock</span></td>
          <?php else: ?>
            <?php foreach (RUBRIC as $rk=>$rc): ?><td style="color:var(--faint);">—</td><?php endforeach; ?>
            <td style="color:var(--faint);">—</td>
            <td><?php if (!$d['finalized']): ?><button class="btn btn-primary btn-xs" onclick='openScore(<?= (int)$ev['user_id'] ?>, <?= json_encode($ev['name']) ?>, <?= json_encode($ev['role']) ?>)'>Enter Score</button><?php endif; ?></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

  <!-- ── RESULTS ── -->
  <div class="tabpane" id="t-results">
    <?php
      $scoreCount = count($d['scores']); $evalCount = count($evaluators);
      $canAggregate = $scoreCount>0;
    ?>
    <div class="balance-banner" style="margin-bottom:16px;">
      <div><div class="bb-label">Aggregate Score</div><div class="bb-val"><?= $d['aggregate_score']!==null?e($d['aggregate_score']):'—' ?></div><div class="bb-sub">weighted /100</div></div>
      <div><div class="bb-label">Grade</div><div class="bb-val"><?= $d['final_grade']?e($d['final_grade']):'—' ?></div><div class="bb-sub">institutional policy</div></div>
      <div><div class="bb-label">Outcome</div><div class="bb-val"><?= $d['result_status']?ucfirst($d['result_status']):'—' ?></div><div class="bb-sub"><?= $scoreCount ?>/<?= $evalCount ?> scores in</div></div>
      <div><div class="bb-label">Published</div><div class="bb-val"><?= $d['published']?'Yes':'No' ?></div><div class="bb-sub"><?= $d['sent_to_exam_officer']?'Sent to exams':'Not sent' ?></div></div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <form method="POST"><?= csrf_field() ?><input type="hidden" name="do" value="aggregate"><input type="hidden" name="tab" value="results"><button class="btn btn-outline" <?= $canAggregate?'':'disabled' ?>><span class="material-symbols-outlined">functions</span>Aggregate</button></form>
      <form method="POST" onsubmit="return confirm('Finalize? This locks all scores and assigns the grade.')"><?= csrf_field() ?><input type="hidden" name="do" value="finalize"><input type="hidden" name="tab" value="results"><button class="btn btn-primary" <?= (!$d['finalized']&&$canAggregate)?'':'disabled' ?>><span class="material-symbols-outlined">lock</span>Finalize</button></form>
      <form method="POST"><?= csrf_field() ?><input type="hidden" name="do" value="publish"><input type="hidden" name="tab" value="results"><button class="btn btn-green" <?= ($d['finalized']&&!$d['published'])?'':'disabled' ?>><span class="material-symbols-outlined">campaign</span>Publish</button></form>
      <form method="POST"><?= csrf_field() ?><input type="hidden" name="do" value="send_exam"><input type="hidden" name="tab" value="results"><button class="btn btn-gold" <?= ($d['finalized']&&!$d['sent_to_exam_officer'])?'':'disabled' ?>><span class="material-symbols-outlined">forward_to_inbox</span>Send to Exam Officer</button></form>
    </div>
    <p class="form-hint" style="margin-top:10px;">Weighting — Supervisor 30% · Internal Examiners 40% · External Examiner 30%. Pass mark 50. Grades: A≥70, B≥60, C≥50, D≥45, F&lt;45.</p>
  </div>

  <!-- ── AUDIT ── -->
  <div class="tabpane" id="t-audit">
    <table>
      <thead><tr><th>Action</th><th>Detail</th><th>Actor</th><th>Time</th></tr></thead>
      <tbody>
      <?php foreach ($auditRows as $a): ?>
        <tr>
          <td><span class="badge badge-brand"><?= e($a['action']) ?></span></td>
          <td style="font-size:12.5px;"><?= e($a['detail']) ?></td>
          <td style="font-size:12px;color:var(--muted);"><?= e($a['actor']) ?></td>
          <td style="font-size:11px;color:var(--faint);white-space:nowrap;"><?= date('d M Y H:i', strtotime($a['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div></div>

<!-- Score modal -->
<div class="modal-backdrop" id="scoreModal">
  <div class="modal" style="max-width:480px;">
    <div class="modal-header"><div class="modal-title">Submit Score — <span id="sc_name"></span></div><button class="modal-close" onclick="closeModal('scoreModal')"><span class="material-symbols-outlined">close</span></button></div>
    <form method="POST"><?= csrf_field() ?>
      <input type="hidden" name="do" value="submit_score"><input type="hidden" name="tab" value="scoring">
      <input type="hidden" name="evaluator_id" id="sc_eid"><input type="hidden" name="evaluator_role" id="sc_role">
      <div class="modal-body">
        <?php foreach (RUBRIC as $rk=>$rc): ?>
        <div class="form-group"><label class="form-label"><?= e($rc['label']) ?> (max <?= $rc['max'] ?>)</label><input type="number" step="0.5" min="0" max="<?= $rc['max'] ?>" name="<?= $rk ?>" class="form-control" required></div>
        <?php endforeach; ?>
        <div class="form-group" style="margin-bottom:0;"><label class="form-label">Comments</label><textarea name="comments" class="form-control" rows="2"></textarea></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('scoreModal')">Cancel</button><button class="btn btn-primary">Submit &amp; Lock</button></div>
    </form>
  </div>
</div>

<script>
document.querySelectorAll('.tab').forEach(t=>t.addEventListener('click',()=>{
  document.querySelectorAll('.tab').forEach(x=>x.classList.remove('active'));
  document.querySelectorAll('.tabpane').forEach(x=>x.classList.remove('active'));
  t.classList.add('active'); document.getElementById('t-'+t.dataset.t).classList.add('active');
  history.replaceState(null,'','#'+t.dataset.t);
}));
function showTab(name){ var t=document.querySelector('.tab[data-t="'+name+'"]'); if(t) t.click(); }
if(location.hash){ showTab(location.hash.slice(1)); }
function openScore(eid,name,role){ document.getElementById('sc_eid').value=eid; document.getElementById('sc_role').value=role; document.getElementById('sc_name').textContent=name; openModal('scoreModal'); }

/* ── Real in-browser session recording (MediaRecorder → upload) ── */
<?php if ($d['status']==='ongoing' && (can('*') || current_role()==='coordinator')): ?>
(function(){
  var startBtn=document.getElementById('recStart'), stopBtn=document.getElementById('recStop'),
      timerEl=document.getElementById('recTimer'), statusEl=document.getElementById('recStatus');
  if(!startBtn) return;
  var mediaRecorder, chunks=[], stream, startTime, timerInt;
  function fmt(s){var m=Math.floor(s/60),sec=s%60;return ('0'+m).slice(-2)+':'+('0'+sec).slice(-2);}
  startBtn.addEventListener('click', async function(){
    try {
      // Prefer screen+audio; fall back to camera+mic.
      try { stream = await navigator.mediaDevices.getDisplayMedia({video:true, audio:true}); }
      catch(e){ stream = await navigator.mediaDevices.getUserMedia({video:true, audio:true}); }
      chunks=[]; mediaRecorder = new MediaRecorder(stream, {mimeType: MediaRecorder.isTypeSupported('video/webm;codecs=vp9')?'video/webm;codecs=vp9':'video/webm'});
      mediaRecorder.ondataavailable = function(e){ if(e.data.size>0) chunks.push(e.data); };
      mediaRecorder.onstop = uploadRecording;
      mediaRecorder.start(1000);
      startTime = Date.now();
      startBtn.style.display='none'; stopBtn.style.display='inline-flex'; timerEl.style.display='inline';
      timerInt = setInterval(function(){ timerEl.textContent='● '+fmt(Math.floor((Date.now()-startTime)/1000)); }, 1000);
      statusEl.textContent='Recording…';
    } catch(err){ statusEl.textContent='Could not start capture: '+err.message; }
  });
  stopBtn.addEventListener('click', function(){
    if(mediaRecorder && mediaRecorder.state!=='inactive') mediaRecorder.stop();
    if(stream) stream.getTracks().forEach(function(t){t.stop();});
    clearInterval(timerInt);
    stopBtn.disabled=true; statusEl.textContent='Uploading…';
  });
  async function uploadRecording(){
    var dur = Math.floor((Date.now()-startTime)/1000);
    var blob = new Blob(chunks, {type:'video/webm'});
    var fd = new FormData();
    fd.append('recording', blob, 'defense-recording.webm');
    fd.append('duration_sec', dur);
    fd.append('_csrf', window.EARMS_CSRF || '');
    try {
      var res = await fetch('<?= BASE_URL ?>/recording_upload.php?id=<?= (int)$id ?>', {method:'POST', body:fd});
      var j = await res.json();
      if(j.success){ statusEl.textContent='Saved ('+(j.data.size/1048576).toFixed(1)+' MB). Reloading…'; setTimeout(function(){location.hash='#session';location.reload();},900); }
      else { statusEl.textContent='Upload failed: '+(j.error||'error'); stopBtn.disabled=false; }
    } catch(e){ statusEl.textContent='Upload error: '+e.message; stopBtn.disabled=false; }
  }
})();
<?php endif; ?>
</script>
<?php require_once __DIR__ . '/../../includes/layout_end.php';
