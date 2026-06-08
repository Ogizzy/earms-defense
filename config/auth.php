<?php
// config/auth.php — Authentication, RBAC and CSRF for EARMS.
// In 'standalone' mode the service enforces its own login. In 'gateway' mode it
// trusts an upstream IAM/API Gateway and runs as a fixed coordinator context.

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    // Harden the session cookie.
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'httponly' => true,
        'samesite' => 'Lax', 'secure' => $secure,
    ]);
    session_start();
}

/* ── CSRF ─────────────────────────────────────────────────────────── */
if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf'];
    }
}
if (!function_exists('csrf_field')) {
    function csrf_field(): string {
        return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
    }
}
if (!function_exists('csrf_check')) {
    // Validates CSRF for state-changing requests. Returns true if OK.
    function csrf_check(): bool {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return true;
        $sent = $_POST['_csrf'] ?? '';
        return is_string($sent) && hash_equals($_SESSION['csrf'] ?? '', $sent);
    }
}

/* ── Current user / context ───────────────────────────────────────── */
if (!function_exists('current_user')) {
    function current_user(): ?array {
        static $cached = false; static $u = null;
        if ($cached) return $u;
        $cached = true;

        if (AUTH_MODE === 'gateway') {
            // Authentication happened upstream. The API Gateway forwards the
            // authenticated identity via headers (names configurable in config).
            // Honour them so role-based access reflects the real user.
            $hdr = function (string $name): ?string {
                // exact configured name, plus a couple of common aliases
                $names = [$name, str_replace('X-Auth-User-', 'X-User-', $name), str_replace('X-Auth-', 'X-', $name)];
                foreach (array_unique($names) as $n) {
                    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $n));
                    if (!empty($_SERVER[$key])) return $_SERVER[$key];
                }
                return null;
            };
            $gid   = $hdr(GW_HDR_ID);
            $grole = $hdr(GW_HDR_ROLE);
            $gname = $hdr(GW_HDR_NAME);
            $gmail = $hdr(GW_HDR_EMAIL);

            if ($gid !== null && ctype_digit((string)$gid)) {
                // Prefer the authoritative DB record for this forwarded id.
                $row = qrow(Database::connect(),
                    "SELECT id,name,email,role,department FROM users WHERE id=? AND is_active=1", [(int)$gid]);
                if ($row) { $u = $row; return $u; }
            }
            if ($grole !== null || $gname !== null || $gid !== null) {
                $u = ['id' => ($gid !== null && ctype_digit((string)$gid)) ? (int)$gid : 0,
                      'name' => $gname ?: ('User' . ($gid !== null ? " $gid" : '')),
                      'role' => $grole ?: CONTEXT_ROLE, 'email' => $gmail, 'department' => null];
                return $u;
            }
            // No gateway headers (e.g. direct/dev access): neutral default context.
            $u = ['id' => 0, 'name' => CONTEXT_ACTOR, 'role' => CONTEXT_ROLE,
                  'email' => null, 'department' => null];
            return $u;
        }
        if (!empty($_SESSION['uid'])) {
            $u = qrow(Database::connect(),
                "SELECT id,name,email,role,department,is_active FROM users WHERE id=?",
                [(int)$_SESSION['uid']]);
            if ($u && (int)$u['is_active'] !== 1) $u = null;
        }
        return $u;
    }
}
if (!function_exists('current_role')) {
    function current_role(): string { $u = current_user(); return $u['role'] ?? 'guest'; }
}
if (!function_exists('actor_name')) {
    function actor_name(): string { $u = current_user(); return $u['name'] ?? 'System'; }
}

/* ── Capability check ─────────────────────────────────────────────── */
if (!function_exists('can')) {
    function can(string $capability): bool {
        $role = current_role();
        $caps = ROLE_CAPABILITIES[$role] ?? [];
        return in_array('*', $caps, true) || in_array($capability, $caps, true);
    }
}

/* ── Guards ───────────────────────────────────────────────────────── */
if (!function_exists('require_login')) {
    function require_login(): void {
        if (AUTH_MODE === 'gateway') return;          // upstream handles it
        if (current_user()) return;
        $to = urlencode($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: ' . BASE_URL . '/login.php?next=' . $to);
        exit;
    }
}
if (!function_exists('require_can')) {
    function require_can(string $capability): void {
        require_login();
        if (!can($capability)) {
            http_response_code(403);
            $msg = 'You do not have permission to perform this action.';
            if (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json'
                || str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
                jsonOut(['success' => false, 'error' => $msg], 403);
            }
            flash($msg, 'err');
            header('Location: ' . BASE_URL . '/index.php');
            exit;
        }
    }
}

/* ── Login / logout ───────────────────────────────────────────────── */
if (!function_exists('attempt_login')) {
    function attempt_login(string $email, string $password): array {
        $db = Database::connect();

        // Throttle by IP.
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $key = 'login_fail_' . md5($ip);
        $fails = $_SESSION[$key] ?? ['n' => 0, 't' => 0];
        if ($fails['n'] >= LOGIN_MAX_TRIES && (time() - $fails['t']) < LOGIN_LOCK_SECONDS) {
            $wait = LOGIN_LOCK_SECONDS - (time() - $fails['t']);
            return ['ok' => false, 'error' => "Too many attempts. Try again in {$wait}s."];
        }

        $u = qrow($db, "SELECT * FROM users WHERE email=? AND is_active=1", [$email]);
        if (!$u || empty($u['password_hash']) || !password_verify($password, $u['password_hash'])) {
            $_SESSION[$key] = ['n' => $fails['n'] + 1, 't' => time()];
            return ['ok' => false, 'error' => 'Invalid email or password.'];
        }

        // Success — reset throttle, rotate session id.
        unset($_SESSION[$key]);
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        $db->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([$u['id']]);
        audit($db, null, 'auth.login', "User {$u['name']} signed in", $u['name']);
        return ['ok' => true, 'user' => $u];
    }
}
if (!function_exists('logout')) {
    function logout(): void {
        $u = current_user();
        if ($u) audit(Database::connect(), null, 'auth.logout', "User {$u['name']} signed out", $u['name']);
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }
}
