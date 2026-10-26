<?php
/**
 * Gate 06 database guard diagnostics (no schema modification).
 * This script checks safe, non-secret boolean connection characteristics.
 * It does not run migrations, seeders, application tests or write SQL.
 */
declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

const EXPECTED_TEST_DB = 'manga_gate06_test';

function lineCheck(string $name, bool $passed): void
{
    echo $name.'='.($passed ? 'PASS' : 'FAIL').PHP_EOL;
}

function ipCategory($ip): string
{
    if ($ip === null || $ip === '') return 'null_or_unavailable';
    if ($ip === '127.0.0.1') return 'ipv4_loopback_expected';
    if ($ip === '::1') return 'ipv6_loopback';
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return strncmp($ip, '127.', 4) === 0 ? 'other_ipv4_loopback' : 'other_ipv4';
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) return 'other_ipv6';
    return 'unrecognized';
}

$platform = dirname(__DIR__);
$autoload = $platform.'/vendor/autoload.php';
if (!is_file($autoload)) {
    echo "LARAVEL_AUTOLOAD=FAIL\n";
    exit(2);
}
require $autoload;

try {
    $app = require $platform.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    echo "LARAVEL_BOOTSTRAP=PASS\n";
    lineCheck('CONFIG_CACHE_PRESENT', is_file($platform.'/bootstrap/cache/config.php'));

    $pgsql = config('database.connections.pgsql');
    $checks = [
        'CONFIG_DEFAULT_PGSQL' => config('database.default') === 'pgsql',
        'CONFIG_PGSQL_DEFINED' => is_array($pgsql),
        'CONFIG_TEST_DB' => is_array($pgsql) && ($pgsql['database'] ?? null) === EXPECTED_TEST_DB,
        'CONFIG_HOST_127' => is_array($pgsql) && ($pgsql['host'] ?? null) === '127.0.0.1',
        'CONFIG_PORT_5432' => is_array($pgsql) && (string)($pgsql['port'] ?? '') === '5432',
        'CONFIG_URL_EMPTY' => is_array($pgsql) && empty($pgsql['url'] ?? null),
    ];
    foreach ($checks as $key => $pass) lineCheck($key, $pass);
    if (in_array(false, $checks, true)) {
        echo "DIAGNOSTIC=STOP_CONFIG_GUARD (no database connection attempted)\n";
        exit(2);
    }

    $pdo = null;
    try {
        $pdo = DB::connection('pgsql')->getPdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        echo "DB_CONNECT=PASS\n";
        $pdo->beginTransaction();
        $pdo->exec('SET TRANSACTION READ ONLY');
        $row = $pdo->query("SELECT current_database() AS db_name, host(inet_server_addr()) AS ip, inet_server_port() AS port, current_setting('transaction_read_only') AS ro")
            ->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            echo "IDENTITY_QUERY=FAIL\n";
            exit(1);
        }
        echo "IDENTITY_QUERY=PASS\n";
        $dbOk = $row['db_name'] === EXPECTED_TEST_DB;
        $ipOk = $row['ip'] === '127.0.0.1';
        $portOk = (int)$row['port'] === 5432;
        $roOk = $row['ro'] === 'on';
        lineCheck('ACTUAL_DATABASE_NAME', $dbOk);
        lineCheck('ACTUAL_SERVER_IP', $ipOk);
        echo 'SERVER_IP_CATEGORY='.ipCategory($row['ip']).PHP_EOL;
        lineCheck('ACTUAL_SERVER_PORT_5432', $portOk);
        lineCheck('TRANSACTION_READ_ONLY', $roOk);
        echo 'DIAGNOSTIC='.(($dbOk && $ipOk && $portOk && $roOk) ? 'PASS_GUARD' : 'FAIL_GUARD_IDENTITY').PHP_EOL;
    } catch (Throwable $e) {
        echo 'DB_DIAGNOSTIC_EXCEPTION='.get_class($e).PHP_EOL;
        if ($e instanceof PDOException && !empty($e->errorInfo[0])) {
            echo 'SQLSTATE='.preg_replace('/[^A-Z0-9]/', '', (string)$e->errorInfo[0]).PHP_EOL;
        }
        echo "DIAGNOSTIC=FAIL_DB_EXECUTION (error message intentionally hidden)\n";
        exit(1);
    } finally {
        if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    }
} catch (Throwable $e) {
    echo 'BOOTSTRAP_EXCEPTION='.get_class($e).PHP_EOL;
    echo "DIAGNOSTIC=FAIL_BOOTSTRAP (error message intentionally hidden)\n";
    exit(1);
}
