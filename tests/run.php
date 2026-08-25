<?php

declare(strict_types=1);

/**
 * UniPayment CLI test runner.
 *
 * Phase 0–5 provide configuration, API/auth, shop-cache, inbound signed API, and calculator domain contract tests.
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
$validSuites = ['safe', 'all'];

if (!in_array($suite, $validSuites, true)) {
    fwrite(STDERR, "FAIL: unknown suite \"{$suite}\"" . PHP_EOL);
    exit(1);
}

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
    if (!str_ends_with($path, 'Test.php')) {
        continue;
    }

    $files[] = $path;
}

sort($files);

$passed = 0;
$failed = 0;
$failures = [];

foreach ($files as $file) {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file);
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
        ++$passed;
        continue;
    }

    ++$failed;
    $relative = str_replace(dirname(__DIR__) . DIRECTORY_SEPARATOR, '', $file);
    $failures[] = $relative . ': ' . ($lastLine !== '' ? $lastLine : 'exit ' . $exitCode);
}

fwrite(STDOUT, strtoupper($suite) . " suite: {$passed} passed, {$failed} failed" . PHP_EOL);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }
    exit(1);
}

exit(0);
