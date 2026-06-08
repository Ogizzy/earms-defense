<?php
// pages/results.php — Results & Grades
require_once __DIR__ . '/../config/actions.php';
require_can('view_results');
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0); $do = $_POST['do'] ?? ''; $r = null;
    if ($do === 'aggregate') $r = act_aggregate($db, $id);
    if ($do === 'finalize')  $r = act_finalize($db, $id);
    if ($do === 'publish')   $r = act_publish($db, $id);
    if ($do === 'send_exam') $r = act_send_to_exam_officer($db, $id);
    if ($r) flash($r['ok'] ? 'Done.' : $r['error'], $r['ok'] ? 'ok' : 'err');
    header('Location: ' . BASE_URL . '/pages/results.php'); exit;
}

$awaiting = qrows($db,
    "SELECT d.*, pr.title, u.name AS student_name,
            (SELECT COUNT(*) FROM defense_scores s WHERE s.defense_id=d.id) AS scored_n
     FROM defenses d JOIN projects pr ON pr.id=d.project_id JOIN users u ON u.id=d.student_id
     WHERE d.status='completed' AND d.finalized=0 ORDER BY d.scheduled_at DESC");

$finalized = qrows($db,
    "SELECT d.*, pr.title, u.name AS student_name
     FROM defenses d JOIN projects pr ON pr.id=d.project_id JOIN users u ON u.id=d.student_id
     WHERE d.finalized=1 ORDER BY d.updated_at DESC");

$pageTitle = 'Results & Grades'; $activeNav = 'results';
require_once __DIR__ . '/../includes/layout.php';
?>
<div class="welcome-bar">
  <div><h1>Results &amp; Grades</h1><p>Aggregate weighted scores, finalize grades, publish and forward to the examination office</p></div>
</div>

<?php if ($awaiting): ?>
<div class="card" style="margin-bottom:16px;">
  <div class="card-head"><div><div class="card-title">Awaiting Finalization</div><div class="card-sub">Scores submitted — ready to aggregate &amp; finalize</div></div><span class="badge badge-pending"><?= count($awaiting) ?> pending</span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Reference</th><th>Student / Project</th><th>Scores</th><th>Aggregate</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($awaiting as $d): ?>
        <tr>
          <td style="font-family:monospace;font-size:11px;color:var(--muted);"><?= e($d['reference']) ?></td>
          <td><div style="font-size:12.5px;font-weight:600;"><?= e($d['student_name']) ?></div><div style="font-size:11px;color:var(--muted);max-width:240px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= e($d['title']) ?></div></td>
          <td style="font-size:12.5px;"><?= $d['scored_n'] ?> in</td>
          <td><?= $d['aggregate_score']!==null?'<span class="badge badge-brand">'.e($d['aggregate_score']).'</span>':'<span style="color:var(--faint);">—</span>' ?></td>
          <td>
            <div style="display:flex;gap:6px;flex-wrap:wrap;">
              <form method="POST"><?= csrf_field() ?><input type="hidden" name="do" value="aggregate"><input type="hidden" name="id" value="<?= $d['id'] ?>"><button class="btn btn-outline btn-xs">Aggregate</button></form>
              <form method="POST" onsubmit="return confirm('Finalize and lock scores?')"><?= csrf_field() ?><input type="hidden" name="do" value="finalize"><input type="hidden" name="id" value="<?= $d['id'] ?>"><button class="btn btn-primary btn-xs" <?= $d['scored_n']>0?'':'disabled' ?>>Finalize</button></form>
              <a href="<?= BASE_URL ?>/pages/defenses/view.php?id=<?= $d['id'] ?>#results" class="btn btn-outline btn-xs">Open</a>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-head"><div><div class="card-title">Finalized Results</div><div class="card-sub">Published grades &amp; outcomes</div></div></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Reference</th><th>Student / Project</th><th>Aggregate</th><th>Grade</th><th>Outcome</th><th>Published</th><th>Exam Office</th><th>Actions</th></tr></thead>
      <tbody>
      <?php if (!$finalized): ?>
        <tr><td colspan="8"><div class="empty-state"><span class="material-symbols-outlined">workspace_premium</span><h3>No finalized results yet</h3></div></td></tr>
      <?php else: foreach ($finalized as $d): ?>
        <tr>
          <td style="font-family:monospace;font-size:11px;color:var(--muted);"><?= e($d['reference']) ?></td>
          <td><div style="font-size:12.5px;font-weight:600;"><?= e($d['student_name']) ?></div><div style="font-size:11px;color:var(--muted);max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= e($d['title']) ?></div></td>
          <td style="font-weight:700;font-size:13px;"><?= e($d['aggregate_score']) ?></td>
          <td><span class="badge badge-brand" style="font-size:12px;"><?= e($d['final_grade']) ?></span></td>
          <td><?= $d['result_status']==='pass'?'<span class="badge badge-active">Pass</span>':'<span class="badge badge-failed">Fail</span>' ?></td>
          <td><?= $d['published']?'<span class="badge badge-active">Yes</span>':'<span class="badge badge-inactive">No</span>' ?></td>
          <td><?= $d['sent_to_exam_officer']?'<span class="badge badge-active">Sent</span>':'<span class="badge badge-inactive">—</span>' ?></td>
          <td>
            <div style="display:flex;gap:6px;flex-wrap:wrap;">
              <?php if (!$d['published']): ?><form method="POST"><?= csrf_field() ?><input type="hidden" name="do" value="publish"><input type="hidden" name="id" value="<?= $d['id'] ?>"><button class="btn btn-green btn-xs">Publish</button></form><?php endif; ?>
              <?php if (!$d['sent_to_exam_officer']): ?><form method="POST"><?= csrf_field() ?><input type="hidden" name="do" value="send_exam"><input type="hidden" name="id" value="<?= $d['id'] ?>"><button class="btn btn-gold btn-xs">Send</button></form><?php endif; ?>
              <a href="<?= BASE_URL ?>/pages/defenses/view.php?id=<?= $d['id'] ?>#results" class="btn btn-outline btn-xs">View</a>
              <a href="<?= BASE_URL ?>/result_sheet.php?id=<?= $d['id'] ?>" class="btn btn-gold btn-xs" target="_blank"><span class="material-symbols-outlined">picture_as_pdf</span>PDF</a>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/layout_end.php';
