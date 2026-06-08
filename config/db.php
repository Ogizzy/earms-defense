<?php
// config/db.php — EARMS Defense & Evaluation bootstrap
// Brings together configuration, database, helpers and authentication.
// In 'standalone' mode a login is enforced per-page via require_login(); in
// 'gateway' mode auth is delegated to the upstream IAM Service / API Gateway.

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';   // starts the (hardened) session too
require_once __DIR__ . '/wings.php';  // conferencing provider

if (!function_exists('getDB')) {
    function getDB(): PDO { return Database::connect(); }
}

// Reject any POST that fails CSRF validation (UI forms include csrf_field()).
// The JSON API uses its own token-less surface and is excluded here.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && !str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')
    && !csrf_check()) {
    http_response_code(419);
    if (function_exists('flash')) flash('Your session expired. Please try again.', 'err');
    $ref = $_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/index.php');
    header('Location: ' . $ref);
    exit;
}
