<?php
// includes/layout.php — EARMS Defense & Evaluation App Shell
// Reuses the ORION-PAY shell structure/classes verbatim. Role-aware: the
// signed-in user (or upstream gateway context) drives navigation & access.
// Expects: $pageTitle, $activeNav
require_login();
$db2 = getDB();
$me   = current_user();
$myName = $me['name'] ?? 'User';
$myRole = $me['role'] ?? 'guest';
$myRoleLabel = ROLE_LABELS[$myRole] ?? ucwords(str_replace('_',' ', $myRole));

// Quick header counts
$upcomingCount = (int) qval($db2,
    "SELECT COUNT(*) FROM defenses WHERE status='scheduled' AND scheduled_at >= NOW()");
$awaitingFinalize = (int) qval($db2,
    "SELECT COUNT(*) FROM defenses WHERE status='completed' AND finalized=0");

// Avatar initials from the real name
$parts = preg_split('/\s+/', trim($myName));
$initials = strtoupper(substr($parts[0] ?? 'U', 0, 1) . (count($parts) > 1 ? substr(end($parts), 0, 1) : ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <meta name="theme-color" content="#16a34a"/>
  <title><?= e($pageTitle ?? 'Dashboard') ?> — EARMS Defense</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700;9..40,800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css"/>
</head>
<body>
<div class="app-shell">

<!-- ══ SIDEBAR ══════════════════════════════════════════════ -->
<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="sb-logo-icon"><span class="material-symbols-outlined">gavel</span></div>
    <div>
      <div class="sb-logo-text">EARMS</div>
      <div class="sb-logo-sub">Defense &amp; Evaluation</div>
    </div>
  </div>

  <!-- SERVICE / ROLE BADGE -->
  <div class="sb-role-badge">
    <span class="material-symbols-outlined">verified_user</span>
    <div>
      <div class="sb-role-text"><?= e($myRoleLabel) ?></div>
      <div class="sb-role-sub">Defense &amp; Evaluation Service</div>
    </div>
  </div>

  <nav class="sidebar-nav">
    <span class="nav-section-label">Overview</span>
    <a href="<?= BASE_URL ?>/index.php" class="nav-item <?= $activeNav==='dashboard'?'active':'' ?>">
      <span class="material-symbols-outlined nav-icon">dashboard</span><span class="nav-label">Dashboard</span>
    </a>

    <span class="nav-section-label">Defense &amp; Evaluation</span>
    <?php if (can('view_defenses') || can('view_own_defense')): ?>
    <a href="<?= BASE_URL ?>/pages/defenses/index.php" class="nav-item <?= $activeNav==='defenses'?'active':'' ?>">
      <span class="material-symbols-outlined nav-icon">gavel</span><span class="nav-label">Defenses</span>
      <?php if ($upcomingCount > 0): ?><span class="nav-badge"><?= $upcomingCount ?></span><?php endif; ?>
    </a>
    <?php endif; ?>
    <?php if (can('score')): ?>
    <a href="<?= BASE_URL ?>/pages/scoring.php" class="nav-item <?= $activeNav==='scoring'?'active':'' ?>">
      <span class="material-symbols-outlined nav-icon">grading</span><span class="nav-label">Scoring</span>
    </a>
    <?php endif; ?>
    <?php if (can('view_results') || can('view_own_result')): ?>
    <a href="<?= BASE_URL ?>/pages/results.php" class="nav-item <?= $activeNav==='results'?'active':'' ?>">
      <span class="material-symbols-outlined nav-icon">workspace_premium</span><span class="nav-label">Results &amp; Grades</span>
      <?php if ($awaitingFinalize > 0 && can('view_results')): ?><span class="nav-badge"><?= $awaitingFinalize ?></span><?php endif; ?>
    </a>
    <?php endif; ?>

    <?php if (can('view_storage')): ?>
    <span class="nav-section-label">Repository</span>
    <a href="<?= BASE_URL ?>/pages/storage.php" class="nav-item <?= $activeNav==='storage'?'active':'' ?>">
      <span class="material-symbols-outlined nav-icon">folder_managed</span><span class="nav-label">File Storage</span>
    </a>
    <?php endif; ?>

    <span class="nav-section-label">System</span>
    <?php if (can('view_audit')): ?>
    <a href="<?= BASE_URL ?>/pages/audit.php" class="nav-item <?= $activeNav==='audit'?'active':'' ?>">
      <span class="material-symbols-outlined nav-icon">security</span><span class="nav-label">Audit Logs</span>
    </a>
    <?php endif; ?>
    <a href="<?= BASE_URL ?>/pages/api_docs.php" class="nav-item <?= $activeNav==='api'?'active':'' ?>">
      <span class="material-symbols-outlined nav-icon">api</span><span class="nav-label">API Reference</span>
    </a>
  </nav>

  <div class="sidebar-footer">
    <div class="user-card">
      <div class="user-avatar"><?= e($initials) ?></div>
      <div class="user-info">
        <div class="user-name"><?= e($myName) ?></div>
        <div class="user-role"><?= e($myRoleLabel) ?></div>
      </div>
      <?php if (AUTH_MODE !== 'gateway'): ?>
      <a href="<?= BASE_URL ?>/logout.php" class="tb-icon-btn" title="Sign out" style="margin-left:auto;"><span class="material-symbols-outlined">logout</span></a>
      <?php endif; ?>
    </div>
  </div>
</aside>

<!-- ══ MAIN AREA ════════════════════════════════════════════ -->
<div class="main-area">
  <div class="topbar">
    <div class="topbar-row1">
      <span class="tb-page-name"><?= e($pageTitle ?? 'Dashboard') ?></span>
      <div class="tb-search">
        <span class="material-symbols-outlined">search</span>
        <form method="GET" action="<?= BASE_URL ?>/pages/defenses/index.php" style="width:100%;">
          <input type="text" name="q" placeholder="Search defenses, students, references…"/>
        </form>
      </div>
      <div class="tb-actions">
        <a href="<?= BASE_URL ?>/pages/defenses/index.php?action=new" class="tb-icon-btn" title="Schedule Defense">
          <span class="material-symbols-outlined">add</span>
        </a>
        <a href="<?= BASE_URL ?>/pages/api_docs.php" class="tb-icon-btn" title="API Reference">
          <span class="material-symbols-outlined">api</span>
        </a>
      </div>
    </div>
    <div class="topbar-row2">
      <span class="material-symbols-outlined">home</span>
      <a href="<?= BASE_URL ?>/index.php">Home</a>
      <span class="material-symbols-outlined">chevron_right</span>
      <span style="color:var(--brand);font-weight:600;"><?= e($pageTitle ?? 'Dashboard') ?></span>
      <span style="margin-left:auto;color:var(--faint);">
        <?= date('l, d M Y') ?> &nbsp;·&nbsp; <span data-clock><?= date('H:i') ?></span> CAT
      </span>
    </div>
  </div>

  <div class="content">
<?php
$flash = $_SESSION['flash'] ?? null;
if ($flash) { unset($_SESSION['flash']); ?>
<div class="alert alert-<?= e($flash['type'] === 'err' ? 'error' : ($flash['type'] === 'ok' ? 'success' : $flash['type'])) ?>">
  <span class="material-symbols-outlined"><?= $flash['type']==='err'?'error':($flash['type']==='ok'?'check_circle':'info') ?></span>
  <?= e($flash['msg']) ?>
</div>
<?php } ?>
