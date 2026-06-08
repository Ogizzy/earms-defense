<?php
// index.php — EARMS Defense & Evaluation Dashboard
require_once __DIR__ . '/config/db.php';
$db = getDB();

$totalDefenses = (int) qval($db, "SELECT COUNT(*) FROM defenses WHERE status!='cancelled'");
$upcoming      = (int) qval($db, "SELECT COUNT(*) FROM defenses WHERE status='scheduled' AND scheduled_at>=NOW()");
$completed     = (int) qval($db, "SELECT COUNT(*) FROM defenses WHERE status='completed'");
$published     = (int) qval($db, "SELECT COUNT(*) FROM defenses WHERE published=1");
$awaitingFinal = (int) qval($db, "SELECT COUNT(*) FROM defenses WHERE status='completed' AND finalized=0");
$passCount     = (int) qval($db, "SELECT COUNT(*) FROM defenses WHERE result_status='pass'");
$resultCount   = (int) qval($db, "SELECT COUNT(*) FROM defenses WHERE finalized=1");
$passRate      = $resultCount ? round($passCount / $resultCount * 100) : 0;
$totalFiles    = (int) qval($db, "SELECT COUNT(*) FROM files WHERE is_deleted=0");
$storageBytes  = (int) qval($db, "SELECT COALESCE(SUM(size_bytes),0) FROM files WHERE is_deleted=0");

$upcomingRows = qrows($db,
    "SELECT d.*, p.title, u.name AS student_name
     FROM defenses d JOIN projects p ON p.id=d.project_id JOIN users u ON u.id=d.student_id
     WHERE d.status IN ('scheduled','ongoing') ORDER BY d.scheduled_at ASC LIMIT 6");

$recentAudit = qrows($db, "SELECT * FROM audit_logs ORDER BY id DESC LIMIT 8");

$statusBreakdown = [
    'Scheduled' => (int) qval($db, "SELECT COUNT(*) FROM defenses WHERE status='scheduled'"),
    'Ongoing'   => (int) qval($db, "SELECT COUNT(*) FROM defenses WHERE status='ongoing'"),
    'Completed' => $completed,
    'Cancelled' => (int) qval($db, "SELECT COUNT(*) FROM defenses WHERE status='cancelled'"),
];
$maxStatus = max(1, max($statusBreakdown));

$pageTitle = 'Dashboard'; $activeNav = 'dashboard';
require_once __DIR__ . '/includes/layout.php';

$auditIcon = function(string $a): array {
    if (str_contains($a, 'finalized') || str_contains($a, 'published')) return ['green', 'workspace_premium'];
    if (str_contains($a, 'score'))      return ['brand', 'grading'];
    if (str_contains($a, 'scheduled') || str_contains($a, 'reschedule')) return ['gold', 'event'];
    if (str_contains($a, 'cancel'))     return ['red', 'cancel'];
    if (str_contains($a, 'recording'))  return ['brand', 'movie'];
    if (str_contains($a, 'file') || str_contains($a, 'material')) return ['gold', 'folder'];
    return ['brand', 'bolt'];
};
?>
<div class="welcome-bar">
  <div>
    <h1>Defense &amp; Evaluation</h1>
    <p>Welcome back, <strong><?= e(actor_name()) ?></strong> — managing research defenses, scoring and results.</p>
  </div>
  <div class="btn-actions">
    <a href="<?= BASE_URL ?>/pages/defenses/index.php?action=new" class="btn btn-primary"><span class="material-symbols-outlined">add</span>Schedule Defense</a>
    <a href="<?= BASE_URL ?>/pages/results.php" class="btn btn-outline"><span class="material-symbols-outlined">workspace_premium</span>Results</a>
  </div>
</div>

<?php if ($awaitingFinal > 0): ?>
<div class="info-ribbon brand">
  <div class="ir-icon"><span class="material-symbols-outlined">pending_actions</span></div>
  <div class="ir-text">
    <div class="ir-title"><?= $awaitingFinal ?> defense<?= $awaitingFinal!==1?'s':'' ?> awaiting finalization</div>
    <div class="ir-sub">Scores are in — aggregate and finalize to release grades to the examination office.</div>
  </div>
  <div class="ir-actions"><a href="<?= BASE_URL ?>/pages/results.php" class="btn btn-white">Review now</a></div>
</div>
<?php endif; ?>

<div class="kpi-row">
  <div class="kpi-card c-brand">
    <div class="kpi-info">
      <div class="kpi-label">Total Defenses</div>
      <div class="kpi-value"><?= $totalDefenses ?></div>
      <span class="kpi-change up"><span class="material-symbols-outlined">event_available</span><?= $upcoming ?> upcoming</span>
    </div>
    <div class="kpi-icon c-brand"><span class="material-symbols-outlined">gavel</span></div>
  </div>
  <div class="kpi-card c-gold">
    <div class="kpi-info">
      <div class="kpi-label">Awaiting Finalization</div>
      <div class="kpi-value"><?= $awaitingFinal ?></div>
      <span class="kpi-change <?= $awaitingFinal? 'down':'up' ?>"><span class="material-symbols-outlined">grading</span>scored</span>
    </div>
    <div class="kpi-icon c-gold"><span class="material-symbols-outlined">pending_actions</span></div>
  </div>
  <div class="kpi-card c-green">
    <div class="kpi-info">
      <div class="kpi-label">Pass Rate</div>
      <div class="kpi-value"><?= $passRate ?>%</div>
      <span class="kpi-change up"><span class="material-symbols-outlined">verified</span><?= $passCount ?>/<?= $resultCount ?> passed</span>
    </div>
    <div class="kpi-icon c-green"><span class="material-symbols-outlined">trending_up</span></div>
  </div>
  <div class="kpi-card c-teal">
    <div class="kpi-info">
      <div class="kpi-label">Files Stored</div>
      <div class="kpi-value"><?= $totalFiles ?></div>
      <span class="kpi-change up"><span class="material-symbols-outlined">database</span><?= humanSize($storageBytes) ?></span>
    </div>
    <div class="kpi-icon c-teal"><span class="material-symbols-outlined">folder_managed</span></div>
  </div>
</div>

<div class="grid-3">
  <!-- Upcoming defenses -->
  <div class="card">
    <div class="card-head">
      <div><div class="card-title">Upcoming &amp; Active Defenses</div><div class="card-sub">Next sessions on the calendar</div></div>
      <a href="<?= BASE_URL ?>/pages/defenses/index.php" class="btn btn-outline btn-sm">View all</a>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Reference</th><th>Student / Project</th><th>Mode</th><th>Schedule</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php if (!$upcomingRows): ?>
          <tr><td colspan="6"><div class="empty-state"><span class="material-symbols-outlined">event_busy</span><h3>No upcoming defenses</h3><p>Schedule a defense to get started.</p></div></td></tr>
        <?php else: foreach ($upcomingRows as $d): ?>
          <tr>
            <td style="font-family:monospace;font-size:11px;color:var(--muted);"><?= e($d['reference']) ?></td>
            <td>
              <div style="font-size:12.5px;font-weight:600;"><?= e($d['student_name']) ?></div>
              <div style="font-size:11px;color:var(--muted);max-width:240px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= e($d['title']) ?></div>
            </td>
            <td><?= modeBadge($d['mode']) ?></td>
            <td style="font-size:12px;"><?= date('d M Y', strtotime($d['scheduled_at'])) ?><div style="font-size:11px;color:var(--faint);"><?= date('H:i', strtotime($d['scheduled_at'])) ?> · <?= e($d['venue']) ?></div></td>
            <td><?= statusBadge($d['status']) ?></td>
            <td><a href="<?= BASE_URL ?>/pages/defenses/view.php?id=<?= $d['id'] ?>" class="btn btn-outline btn-xs">Open</a></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Side column -->
  <div style="display:flex;flex-direction:column;gap:14px;">
    <div class="card">
      <div class="card-head"><div class="card-title">Defense Status</div></div>
      <div class="card-body">
        <?php foreach ($statusBreakdown as $label => $n):
          $colors = ['Scheduled'=>'var(--gold)','Ongoing'=>'var(--brand)','Completed'=>'var(--green)','Cancelled'=>'var(--red)']; ?>
        <div class="rev-bar-wrap">
          <div class="rev-bar-label"><span class="rev-bar-name"><?= $label ?></span><span class="rev-bar-val"><?= $n ?></span></div>
          <div class="rev-bar-track"><div class="rev-bar-fill" style="width:<?= round($n/$maxStatus*100) ?>%;background:<?= $colors[$label] ?>;"></div></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><div class="card-title">Recent Activity</div></div>
      <div style="max-height:300px;overflow-y:auto;">
        <?php foreach ($recentAudit as $a): [$col,$ic] = $auditIcon($a['action']); ?>
        <div class="activity-item">
          <div class="act-icon <?= $col ?>"><span class="material-symbols-outlined"><?= $ic ?></span></div>
          <div class="act-body">
            <div class="act-title"><?= e(ucwords(str_replace(['.','_'],' ',$a['action']))) ?></div>
            <div class="act-sub"><?= e($a['detail']) ?></div>
          </div>
          <div class="act-time"><?= date('d M H:i', strtotime($a['created_at'])) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/layout_end.php';
