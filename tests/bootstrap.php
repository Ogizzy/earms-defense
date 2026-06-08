<?php
// tests/bootstrap.php — prepares an isolated test database and loads the app.
// Uses the same env vars as the app (EARMS_DB_*). Set EARMS_DB_NAME to a
// throwaway database; this bootstrap (re)creates it from the SQL files.

error_reporting(E_ALL & ~E_DEPRECATED);
define('EARMS_TEST', true);

$root = dirname(__DIR__);
require_once __DIR__ . '/TestCase.php';

// Force gateway auth in tests so domain functions run without a web session,
// and silence cookie/session warnings under CLI.
putenv('EARMS_AUTH_MODE=gateway');

// Resolve DB settings (default to a dedicated test DB).
$dbName = getenv('EARMS_DB_NAME') ?: 'earms_test';
putenv('EARMS_DB_NAME=' . $dbName);

$host   = getenv('EARMS_DB_HOST') ?: '127.0.0.1';
$user   = getenv('EARMS_DB_USER') ?: 'root';
$pass   = getenv('EARMS_DB_PASS');
$socket = getenv('EARMS_DB_SOCKET');

$dsnBase = $socket ? "mysql:unix_socket=$socket" : "mysql:host=$host";
try {
    $admin = new PDO($dsnBase, $user, $pass === false ? '' : $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    fwrite(STDERR, "Cannot connect to MySQL for tests: {$e->getMessage()}\n");
    fwrite(STDERR, "Set EARMS_DB_HOST/USER/PASS/SOCKET (and optionally EARMS_DB_NAME).\n");
    exit(2);
}

// Recreate the test database from scratch.
$admin->exec("DROP DATABASE IF EXISTS `$dbName`");
$admin->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$admin->exec("USE `$dbName`");

function run_sql_file(PDO $pdo, string $file): void {
    $sql = file_get_contents($file);
    // Strip comments, split on semicolons at line ends (sufficient for our DDL).
    $sql = preg_replace('/^--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', preg_split('/;\s*[\r\n]/', $sql))) as $stmt) {
        if ($stmt === '') continue;
        try { $pdo->exec($stmt); } catch (PDOException $e) {
            // FK re-adds etc. — surface only unexpected errors.
            if (!str_contains($e->getMessage(), 'Duplicate')) {
                fwrite(STDERR, "SQL warn: " . substr($stmt, 0, 60) . "… → {$e->getMessage()}\n");
            }
        }
    }
}
run_sql_file($admin, "$root/earms_schema.sql");
require_once __DIR__ . '/fixtures.php';
load_test_fixtures($admin);   // demo/test data (test-only)

// Now load the application (config/db/helpers/actions) against the test DB.
require_once "$root/config/actions.php";

function test_db(): PDO { return Database::connect(); }
