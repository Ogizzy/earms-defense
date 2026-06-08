<?php
// pages/scoring.php — Scoring overview across defenses
require_once __DIR__ . '/../config/actions.php';
require_can('score');
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'submit_score') {
    $id = (int)$_POST['defense_id'];
    $r = act_submit_score($db, $id, $_POST);
    flash($r['ok'] ? 'Score submitted and locked.' : $r['error'], $r['ok'] ? 'ok' : 'err');
    header('Location: ' . BASE_URL . '/pages/scoring.php'); exit;
}

// Defenses that are ongoing/completed but not finalized — scoring still relevant
$rows = qrows($db,
    "SELECT d.*, pr.title, u.name AS student_name,
            (SELECT COUNT(*) FROM defense_participants p WHERE p.defense_id=d.id AND p.role IN ('supervisor','internal_examiner','external_examiner')) AS panel_n,
            (SELECT COUNT(*) FROM defense_scores s WHERE s.defense_id=d.id) AS scored_n
     FROM defenses d
     JOIN projects pr ON pr.id=d.project_id
     JOIN users u ON u.id=d.student_id
     WHERE d.status IN ('ongoing','completed') AND d.finalized=0
     ORDER BY d.scheduled_at DESC");

// Pending evaluators for the quick-score modal (across these defenses)
$pending = [];
foreach ($rows as $d) {
    $evs = qrows($db,
        "SELECT p.user_id, u.name, p.role FROM defense_participants p JOIN users u ON u.id=p.user_id
         WHERE p.defense_id=? AND p.role IN ('supervisor','internal_examiner','external_examiner')
           AND p.user_id NOT IN (SELECT evaluator_id FROM defense_scores WHERE defense_id=?)", [$d['id'], $d['id']]);
    if ($evs) $pending[$d['id']] = $evs;
}

$pageTitle = 'Scoring'; $activeNav = 'scoring';
require_once __DIR__ . '/../includes/layout.php';
?>
<div class="welcome-bar">
  <div><h1>Scoring</h1><p>Rubric-based evaluation · enter and lock examiner scores</p></div>
</div>

<div class="info-ribbon brand" style="margin-bottom:16px;">
  <div class="ir-icon"><span class="material-symbols-outlined">grading</span></div>
  <div class="ir-text">
    <div class="ir-title">Scoring rubric — total 100 marks</div>
    <div class="ir-sub">Content Quality 30 · Presentation 25 · Originality 25 · Defense Responses 20. Each examiner's score locks on submission.</div>
  </div>
</div>

<div class="card">
  <div class="card-head"><div><div class="card-title">Defenses Open for Scoring</div><div class="card-sub">Ongoing or completed sessions not yet finalized</div></div></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Reference</th><th>Student / Project</th><th>Status</th><th>Scores In</th><th>Progress</th><th></th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="6"><div class="empty-state"><span class="material-symbols-outlined">grading</span><h3>Nothing to score</h3><p>No ongoing or unfinalized defenses right now.</p></div></td></tr>
      <?php else: foreach ($rows as $d): $pct = $d['panel_n']? round($d['scored_n']/$d['panel_n']*100):0; ?>
        <tr>
          <td style="font-family:monospace;font-size:11px;color:var(--muted);"><?= e($d['reference']) ?></td>
          <td><div style="font-size:12.5px;font-weight:600;"><?= e($d['student_name']) ?></div><div style="font-size:11px;color:var(--muted);max-width:240px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= e($d['title']) ?></div></td>
          <td><?= statusBadge($d['status']) ?></td>
          <td style="font-size:12.5px;font-weight:600;"><?= $d['scored_n'] ?>/<?= $d['panel_n'] ?></td>
          <td style="min-width:120px;"><div class="rev-bar-track"><div class="rev-bar-fill" style="width:<?= $pct ?>%;background:<?= $pct==100?'var(--green)':'var(--brand)' ?>;"></div></div></td>
          <td>
            <div style="display:flex;gap:6px;">
              <?php if (!empty($pending[$d['id']])): ?><button class="btn btn-primary btn-xs" onclick='openScore(<?= (int)$d['id'] ?>, <?= json_encode($pending[$d['id']]) ?>)'>Enter Score</button><?php endif; ?>
              <a href="<?= BASE_URL ?>/pages/defenses/view.php?id=<?= $d['id'] ?>#scoring" class="btn btn-outline btn-xs">Open</a>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-backdrop" id="scoreModal">
  <div class="modal" style="max-width:480px;">
    <div class="modal-header"><div class="modal-title">Submit Score</div><button class="modal-close" onclick="closeModal('scoreModal')"><span class="material-symbols-outlined">close</span></button></div>
    <form method="POST"><?= csrf_field() ?>
      <input type="hidden" name="do" value="submit_score"><input type="hidden" name="defense_id" id="sc_did">
      <input type="hidden" name="evaluator_id" id="sc_eid"><input type="hidden" name="evaluator_role" id="sc_role">
      <div class="modal-body">
        <div class="form-group"><label class="form-label">Evaluator</label><select id="sc_eval" class="form-control" onchange="pickEval()"></select></div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Content Quality (30)</label><input type="number" step="0.5" min="0" max="30" name="content_quality" class="form-control" required></div>
          <div class="form-group"><label class="form-label">Presentation (25)</label><input type="number" step="0.5" min="0" max="25" name="presentation" class="form-control" required></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Originality (25)</label><input type="number" step="0.5" min="0" max="25" name="originality" class="form-control" required></div>
          <div class="form-group"><label class="form-label">Defense Responses (20)</label><input type="number" step="0.5" min="0" max="20" name="defense_response" class="form-control" required></div>
        </div>
        <div class="form-group" style="margin-bottom:0;"><label class="form-label">Comments</label><textarea name="comments" class="form-control" rows="2"></textarea></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('scoreModal')">Cancel</button><button class="btn btn-primary">Submit &amp; Lock</button></div>
    </form>
  </div>
</div>

<script>
var _evals=[];
function openScore(did, evals){
  _evals=evals; document.getElementById('sc_did').value=did;
  var sel=document.getElementById('sc_eval'); sel.innerHTML='';
  evals.forEach(function(ev,i){ var o=document.createElement('option'); o.value=i; o.textContent=ev.name+' ('+ev.role.replace('_',' ')+')'; sel.appendChild(o); });
  pickEval(); openModal('scoreModal');
}
function pickEval(){ var i=document.getElementById('sc_eval').value; var ev=_evals[i]; document.getElementById('sc_eid').value=ev.user_id; document.getElementById('sc_role').value=ev.role; }
</script>
<?php require_once __DIR__ . '/../includes/layout_end.php';
