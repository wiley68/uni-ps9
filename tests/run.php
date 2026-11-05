<?php

declare(strict_types=1);

/**
 * UniPayment CLI test runner.
 *
 * Phase 0–6 provide configuration, API/auth, shop-cache, inbound signed API,
 * calculator domain, and product-page calculator contract tests.
 * The optional suite argument is accepted so later phases can reuse the same entry point.
 *
 * Usage:
 *   php tests/run.php
 *   php tests/run.php safe
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$suite = $argv[1] ?? 'safe';
$validSuites = ['safe', 'runtime', 'destructive', 'all'];

if (!in_array($suite, $validSuites, true)) {
    fwrite(STDERR, "FAIL: unknown suite \"{$suite}\"" . PHP_EOL);
    exit(1);
}

// The suite argument owns safety; inherited opt-ins cannot turn a safe run live.
if ($suite === 'safe') {
    foreach (array_keys(getenv()) as $key) {
        if (str_starts_with($key, 'UNIPAYMENT_')) {
            putenv($key);
        }
    }
}
putenv('UNIPAYMENT_TEST_SUITE=' . $suite);
$runtimeFiles = [
    'Api/LiveControlPanelSmokeTest.php',
    'Configuration/LiveShopCacheSmokeTest.php',
    'Infrastructure/ControlPanelOriginSchemaRuntimeTest.php',
    'Order/ControlPanelStatusSyncCasEmptyStringRuntimeTest.php',
    'SmartUcf/Aud002bLifecycleRepositoryTest.php',
    'Uninstall/Aud006ModuleDataPurgerDbTest.php',
];

/** @var list<string> $files */
$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $fileInfo) {
    if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
        continue;
    }

    $path = $fileInfo->getPathname();
    if (!str_ends_with($path, 'Test.php') && !str_ends_with($path, 'Test.js')) {
        continue;
    }

    $files[] = $path;
}

sort($files);

$passed = 0;
$skipped = 0;
$partial = 0;
$failed = 0;
$failures = [];

foreach ($files as $file) {
    $relative = str_replace(__DIR__ . DIRECTORY_SEPARATOR, '', $file);
    if ($suite === 'safe' && in_array($relative, $runtimeFiles, true)) {
        ++$skipped;
        fwrite(STDOUT, 'SKIP ' . $relative . ' (runtime; excluded before execution)' . PHP_EOL);
        continue;
    }
    $binary = str_ends_with($file, '.js') ? 'node' : PHP_BINARY;
    $command = escapeshellarg($binary) . ' ' . escapeshellarg($file);
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);
    $filtered = array_values(array_filter(
        $output,
        static function (string $line): bool {
            return !preg_match('/^(Deprecated:|Warning: Cannot modify header)/', $line);
        }
    ));
    $lastLine = $filtered !== [] ? (string) $filtered[array_key_last($filtered)] : '';

    if ($exitCode === 0 && ($lastLine === '' || str_starts_with($lastLine, 'OK') || str_starts_with($lastLine, 'SKIP'))) {
        $skipLines = array_values(array_filter($filtered, static fn (string $line): bool => str_starts_with($line, 'SKIP')));
        $okLines = array_values(array_filter($filtered, static fn (string $line): bool => str_starts_with($line, 'OK')));
        if ($skipLines !== [] && $okLines !== []) {
            ++$partial;
        } elseif ($skipLines !== []) {
            ++$skipped;
        } else {
            ++$passed;
        }
        foreach ($skipLines as $line) {
            fwrite(STDOUT, $relative . ': ' . $line . PHP_EOL);
        }
        continue;
    }

    ++$failed;
    $relative = str_replace(dirname(__DIR__) . DIRECTORY_SEPARATOR, '', $file);
    $failures[] = $relative . ': ' . ($lastLine !== '' ? $lastLine : 'exit ' . $exitCode);
}

fwrite(STDOUT, strtoupper($suite) . " suite: {$passed} passed, {$skipped} skipped, {$partial} partial, {$failed} failed" . PHP_EOL);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }
    exit(1);
}

exit(0);
