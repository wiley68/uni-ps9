<?php

declare(strict_types=1);

/**
 * REM-PS9-CACHE-001 isolated MySQL named-lock concurrency probe.
 *
 * Uses independent connections and /tmp IPC only. It never reads/writes shop tables
 * and never contacts Control Panel or SmartUCF.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Configuration\DbShopConfigurationRefreshCoordinator;

if (!function_exists('pSQL')) {
    function pSQL($string, $htmlOK = false)
    {
        return addslashes((string) $string);
    }
}

final class R1MysqliAdapter
{
    /** @var mysqli */
    private $connection;

    public function __construct()
    {
        $config = dirname(__DIR__, 4) . '/app/config/parameters.php';
        if (!is_file($config)) {
            throw new RuntimeException('PrestaShop database parameters are unavailable.');
        }
        /** @var array<string,mixed> $parameters */
        $parameters = require $config;
        $db = $parameters['parameters']['database_host'] ?? null;
        $user = $parameters['parameters']['database_user'] ?? null;
        $password = $parameters['parameters']['database_password'] ?? null;
        $port = (int) ($parameters['parameters']['database_port'] ?? 3306);
        if (!is_string($db) || !is_string($user) || !is_string($password)) {
            throw new RuntimeException('PrestaShop database parameters are invalid.');
        }
        $this->connection = new mysqli($db, $user, $password, '', $port);
        if ($this->connection->connect_errno !== 0) {
            throw new RuntimeException('Independent MySQL test connection failed.');
        }
    }

    /** @return mixed */
    public function getValue(string $sql)
    {
        $result = $this->connection->query($sql);
        if (!$result instanceof mysqli_result) {
            throw new RuntimeException('MySQL named-lock query failed.');
        }
        $row = $result->fetch_row();
        $result->free();

        return $row[0] ?? null;
    }

    public function close(): void
    {
        $this->connection->close();
    }
}

function r1Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function r1Write(string $path, string $value): void
{
    $handle = fopen($path, 'c+');
    if (!is_resource($handle)) {
        throw new RuntimeException('Cannot open IPC file.');
    }
    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Cannot lock IPC file.');
        }
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $value);
        fflush($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
}

function r1Read(string $path): string
{
    if (!is_file($path)) {
        return '';
    }
    $value = file_get_contents($path);

    return is_string($value) ? trim($value) : '';
}

function r1Increment(string $path): void
{
    $handle = fopen($path, 'c+');
    if (!is_resource($handle)) {
        throw new RuntimeException('Cannot open counter.');
    }
    try {
        flock($handle, LOCK_EX);
        rewind($handle);
        $value = (int) stream_get_contents($handle);
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) ($value + 1));
        fflush($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
}

function r1WaitFor(string $path, int $timeoutMs): void
{
    $deadline = microtime(true) + ($timeoutMs / 1000);
    while (!is_file($path)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('IPC barrier timeout: ' . basename($path));
        }
        usleep(10000);
    }
}

function r1WaitForResultCount(string $dir, int $expected, int $timeoutMs): void
{
    $deadline = microtime(true) + ($timeoutMs / 1000);
    do {
        $matches = glob($dir . '/result-*');
        if (is_array($matches) && count($matches) >= $expected) { return; }
        usleep(10000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Presentation contender barrier timeout.');
}

function r1Worker(array $argv): void
{
    $dir = (string) ($argv[2] ?? '');
    $scenario = (string) ($argv[3] ?? '');
    $index = (int) ($argv[4] ?? -1);
    $unicid = (string) ($argv[5] ?? '');
    $workers = (int) ($argv[6] ?? 0);
    r1Write($dir . '/ready-' . $index, '1');
    r1WaitFor($dir . '/go', 10000);

    $database = new R1MysqliAdapter();
    $coordinator = new DbShopConfigurationRefreshCoordinator($database);
    $eligiblePresentation = $scenario === 'stale-presentation';
    $lease = $coordinator->acquire($unicid, $eligiblePresentation ? 0 : 3);
    if ($lease === null) {
        r1Assert($eligiblePresentation, 'non-presentation contender timed out');
        r1Write($dir . '/result-' . $index, 'lkg');

        return;
    }

    try {
        if (r1Read($dir . '/state') === 'fresh') {
            r1Write($dir . '/result-' . $index, 'fresh');

            return;
        }
        r1Increment($dir . '/remote-count');
        if ($eligiblePresentation) {
            r1WaitForResultCount($dir, $workers - 1, 10000);
        } else {
            usleep(700000);
        }
        r1Write($dir . '/state', 'fresh');
        r1Write($dir . '/result-' . $index, 'owner');
    } finally {
        $lease->release();
    }
}

function r1SpawnWorkers(string $script, string $dir, string $scenario, int $workers, string $unicid): array
{
    $processes = [];
    for ($i = 0; $i < $workers; ++$i) {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script)
            . ' --worker ' . escapeshellarg($dir) . ' ' . escapeshellarg($scenario)
            . ' ' . $i . ' ' . escapeshellarg($unicid) . ' ' . $workers;
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        r1Assert(is_resource($process), 'worker could not start');
        $processes[] = [$process, $pipes];
    }

    for ($i = 0; $i < $workers; ++$i) {
        r1WaitFor($dir . '/ready-' . $i, 10000);
    }
    r1Write($dir . '/go', '1');

    $errors = [];
    foreach ($processes as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            $errors[] = trim((string) $stdout . ' ' . (string) $stderr);
        }
    }
    r1Assert($errors === [], 'worker failure: ' . implode('; ', $errors));

    return array_map(static function (int $i) use ($dir): string {
        return r1Read($dir . '/result-' . $i);
    }, range(0, $workers - 1));
}

function r1Scenario(string $script, string $base, string $scenario, int $workers): array
{
    $dir = $base . '/' . $scenario;
    mkdir($dir, 0700, true);
    r1Write($dir . '/state', $scenario === 'missing' ? 'missing' : $scenario);
    r1Write($dir . '/remote-count', '0');
    $unicid = 'r1-' . $scenario . '-scope';
    $results = r1SpawnWorkers($script, $dir, $scenario, $workers, $unicid);
    $count = (int) r1Read($dir . '/remote-count');
    r1Assert($count === 1, $scenario . ': expected exactly one remote fetch, got ' . $count);
    r1Assert(count(array_filter($results, static function (string $v): bool {
        return $v === 'owner';
    })) === 1, $scenario . ': expected exactly one owner');
    if ($scenario === 'stale-presentation') {
        r1Assert(!in_array('fresh', $results, true), 'presentation contenders unexpectedly waited');
        r1Assert(count(array_filter($results, static function (string $v): bool {
            return $v === 'lkg';
        })) === $workers - 1, 'presentation contenders did not all use LKG');
    } else {
        r1Assert(!in_array('lkg', $results, true), $scenario . ': strict contender used LKG');
        r1Assert(count(array_filter($results, static function (string $v): bool {
            return $v === 'fresh';
        })) === $workers - 1, $scenario . ': contenders did not re-read fresh state');
    }

    return ['workers' => $workers, 'remote' => $count];
}

function r1RemoveTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            r1RemoveTree($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

if (($argv[1] ?? '') === '--worker') {
    try {
        r1Worker($argv);
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}

if (!extension_loaded('mysqli') || !function_exists('proc_open')) {
    fwrite(STDERR, "FAIL: mysqli and proc_open are required." . PHP_EOL);
    exit(1);
}

$base = sys_get_temp_dir() . '/unipayment-r1-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($base, 0700, true);
$results = [];

try {
    $script = __FILE__;
    $results['stale presentation'] = r1Scenario($script, $base, 'stale-presentation', 20);
    $results['stale submission'] = r1Scenario($script, $base, 'stale-submission', 10);
    $results['missing'] = r1Scenario($script, $base, 'missing', 20);
    $results['too-old'] = r1Scenario($script, $base, 'too-old', 10);
    $results['corrupt'] = r1Scenario($script, $base, 'corrupt', 10);

    // Same key contention, different-key isolation, and non-owner release.
    $dbA = new R1MysqliAdapter();
    $dbB = new R1MysqliAdapter();
    $coordinatorA = new DbShopConfigurationRefreshCoordinator($dbA);
    $coordinatorB = new DbShopConfigurationRefreshCoordinator($dbB);
    $leaseA = $coordinatorA->acquire('scope-a', 0);
    r1Assert($leaseA !== null, 'connection A failed to acquire scope-a');
    r1Assert($coordinatorB->acquire('scope-a', 0) === null, 'same-scope contender acquired live lock');
    $leaseB = $coordinatorB->acquire('scope-b', 0);
    r1Assert($leaseB !== null, 'different UNICID was globally serialized');

    $lockA = 'unipay_shop_refresh_' . substr(hash('sha256', 'scope-a'), 0, 32);
    $nonOwnerRelease = $dbB->getValue("SELECT RELEASE_LOCK('" . pSQL($lockA) . "')");
    r1Assert((int) $nonOwnerRelease === 0, 'non-owner released owner lock');
    r1Assert($coordinatorB->acquire('scope-a', 0) === null, 'owner lock disappeared after non-owner release');
    $leaseB->release();
    $leaseA->release();

    // Connection-loss/crash recovery: closing the owning connection releases the named lock.
    $crashDb = new R1MysqliAdapter();
    $crashCoordinator = new DbShopConfigurationRefreshCoordinator($crashDb);
    $crashLease = $crashCoordinator->acquire('crash-scope', 0);
    r1Assert($crashLease !== null, 'crash owner did not acquire lock');
    $recoveryDb = new R1MysqliAdapter();
    $recoveryCoordinator = new DbShopConfigurationRefreshCoordinator($recoveryDb);
    r1Assert($recoveryCoordinator->acquire('crash-scope', 0) === null, 'live crash owner was bypassed');
    $crashDb->close();
    unset($crashLease, $crashCoordinator, $crashDb);
    $recovered = $recoveryCoordinator->acquire('crash-scope', 2);
    r1Assert($recovered !== null, 'connection loss did not release named lock');
    $recovered->release();
    $results['crash recovery'] = ['workers' => 2, 'remote' => 1];

    foreach ($results as $case => $result) {
        fwrite(STDOUT, sprintf("%-20s workers=%d remote_get=%d OK\n", $case, $result['workers'], $result['remote']));
    }
    fwrite(STDOUT, "cross-UNICID isolation OK\n");
    fwrite(STDOUT, "non-owner release protection OK\n");
    fwrite(STDOUT, "connection-loss recovery OK\n");
    fwrite(STDOUT, "OK (REM-PS9-CACHE-001 deterministic MySQL single-flight)\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
} finally {
    r1RemoveTree($base);
}
