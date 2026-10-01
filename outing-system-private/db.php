<?php
// Single shared PDO connection. Credentials come from environment
// variables (set in your web server config / .env loader), never
// hardcoded in a file that could be uploaded, shared, or committed
// to version control by accident.

declare(strict_types=1);

// This app's "now" needs to mean Malaysia time everywhere, since
// curfew.php's whole job is comparing the current time against a
// wall-clock window. A server defaulting to UTC (very common) would
// see e.g. 8:25 AM MYT as 00:25 -- which falls inside a 23:00-06:00
// curfew window even though curfew has clearly ended locally.
//
// PHP's date()/time functions and MySQL's NOW()/CURRENT_TIMESTAMP are
// two INDEPENDENT clocks -- fixing only one still leaves the other
// wrong, and worse, leaves them disagreeing with each other (e.g. a
// checkout timestamp written by MySQL wouldn't match what PHP's
// is_within_curfew() thinks "now" is). Both are set right here, in
// the one file that's required first by literally everything else
// (auth.php requires this before anything runs, and the CLI seed
// scripts require it directly), so there's a single place this can
// ever be wrong.
//
// Malaysia doesn't observe daylight saving, so a fixed +08:00 offset
// for MySQL is accurate year-round and doesn't depend on the
// server's timezone tables being loaded (they often aren't, by
// default, on a fresh XAMPP install).
// Load outing-system-private/.env if present (real server env vars still win).
require_once __DIR__ . '/env_loader.php';
load_env(__DIR__ . '/.env');

date_default_timezone_set('Asia/Kuala_Lumpur');

function get_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $name = getenv('DB_NAME') ?: 'outing_system';
    $user = getenv('DB_USER') ?: "root";
    $pass = getenv('DB_PASS') ?: "";

    if ($user === false || $pass === false) {
        // Fail loudly in dev rather than silently connecting with
        // blank credentials.
        throw new RuntimeException('DB_USER / DB_PASS environment variables are not set.');
    }

    $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false, // real prepared statements
    ]);

    $pdo->exec("SET time_zone = '+08:00'");

    return $pdo;
}
