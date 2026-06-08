<?php
// pages/audit.php — Global audit log
require_once __DIR__ . '/../config/db.php';
require_can('view_audit');
$db = getDB();

$q = trim($_GET['q'] ?? '');
$w = "WHERE 1=1"; $p = [];
if ($q) { $w .= " AND (a.action LIKE ? OR a.detail LIKE ? OR a.actor LIKE ?)"; $s="%$q%"; $p=[$s,$s,$s]; }

$total = (int) qval($db, "SELECT COUNT(*) FROM audit_logs a LEFT JOIN defenses d ON d.id=a.defense_id $w", $p);
$pg = paginate($total);
$rows = qrows($db,
    "SELECT a.*, d.reference FROM audit_logs a LEFT JOIN defenses d ON d.id=a.defense_id
     $w ORDER BY a.id DESC LIMIT " . (int)$pg['per'] . " OFFSET " . (int)$pg['offset'], $p);

$pageTitle = 'Audit Logs'; $activeNav = 'audit';
require_once __DIR__ . '/../includes/layout.php';

$icon = function(string $a): string {
    if (str_contains($a,'finaliz')||str_contains($a,'publish')) return 'workspace_premium';
    if (str_contains($a,'score'))    return 'grading';
    if (str_contains($a,'schedul'))  return 'event';
    if (str_contains($a,'cancel'))   return 'cancel';
    if (str_contains($a,'recording'))return 'movie';
    if (str_contains($a,'file')||str_contains($a,'material')) return 'folder';
    if (str_contains($a,'session'))  return 'meeting_room';
    if (str_contains($a,'participant')||str_contains($a,'attendance')) return 'group';
    return 'bolt';
};
?>
<div class="welcome-bar">
  <div><h1>Audit Logs</h1><p><?= $total ?> recorded event<?= $total!==1?'s':'' ?> · full activity trail across the service</p></div>
</div>

<div class="card"><div class="card-body" style="padding:14px;">
  <form method="GET" style="display:flex;gap:10px;align-items:center;">
    <div class="tb-search" style="flex:1;"><span class="material-symbols-outlined">search</span><input type="text" name="q" value="<?= e($q) ?>" placeholder="Search action, detail or actor…"></div>
    <button class="btn btn-primary btn-sm">Search</button>
    <?php if ($q): ?><a href="<?= BASE_URL ?>/pages/audit.php" class="btn btn-outline btn-sm">Clear</a><?php endif; ?>
  </form>
</div></div>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead><tr><th>Event</th><th>Detail</th><th>Defense</th><th>Actor</th><th>Timestamp</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="5"><div class="empty-state"><span class="material-symbols-outlined">security</span><h3>No audit entries</h3></div></td></tr>
      <?php else: foreach ($rows as $a): ?>
        <tr>
          <td><span style="display:inline-flex;align-items:center;gap:7px;"><span class="material-symbols-outlined" style="font-size:16px;color:var(--brand);"><?= $icon($a['action']) ?></span><span class="badge badge-brand"><?= e($a['action']) ?></span></span></td>
          <td style="font-size:12.5px;"><?= e($a['detail']) ?></td>
          <td style="font-family:monospace;font-size:11px;color:var(--muted);"><?= e($a['reference'] ?? '—') ?></td>
          <td style="font-size:12px;color:var(--muted);"><?= e($a['actor'] ?: 'system') ?></td>
          <td style="font-size:11px;color:var(--faint);white-space:nowrap;"><?= date('d M Y, H:i', strtotime($a['created_at'])) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pg['pages'] > 1): ?><div class="card-body" style="padding:12px;"><?= pager_html($pg, ['q'=>$q]) ?></div><?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/layout_end.php';
