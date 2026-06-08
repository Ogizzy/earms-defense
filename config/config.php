<?php
/* config/config.php — EARMS Defense & Evaluation Microservice configuration */

if (!defined('APP_NAME'))    define('APP_NAME',    'EARMS');
if (!defined('APP_FULL'))    define('APP_FULL',    'Electronic Academic Research Management System');
if (!defined('APP_MODULE'))  define('APP_MODULE',  'Defense & Evaluation Service');
if (!defined('APP_VERSION')) define('APP_VERSION', '1.0');

/* BASE_URL is derived at runtime so the service works on any host/port with no login. */
if (!defined('BASE_URL')) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
    // Project lives at web root; if served from a subfolder, set EARMS_BASE env.
    $base   = getenv('EARMS_BASE') ?: '/earms';
    define('BASE_URL', rtrim($scheme . '://' . $host . $base, '/'));
}

/* ── Authentication mode ──────────────────────────────────────────────
 * 'gateway'    : (DEFAULT) authentication is handled UPSTREAM by the EARMS
 *                IAM Service via the API Gateway. This service has NO login of
 *                its own — it trusts the identity the gateway forwards (see
 *                auth.php) and runs with role-based access on that identity.
 * 'standalone' : optional fallback — the service enforces its own login. Only
 *                use this when running the Defense service in isolation.
 * Override with the EARMS_AUTH_MODE environment variable. */
if (!defined('AUTH_MODE')) define('AUTH_MODE', getenv('EARMS_AUTH_MODE') ?: 'gateway');

/* Session lifetime (seconds) and login throttling */
if (!defined('SESSION_TTL'))       define('SESSION_TTL', 60 * 60 * 8);  // 8 hours
if (!defined('LOGIN_MAX_TRIES'))   define('LOGIN_MAX_TRIES', 5);
if (!defined('LOGIN_LOCK_SECONDS'))define('LOGIN_LOCK_SECONDS', 300);   // 5 min lockout

/* Gateway identity headers — the API Gateway forwards the authenticated user
 * via these headers. Names are configurable to match your gateway/IAM. */
if (!defined('GW_HDR_ID'))    define('GW_HDR_ID',    getenv('EARMS_GW_ID_HEADER')    ?: 'X-Auth-User-Id');
if (!defined('GW_HDR_ROLE'))  define('GW_HDR_ROLE',  getenv('EARMS_GW_ROLE_HEADER')  ?: 'X-Auth-User-Role');
if (!defined('GW_HDR_NAME'))  define('GW_HDR_NAME',  getenv('EARMS_GW_NAME_HEADER')  ?: 'X-Auth-User-Name');
if (!defined('GW_HDR_EMAIL')) define('GW_HDR_EMAIL', getenv('EARMS_GW_EMAIL_HEADER') ?: 'X-Auth-User-Email');

/* Fallback identity used only in 'gateway' mode when the API Gateway forwards
 * no identity headers (e.g. direct/local access). Neutral and configurable —
 * never a real person. The real user always comes from the gateway. */
if (!defined('CONTEXT_ACTOR')) define('CONTEXT_ACTOR', getenv('EARMS_DEFAULT_ACTOR') ?: 'EARMS User');
if (!defined('CONTEXT_ROLE'))  define('CONTEXT_ROLE',  getenv('EARMS_DEFAULT_ROLE')  ?: 'coordinator');

/* ── Role-based access control ────────────────────────────────────────
 * Capability map per role. Pages/actions check can('capability'). */
if (!defined('ROLE_LABELS')) define('ROLE_LABELS', [
    'coordinator'       => 'Project Coordinator',
    'supervisor'        => 'Supervisor',
    'internal_examiner' => 'Internal Examiner',
    'external_examiner' => 'External Examiner',
    'exam_officer'      => 'Examination Officer',
    'student'           => 'Student',
]);
if (!defined('ROLE_CAPABILITIES')) define('ROLE_CAPABILITIES', [
    'coordinator' => ['*'],  // full access
    'supervisor'        => ['view_defenses','view_defense','score','view_materials','upload_material','view_results','view_storage','view_audit'],
    'internal_examiner' => ['view_defenses','view_defense','score','view_materials','view_results'],
    'external_examiner' => ['view_defenses','view_defense','score','view_materials','view_results'],
    'exam_officer'      => ['view_defenses','view_defense','view_results','view_storage','view_audit'],
    'student'           => ['view_own_defense','view_materials','upload_material','view_own_result'],
]);

/* ── File upload constraints (real storage) ───────────────────────────*/
if (!defined('UPLOAD_DIR'))      define('UPLOAD_DIR', dirname(__DIR__) . '/storage_files');
if (!defined('MAX_UPLOAD_BYTES'))define('MAX_UPLOAD_BYTES', 200 * 1024 * 1024); // 200 MB
if (!defined('ALLOWED_UPLOAD_EXT')) define('ALLOWED_UPLOAD_EXT', [
    'pdf','doc','docx','ppt','pptx','xls','xlsx','csv','txt','md',
    'zip','png','jpg','jpeg','gif','mp4','webm','mov','ipynb',
]);

/* ── Evaluation policy ────────────────────────────────────────────────
 * Minimum number of submitted scores required before a defense may be
 * finalized (quorum). Also require the student marked present. */
if (!defined('SCORE_QUORUM'))         define('SCORE_QUORUM', 3);
if (!defined('REQUIRE_STUDENT_PRESENT')) define('REQUIRE_STUDENT_PRESENT', true);

/* Session start window: when enabled, a session can only be started within
 * SESSION_START_GRACE_MIN minutes before its scheduled time (and any time after).
 * Disabled by default so demo/seed defenses dated in the future can still start. */
if (!defined('ENFORCE_START_WINDOW'))   define('ENFORCE_START_WINDOW', filter_var(getenv('EARMS_ENFORCE_START_WINDOW'), FILTER_VALIDATE_BOOL));
if (!defined('SESSION_START_GRACE_MIN'))define('SESSION_START_GRACE_MIN', (int)(getenv('EARMS_START_GRACE_MIN') ?: 30));

/* Pagination */
if (!defined('PAGE_SIZE')) define('PAGE_SIZE', 15);

/* ── Notifications ────────────────────────────────────────────────────
 * MAIL_ENABLED: actually attempt PHP mail() delivery. On hosts without a
 * configured MTA, leave false — notifications are still recorded in-app and
 * shown to recipients, so nothing is lost. */
if (!defined('MAIL_ENABLED')) define('MAIL_ENABLED', filter_var(getenv('EARMS_MAIL_ENABLED'), FILTER_VALIDATE_BOOL));
if (!defined('MAIL_FROM'))     define('MAIL_FROM', getenv('EARMS_MAIL_FROM') ?: 'no-reply@earms.local');
if (!defined('MAIL_FROM_NAME'))define('MAIL_FROM_NAME', 'EARMS Defense & Evaluation');

/* ── Live meeting & recording ─────────────────────────────────────────
 * Virtual defenses use the WINGS conferencing service (host gets a moderated
 * room via the API; participants join by URL). Recording uses the browser
 * MediaRecorder API and uploads a real file.
 * Provider switch: 'wings' (default) or 'jitsi'. */
if (!defined('MEETING_PROVIDER')) define('MEETING_PROVIDER', getenv('EARMS_MEETING_PROVIDER') ?: 'wings');

/* WINGS API — set the key via the EARMS_WINGS_API_KEY env var in production. */
if (!defined('WINGS_API_BASE')) define('WINGS_API_BASE', getenv('EARMS_WINGS_API_BASE') ?: 'https://wings-api.bdic.ng/api/wings/v1');
if (!defined('WINGS_MEET_BASE')) define('WINGS_MEET_BASE', getenv('EARMS_WINGS_MEET_BASE') ?: 'https://meetings.bdic.ng');
if (!defined('WINGS_API_KEY'))  define('WINGS_API_KEY', getenv('EARMS_WINGS_API_KEY') ?: '');
if (!defined('WINGS_ROOM_PREFIX')) define('WINGS_ROOM_PREFIX', getenv('EARMS_WINGS_PREFIX') ?: 'EARMS');

/* Jitsi (fallback provider) */
if (!defined('JITSI_DOMAIN'))     define('JITSI_DOMAIN', getenv('EARMS_JITSI_DOMAIN') ?: 'meet.jit.si');
if (!defined('JITSI_ROOM_PREFIX'))define('JITSI_ROOM_PREFIX', getenv('EARMS_JITSI_PREFIX') ?: 'EARMS');

/* Defense modes & statuses */
if (!defined('DEFENSE_MODES')) define('DEFENSE_MODES', [
    'physical' => 'Physical',
    'virtual'  => 'Virtual',
]);
if (!defined('DEFENSE_STATUSES')) define('DEFENSE_STATUSES', [
    'scheduled' => 'Scheduled',
    'ongoing'   => 'Ongoing',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
]);

/* Participant / evaluator roles */
if (!defined('PARTICIPANT_ROLES')) define('PARTICIPANT_ROLES', [
    'student'            => 'Student',
    'supervisor'         => 'Supervisor',
    'internal_examiner'  => 'Internal Examiner',
    'external_examiner'  => 'External Examiner',
]);

/* Storage access levels (Storage microservice) */
if (!defined('ACCESS_LEVELS')) define('ACCESS_LEVELS', [
    'student_only'      => 'Student Only',
    'supervisor_only'   => 'Supervisor Only',
    'department_only'   => 'Department Only',
    'institution_wide'  => 'Institution Wide',
]);

/* Rubric — max marks per category, total 100 */
if (!defined('RUBRIC')) define('RUBRIC', [
    'content_quality'  => ['label' => 'Content Quality',   'max' => 30],
    'presentation'     => ['label' => 'Presentation',      'max' => 25],
    'originality'      => ['label' => 'Originality',        'max' => 25],
    'defense_response' => ['label' => 'Defense Responses',  'max' => 20],
]);

/* Weighting applied during aggregation (per evaluator role) */
if (!defined('SCORE_WEIGHTS')) define('SCORE_WEIGHTS', [
    'supervisor'        => 0.30,
    'internal_examiner' => 0.40,
    'external_examiner' => 0.30,
]);

/* Institutional grading policy → grade + pass/fail. Pass mark 50. */
if (!function_exists('gradeFromScore')) {
    function gradeFromScore(float $score): array
    {
        if ($score >= 70) return ['A', 'pass'];
        if ($score >= 60) return ['B', 'pass'];
        if ($score >= 50) return ['C', 'pass'];
        if ($score >= 45) return ['D', 'fail'];
        return ['F', 'fail'];
    }
}
