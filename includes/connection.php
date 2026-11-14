<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/security.php';

loadEnvironment();

date_default_timezone_set(config('TIMEZONE', 'Africa/Lagos') ?? 'Africa/Lagos');

$host = config('DB_HOST', 'localhost') ?? 'localhost';
$port = (int) (config('DB_PORT', '3306') ?? '3306');
$name = requiredConfig('DB_NAME');
$user = config('DB_USERNAME', 'root') ?? 'root';
$password = config('DB_PASSWORD', '') ?? '';

// Do not allow failed writes to continue as apparent successes.
// MySQLi otherwise returns false for many SQL failures, which can result in
// a route returning HTTP 201 even though the invoice transaction was not saved.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($host, $user, $password, $name, $port);
    $conn->set_charset('utf8mb4');
    // One collation everywhere: tables use utf8mb4_unicode_ci (see migrations/20261114_unify_utf8mb4_collation.sql).
    // MySQL 8 otherwise gives connection values utf8mb4_0900_ai_ci, which it may refuse to mix
    // with column collations ("Illegal mix of collations"); MariaDB has no such default.
    $conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    // Keep MySQL's NOW()/CURRENT_TIMESTAMP in the same timezone as PHP's date()/time().
    // Session timestamps are written by both; if the database server runs in another
    // timezone (common on shared hosting: UTC), an active session looked idle for an
    // hour and users were signed out while working. A numeric offset (e.g. +01:00)
    // works on MySQL and MariaDB without timezone tables.
    $conn->query("SET time_zone = '" . (new DateTimeImmutable('now'))->format('P') . "'");
} catch (mysqli_sql_exception $exception) {
    error_log('Database connection failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'failed',
        'message' => 'Database connection unavailable.',
    ]);
    exit;
}
