<?php
// config/database.php — EARMS Defense & Evaluation: Database Connection (MySQL / PDO)
// Mirrors the ORION-PAY connection pattern. Credentials can be overridden via env
// vars (EARMS_DB_HOST, EARMS_DB_NAME, EARMS_DB_USER, EARMS_DB_PASS) for deployment.

define('DB_HOST',    getenv('EARMS_DB_HOST') ?: 'localhost');
define('DB_NAME',    getenv('EARMS_DB_NAME') ?: 'earms_db');
define('DB_USER',    getenv('EARMS_DB_USER') ?: 'root');
define('DB_PASS',    getenv('EARMS_DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

class Database {
    private static ?PDO $instance = null;
    public static function connect(): PDO {
        if (self::$instance === null) {
            $sock = getenv('EARMS_DB_SOCKET');
            if ($sock) {
                $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $sock, DB_NAME, DB_CHARSET);
            } else {
                $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
            }
            self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            self::$instance->exec(
                "SET SESSION sql_mode = (SELECT REPLACE(REPLACE(@@SESSION.sql_mode,
                'ONLY_FULL_GROUP_BY,', ''), ',ONLY_FULL_GROUP_BY', ''))"
            );
        }
        return self::$instance;
    }
}
function db(): PDO { return Database::connect(); }
