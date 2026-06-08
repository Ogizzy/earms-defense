<?php
// config/helpers.php — EARMS Defense & Evaluation Shared Helpers
// All functions guarded with if(!function_exists) to prevent double-include fatals.

if (!function_exists('qval')) {
    function qval(PDO $db, string $sql, array $params = []) {
        $st = $db->prepare($sql); $st->execute($params); return $st->fetchColumn();
    }
}
if (!function_exists('qrows')) {
    function qrows(PDO $db, string $sql, array $params = []): array {
        $st = $db->prepare($sql); $st->execute($params); return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
if (!function_exists('qrow')) {
    function qrow(PDO $db, string $sql, array $params = []): ?array {
        $st = $db->prepare($sql); $st->execute($params);
        $r = $st->fetch(PDO::FETCH_ASSOC); return $r ?: null;
    }
}
if (!function_exists('e')) {
    function e(?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('generateRef')) {
    function generateRef(string $prefix = 'DEF'): string {
        return $prefix . '-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    }
}
if (!function_exists('fileUid')) {
    function fileUid(): string {
        return 'FILE-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
    }
}
if (!function_exists('humanSize')) {
    function humanSize(int $bytes): string {
        if ($bytes >= 1_073_741_824) return number_format($bytes / 1_073_741_824, 2) . ' GB';
        if ($bytes >= 1_048_576)     return number_format($bytes / 1_048_576, 1) . ' MB';
        if ($bytes >= 1024)          return number_format($bytes / 1024, 0) . ' KB';
        return $bytes . ' B';
    }
}
if (!function_exists('humanDuration')) {
    function humanDuration(int $sec): string {
        $h = intdiv($sec, 3600); $m = intdiv($sec % 3600, 60); $s = $sec % 60;
        if ($h) return sprintf('%dh %02dm', $h, $m);
        return sprintf('%dm %02ds', $m, $s);
    }
}

/* ── Status badge for defenses & related states ── */
if (!function_exists('statusBadge')) {
    function statusBadge(string $status): string {
        $map = [
            'scheduled'  => ['badge-pending',  'event',        'Scheduled'],
            'ongoing'    => ['badge-brand',    'sensors',      'Ongoing'],
            'completed'  => ['badge-active',   'check_circle', 'Completed'],
            'cancelled'  => ['badge-failed',   'cancel',       'Cancelled'],
            'present'    => ['badge-active',   'how_to_reg',   'Present'],
            'absent'     => ['badge-failed',   'person_off',   'Absent'],
            'pending'    => ['badge-pending',  'schedule',     'Pending'],
            'pass'       => ['badge-active',   'verified',     'Pass'],
            'fail'       => ['badge-failed',   'block',        'Fail'],
            'saved'      => ['badge-active',   'save',         'Saved'],
            'recording'  => ['badge-failed',   'fiber_manual_record', 'Recording'],
            'stopped'    => ['badge-inactive', 'stop_circle',  'Stopped'],
            'published'  => ['badge-active',   'campaign',     'Published'],
            'finalized'  => ['badge-brand',    'lock',         'Finalized'],
        ];
        $b = $map[$status] ?? ['badge-inactive', 'help', ucfirst($status)];
        return '<span class="badge ' . $b[0] . '"><span class="material-symbols-outlined">' . $b[1] . '</span>' . $b[2] . '</span>';
    }
}

/* ── Defense mode badge ── */
if (!function_exists('modeBadge')) {
    function modeBadge(string $mode): string {
        $map = [
            'virtual'  => ['badge-intl', 'videocam',      'Virtual'],
            'physical' => ['badge-bank', 'meeting_room',  'Physical'],
        ];
        $b = $map[$mode] ?? ['badge-inactive', 'help', ucfirst($mode)];
        return '<span class="badge ' . $b[0] . '"><span class="material-symbols-outlined">' . $b[1] . '</span>' . $b[2] . '</span>';
    }
}

/* ── Participant/evaluator role badge ── */
if (!function_exists('roleBadge')) {
    function roleBadge(string $role): string {
        $map = [
            'student'           => ['badge-brand', 'school',          'Student'],
            'supervisor'        => ['badge-mm',    'supervisor_account','Supervisor'],
            'internal_examiner' => ['badge-bank',  'rate_review',     'Internal Examiner'],
            'external_examiner' => ['badge-intl',  'public',          'External Examiner'],
        ];
        $b = $map[$role] ?? ['badge-inactive', 'person', ucwords(str_replace('_', ' ', $role))];
        return '<span class="badge ' . $b[0] . '"><span class="material-symbols-outlined">' . $b[1] . '</span>' . $b[2] . '</span>';
    }
}

/* ── Storage access-level badge ── */
if (!function_exists('accessBadge')) {
    function accessBadge(string $level): string {
        $map = [
            'student_only'     => ['badge-card',  'lock_person',   'Student Only'],
            'supervisor_only'  => ['badge-mm',    'supervisor_account', 'Supervisor Only'],
            'department_only'  => ['badge-bank',  'corporate_fare','Department Only'],
            'institution_wide' => ['badge-active','public',        'Institution Wide'],
        ];
        $b = $map[$level] ?? ['badge-inactive', 'help', ucwords(str_replace('_', ' ', $level))];
        return '<span class="badge ' . $b[0] . '"><span class="material-symbols-outlined">' . $b[1] . '</span>' . $b[2] . '</span>';
    }
}

/* ── File-type icon ── */
if (!function_exists('fileIcon')) {
    function fileIcon(string $type): string {
        $map = [
            'document'  => 'description', 'slides' => 'slideshow', 'dataset' => 'dataset',
            'recording' => 'movie',      'video'  => 'movie',     'feedback' => 'rate_review',
            'image'     => 'image',
        ];
        return $map[$type] ?? 'insert_drive_file';
    }
}

/* ── Weighted aggregate of evaluator scores (shared by API + UI) ── */
if (!function_exists('computeAggregate')) {
    function computeAggregate(PDO $db, int $defenseId): ?float {
        $rows = qrows($db,
            "SELECT evaluator_role, AVG(total) AS avg_total
             FROM defense_scores WHERE defense_id=? GROUP BY evaluator_role", [$defenseId]);
        if (!$rows) return null;
        $byRole = [];
        foreach ($rows as $r) $byRole[$r['evaluator_role']] = (float)$r['avg_total'];

        $sum = 0.0; $wsum = 0.0;
        foreach (SCORE_WEIGHTS as $role => $w) {
            if (isset($byRole[$role])) { $sum += $byRole[$role] * $w; $wsum += $w; }
        }
        return $wsum > 0 ? round($sum / $wsum, 2) : null;
    }
}

/* ── Audit logging ── */
if (!function_exists('audit')) {
    function audit(PDO $db, ?int $defenseId, string $action, string $detail = '', ?string $actor = null): void {
        if ($actor === null) {
            $actor = function_exists('actor_name') ? actor_name()
                   : (defined('CONTEXT_ACTOR') ? CONTEXT_ACTOR : 'System');
        }
        try {
            $db->prepare("INSERT INTO audit_logs (defense_id, action, detail, actor) VALUES (?,?,?,?)")
               ->execute([$defenseId, $action, $detail, $actor]);
        } catch (Exception $e) { /* silent */ }
    }
}

/* ── Real file storage ── */
if (!function_exists('store_uploaded_file')) {
    /* Validates and persists a $_FILES entry to UPLOAD_DIR.
     * Returns ['ok'=>true,'uid','stored_name','path','size','ext','checksum','client_name']
     * or ['ok'=>false,'error']. */
    function store_uploaded_file(array $file): array {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $map = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit',
                UPLOAD_ERR_FORM_SIZE => 'File too large',
                UPLOAD_ERR_PARTIAL => 'Upload was interrupted',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            ];
            return ['ok' => false, 'error' => $map[$file['error']] ?? 'Upload failed'];
        }
        if (($file['size'] ?? 0) > MAX_UPLOAD_BYTES) {
            return ['ok' => false, 'error' => 'File exceeds ' . humanSize(MAX_UPLOAD_BYTES) . ' limit'];
        }
        $client = (string)($file['name'] ?? 'file');
        $ext = strtolower(pathinfo($client, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, ALLOWED_UPLOAD_EXT, true)) {
            return ['ok' => false, 'error' => "File type .$ext is not allowed"];
        }
        if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0775, true);
        if (!is_writable(UPLOAD_DIR)) return ['ok' => false, 'error' => 'Storage directory is not writable'];

        $uid = fileUid();
        $stored = $uid . '.' . $ext;
        $dest = rtrim(UPLOAD_DIR, '/') . '/' . $stored;
        $tmp = $file['tmp_name'] ?? '';
        $moved = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $dest) : @rename($tmp, $dest);
        if (!$moved) return ['ok' => false, 'error' => 'Could not save file to storage'];

        return [
            'ok' => true, 'uid' => $uid, 'stored_name' => $stored,
            'path' => 'storage_files/' . $stored, 'size' => (int)($file['size'] ?? filesize($dest)),
            'ext' => $ext, 'checksum' => hash_file('sha256', $dest), 'client_name' => $client,
        ];
    }
}
if (!function_exists('stream_file')) {
    /* Streams a stored file to the browser as a download. */
    function stream_file(string $relPath, string $downloadName, ?string $mime = null): void {
        $abs = dirname(__DIR__) . '/' . ltrim($relPath, '/');
        $real = realpath($abs);
        $base = realpath(UPLOAD_DIR);
        // Path-traversal guard: file must live inside UPLOAD_DIR.
        if ($real === false || $base === false || !str_starts_with($real, $base) || !is_file($real)) {
            http_response_code(404); echo 'File not found'; exit;
        }
        $mime = $mime ?: (mime_content_type($real) ?: 'application/octet-stream');
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($real));
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($real);
        exit;
    }
}

/* ── Flash messages ── */
if (!function_exists('flash')) {
    function flash(string $msg, string $type = 'ok'): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
    }
}

/* ── JSON response helper for the API layer ── */
if (!function_exists('jsonOut')) {
    function jsonOut($data, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

/* ── Notifications ──
 * Records a notification (always) and attempts email delivery when enabled.
 * Recipients can be one user-id or an array; resolves email + name from users. */
if (!function_exists('notify')) {
    function notify(PDO $db, $userIds, string $event, string $subject, string $body, ?int $defenseId = null): int {
        $ids = array_values(array_filter(array_map('intval', (array)$userIds)));
        if (!$ids) return 0;
        $in = implode(',', array_fill(0, count($ids), '?'));
        $users = qrows($db, "SELECT id,name,email FROM users WHERE id IN ($in)", $ids);
        $sent = 0;
        foreach ($users as $u) {
            $personal = str_replace('{name}', $u['name'], $body);
            $status = 'queued';
            $sentAt = null;
            if (defined('MAIL_ENABLED') && MAIL_ENABLED && !empty($u['email'])) {
                $headers = 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . ">\r\n"
                         . "Content-Type: text/plain; charset=UTF-8\r\n";
                $ok = @mail($u['email'], $subject, $personal, $headers);
                $status = $ok ? 'sent' : 'failed';
                if ($ok) { $sentAt = date('Y-m-d H:i:s'); $sent++; }
            }
            $db->prepare("INSERT INTO notifications (defense_id,user_id,channel,recipient,subject,body,event,status,sent_at)
                          VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([$defenseId, $u['id'], 'email', $u['email'], $subject, $personal, $event, $status, $sentAt]);
        }
        return $sent;
    }
}

/* ── Pagination helper ──
 * Returns [offset, page, totalPages] given a total row count. */
if (!function_exists('paginate')) {
    function paginate(int $total, ?int $perPage = null): array {
        $perPage = $perPage ?: (defined('PAGE_SIZE') ? PAGE_SIZE : 15);
        $pages = max(1, (int)ceil($total / $perPage));
        $page  = max(1, min($pages, (int)($_GET['page'] ?? 1)));
        return ['offset' => ($page - 1) * $perPage, 'page' => $page, 'pages' => $pages, 'per' => $perPage];
    }
}
if (!function_exists('pager_html')) {
    function pager_html(array $pg, array $query = []): string {
        if ($pg['pages'] <= 1) return '';
        $mk = function($p) use ($query) {
            $query['page'] = $p;
            return '?' . http_build_query($query);
        };
        $h = '<div class="pagination">';
        $prev = $pg['page'] > 1 ? $mk($pg['page'] - 1) : null;
        $next = $pg['page'] < $pg['pages'] ? $mk($pg['page'] + 1) : null;
        $h .= $prev ? '<a class="page-btn" href="' . e($prev) . '">Prev</a>' : '<span class="page-btn" style="opacity:.4;">Prev</span>';
        for ($i = 1; $i <= $pg['pages']; $i++) {
            $h .= '<a class="page-btn ' . ($i === $pg['page'] ? 'active' : '') . '" href="' . e($mk($i)) . '">' . $i . '</a>';
        }
        $h .= $next ? '<a class="page-btn" href="' . e($next) . '">Next</a>' : '<span class="page-btn" style="opacity:.4;">Next</span>';
        return $h . '</div>';
    }
}
