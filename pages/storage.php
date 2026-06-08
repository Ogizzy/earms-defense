<?php
// pages/storage.php — Storage microservice / file repository
require_once __DIR__ . '/../config/actions.php';
require_can('view_storage');
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? ''; $r = null;
    if ($do === 'upload') {
        if (!empty($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $stored = store_uploaded_file($_FILES['file']);
            if (!$stored['ok']) { $r = err($stored['error'], 422); }
            else {
                $me = current_user();
                $r = act_store_file($db, $stored, [
                    'project_id' => $_POST['project_id'] ?? 0,
                    'file_type'  => $_POST['file_type'] ?? 'document',
                    'access_level' => $_POST['access_level'] ?? 'department_only',
                    'uploaded_by' => $me['id'] ?? null,
                ]);
            }
        } else {
            $r = err('Please choose a file to upload', 422);
        }
    }
    if ($do === 'delete') $r = act_file_delete($db, (int)$_POST['id']);
    if ($r) flash($r['ok'] ? 'Done.' : $r['error'], $r['ok'] ? 'ok' : 'err');
    header('Location: ' . BASE_URL . '/pages/storage.php'); exit;
}

$project = $_GET['project'] ?? ''; $access = $_GET['access'] ?? '';
$w = "WHERE f.is_deleted=0"; $p = [];
if ($project !== '') { $w .= " AND f.project_id=?"; $p[] = (int)$project; }
if ($access !== '')  { $w .= " AND f.access_level=?"; $p[] = $access; }

$total = (int) qval($db, "SELECT COUNT(*) FROM files f $w", $p);
$pg = paginate($total);
$files = qrows($db,
    "SELECT f.*, pr.title AS project_title, u.name AS uploader
     FROM files f LEFT JOIN projects pr ON pr.id=f.project_id LEFT JOIN users u ON u.id=f.uploaded_by
     $w ORDER BY f.created_at DESC LIMIT " . (int)$pg['per'] . " OFFSET " . (int)$pg['offset'], $p);

$projects = qrows($db, "SELECT id,title FROM projects ORDER BY title");
$totalBytes = (int) qval($db, "SELECT COALESCE(SUM(size_bytes),0) FROM files WHERE is_deleted=0");
$byAccess = [];
foreach (ACCESS_LEVELS as $k=>$v) $byAccess[$k] = (int) qval($db, "SELECT COUNT(*) FROM files WHERE is_deleted=0 AND access_level=?", [$k]);

$pageTitle = 'File Storage'; $activeNav = 'storage';
require_once __DIR__ . '/../includes/layout.php';
?>
<div class="welcome-bar">
  <div><h1>File Storage</h1><p>Repository microservice · documents, slides, datasets &amp; recordings with access control</p></div>
  <button class="btn btn-primary" onclick="openModal('uploadModal')"><span class="material-symbols-outlined">upload</span>Upload File</button>
</div>

<div class="kpi-row">
  <div class="kpi-card c-brand"><div class="kpi-info"><div class="kpi-label">Total Files</div><div class="kpi-value"><?= count(qrows($db,"SELECT id FROM files WHERE is_deleted=0")) ?></div></div><div class="kpi-icon c-brand"><span class="material-symbols-outlined">folder_managed</span></div></div>
  <div class="kpi-card c-teal"><div class="kpi-info"><div class="kpi-label">Storage Used</div><div class="kpi-value"><?= humanSize($totalBytes) ?></div></div><div class="kpi-icon c-teal"><span class="material-symbols-outlined">database</span></div></div>
  <div class="kpi-card c-gold"><div class="kpi-info"><div class="kpi-label">Institution-wide</div><div class="kpi-value"><?= $byAccess['institution_wide'] ?? 0 ?></div></div><div class="kpi-icon c-gold"><span class="material-symbols-outlined">public</span></div></div>
  <div class="kpi-card c-green"><div class="kpi-info"><div class="kpi-label">Restricted</div><div class="kpi-value"><?= ($byAccess['student_only']??0)+($byAccess['supervisor_only']??0) ?></div></div><div class="kpi-icon c-green"><span class="material-symbols-outlined">lock</span></div></div>
</div>

<div class="card"><div class="card-body" style="padding:14px;">
  <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
    <select name="project" class="form-control" style="width:240px;"><option value="">All Projects</option><?php foreach ($projects as $pr): ?><option value="<?= $pr['id'] ?>" <?= (string)$project===(string)$pr['id']?'selected':'' ?>><?= e($pr['title']) ?></option><?php endforeach; ?></select>
    <select name="access" class="form-control" style="width:180px;"><option value="">All Access Levels</option><?php foreach (ACCESS_LEVELS as $k=>$v): ?><option value="<?= $k ?>" <?= $access===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?></select>
    <button class="btn btn-primary btn-sm">Filter</button>
    <a href="<?= BASE_URL ?>/pages/storage.php" class="btn btn-outline btn-sm">Reset</a>
  </form>
</div></div>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead><tr><th>File</th><th>Type</th><th>Project</th><th>Access</th><th>Ver</th><th>Size</th><th>Uploaded</th><th></th></tr></thead>
      <tbody>
      <?php if (!$files): ?>
        <tr><td colspan="8"><div class="empty-state"><span class="material-symbols-outlined">folder_off</span><h3>No files</h3><p>Upload a file or adjust filters.</p></div></td></tr>
      <?php else: foreach ($files as $f): ?>
        <tr>
          <td><span class="material-symbols-outlined" style="font-size:17px;color:var(--brand);vertical-align:middle;"><?= fileIcon($f['file_type']) ?></span> <span style="font-size:12.5px;font-weight:600;"><?= e($f['name']) ?></span><div style="font-family:monospace;font-size:10px;color:var(--faint);margin-left:24px;"><?= e($f['file_uid']) ?></div></td>
          <td style="font-size:12px;"><?= e(ucfirst($f['file_type'])) ?></td>
          <td style="font-size:11.5px;color:var(--muted);max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= e($f['project_title'] ?? '—') ?></td>
          <td><?= accessBadge($f['access_level']) ?></td>
          <td style="font-size:12px;">v<?= (int)$f['version'] ?></td>
          <td style="font-size:12px;color:var(--muted);"><?= humanSize((int)$f['size_bytes']) ?></td>
          <td style="font-size:11px;color:var(--faint);white-space:nowrap;"><?= date('d M Y', strtotime($f['created_at'])) ?></td>
          <td style="display:flex;gap:6px;">
            <?php if (!empty($f['is_stored'])): ?><a href="<?= BASE_URL ?>/download.php?id=<?= $f['id'] ?>" class="btn btn-outline btn-xs">Download</a><?php endif; ?>
            <form method="POST" onsubmit="return confirm('Delete this file?')"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $f['id'] ?>"><button class="btn btn-danger btn-xs">Delete</button></form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pg['pages'] > 1): ?><div class="card-body" style="padding:12px;"><?= pager_html($pg, ['project'=>$project,'access'=>$access]) ?></div><?php endif; ?>
</div>

<div class="modal-backdrop" id="uploadModal">
  <div class="modal" style="max-width:460px;">
    <div class="modal-header"><div class="modal-title">Upload File</div><button class="modal-close" onclick="closeModal('uploadModal')"><span class="material-symbols-outlined">close</span></button></div>
    <form method="POST" enctype="multipart/form-data"><?= csrf_field() ?>
      <input type="hidden" name="do" value="upload">
      <div class="modal-body">
        <div class="form-group"><label class="form-label">File</label><input type="file" name="file" class="form-control" required></div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Type</label><select name="file_type" class="form-control"><option value="document">Document</option><option value="slides">Slides</option><option value="dataset">Dataset</option><option value="recording">Recording</option><option value="feedback">Feedback</option></select></div>
          <div class="form-group"><label class="form-label">Project</label><select name="project_id" class="form-control"><option value="">None</option><?php foreach ($projects as $pr): ?><option value="<?= $pr['id'] ?>"><?= e($pr['title']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-group" style="margin-bottom:0;"><label class="form-label">Access Level</label><select name="access_level" class="form-control"><?php foreach (ACCESS_LEVELS as $k=>$v): ?><option value="<?= $k ?>" <?= $k==='department_only'?'selected':'' ?>><?= $v ?></option><?php endforeach; ?></select></div>
        <p class="form-hint">Max <?= humanSize(MAX_UPLOAD_BYTES) ?>. Allowed: <?= implode(', ', ALLOWED_UPLOAD_EXT) ?>.</p>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('uploadModal')">Cancel</button><button class="btn btn-primary"><span class="material-symbols-outlined">upload</span>Upload</button></div>
    </form>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/layout_end.php';
