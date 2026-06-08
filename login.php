<?php
// login.php — EARMS sign-in (standalone auth mode)
require_once __DIR__ . '/config/db.php';

// Gateway mode has no login; send users straight in.
if (AUTH_MODE === 'gateway') { header('Location: ' . BASE_URL . '/index.php'); exit; }
if (current_user()) { header('Location: ' . BASE_URL . '/index.php'); exit; }

$next = $_GET['next'] ?? ($_POST['next'] ?? (BASE_URL . '/index.php'));
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error = 'Your session expired. Please try again.';
    } else {
        $r = attempt_login(trim($_POST['email'] ?? ''), $_POST['password'] ?? '');
        if ($r['ok']) {
            $dest = (str_starts_with((string)$next, BASE_URL) || str_starts_with((string)$next, '/')) ? $next : BASE_URL . '/index.php';
            header('Location: ' . $dest); exit;
        }
        $error = $r['error'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Sign in — EARMS Defense</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700;9..40,800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
  <style>
    body{background:linear-gradient(135deg,#f0fdf4 0%,#f1f4f9 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;margin:0;padding:24px;}
    .login-wrap{width:100%;max-width:920px;display:grid;grid-template-columns:1.1fr 1fr;background:var(--surface);border-radius:20px;overflow:hidden;box-shadow:0 24px 60px rgba(22,163,74,.18);}
    .login-side{background:linear-gradient(160deg,var(--brand) 0%,var(--brand-dark) 100%);color:#fff;padding:42px 38px;display:flex;flex-direction:column;}
    .login-side .lg{display:flex;align-items:center;gap:12px;margin-bottom:32px;}
    .login-side .lg-ic{width:46px;height:46px;border-radius:12px;background:rgba(255,255,255,.18);display:grid;place-items:center;}
    .login-side h2{font-family:'DM Sans';font-size:26px;margin:0 0 12px;line-height:1.2;}
    .login-side p{font-size:13.5px;opacity:.9;line-height:1.6;}
    .login-feat{margin-top:auto;display:flex;flex-direction:column;gap:12px;}
    .login-feat div{display:flex;align-items:center;gap:10px;font-size:13px;opacity:.95;}
    .login-main{padding:42px 40px;}
    .login-main h1{font-family:'DM Sans';font-size:23px;margin:0 0 4px;}
    .login-main .sub{color:var(--muted);font-size:13px;margin-bottom:24px;}
    .demo-box{margin-top:18px;border:1.5px dashed var(--border);border-radius:12px;padding:12px 14px;font-size:12px;color:var(--muted);}
    .demo-box b{color:var(--text);}
    .demo-pick{cursor:pointer;color:var(--brand);}
    @media(max-width:760px){.login-wrap{grid-template-columns:1fr;}.login-side{display:none;}}
  </style>
</head>
<body>
<div class="login-wrap">
  <div class="login-side">
    <div class="lg"><div class="lg-ic"><span class="material-symbols-outlined">gavel</span></div><div><div style="font-family:'DM Sans';font-weight:700;font-size:18px;">EARMS</div><div style="font-size:12px;opacity:.85;">Defense &amp; Evaluation</div></div></div>
    <h2>Research Defense<br>Management</h2>
    <p>Schedule defenses, manage examiner panels, score against the institutional rubric, and publish verified results — all in one place.</p>
    <div class="login-feat">
      <div><span class="material-symbols-outlined">verified_user</span> Role-based, access-controlled</div>
      <div><span class="material-symbols-outlined">grading</span> Weighted rubric scoring</div>
      <div><span class="material-symbols-outlined">security</span> Full audit trail</div>
    </div>
  </div>
  <div class="login-main">
    <h1>Welcome back</h1>
    <div class="sub">Sign in to continue to the Defense &amp; Evaluation service.</div>
    <?php if ($error): ?><div class="alert alert-error"><span class="material-symbols-outlined">error</span><?= e($error) ?></div><?php endif; ?>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <div class="form-group"><label class="form-label">Email</label>
        <input type="email" name="email" id="email" class="form-control" placeholder="you@uni.edu" required autofocus value="<?= e($_POST['email'] ?? '') ?>"></div>
      <div class="form-group"><label class="form-label">Password</label>
        <input type="password" name="password" id="password" class="form-control" placeholder="••••••••" required></div>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:6px;"><span class="material-symbols-outlined">login</span>Sign in</button>
    </form>
  </div>
</div>
</body>
</html>
