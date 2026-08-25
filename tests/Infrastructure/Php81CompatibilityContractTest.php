<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

/**
 * Static PHP 8.1 production baseline guard.
 *
 * This is a contract check, not a substitute for an actual PHP 8.1 execution
 * lane. Syntax is parsed with the current interpreter (php -l). A small set of
 * high-signal tokens is used to reject known PHP 8.2+ language constructs.
 */

function assertPhp81Compatibility(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$composerPath = $root . '/composer.json';
assertPhp81Compatibility(is_file($composerPath), 'composer.json must exist');

$composer = json_decode((string) file_get_contents($composerPath), true);
assertPhp81Compatibility(is_array($composer), 'composer.json must be valid JSON');
assertPhp81Compatibility(
    ($composer['require']['php'] ?? null) === '>=8.1 <8.6',
    'composer.json must require PHP >=8.1 <8.6'
);

$skipDirectories = [
    $root . DIRECTORY_SEPARATOR . 'vendor',
    $root . DIRECTORY_SEPARATOR . 'tests',
];

$php82PlusPatterns = [
    'readonly class (PHP 8.2)' => '/\breadonly\s+class\b/',
    'readonly enum (PHP 8.2)' => '/\breadonly\s+enum\b/',
    '#[SensitiveParameter] (PHP 8.2)' => '/#\[(?:\\\\)?SensitiveParameter\b/',
    '#[Override] (PHP 8.3)' => '/#\[(?:\\\\)?Override\b/',
    'typed class constants (PHP 8.3)' => '/\b(?:public|protected|private)\s+const\s+(?:\\\\?[A-Za-z_][\w\\\\]*|int|float|string|bool|array|true|false|null|iterable|mixed|object)\s+[A-Z_][A-Z0-9_]*\s*=/',
    'asymmetric visibility (PHP 8.4)' => '/\b(?:public|protected|private)\((?:get|set)\)/',
    'new without parentheses (PHP 8.4)' => '/\bnew\s+[A-Za-z_\\\\][\w\\\\]*\s*->/',
];

$checked = 0;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $fileInfo) {
    if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
        continue;
    }
    if (strtolower($fileInfo->getExtension()) !== 'php') {
        continue;
    }

    $path = $fileInfo->getPathname();
    $skip = false;
    foreach ($skipDirectories as $skipDirectory) {
        if (str_starts_with($path, $skipDirectory . DIRECTORY_SEPARATOR)) {
            $skip = true;
            break;
        }
    }
    if ($skip) {
        continue;
    }

    $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path);
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);
    assertPhp81Compatibility(
        $exitCode === 0,
        'syntax check failed for ' . str_replace($root . DIRECTORY_SEPARATOR, '', $path)
    );

    $source = (string) file_get_contents($path);
    $source = preg_replace('!/\*.*?\*/!s', '', $source) ?? $source;
    $source = preg_replace('!//.*$!m', '', $source) ?? $source;
    $relative = str_replace($root . DIRECTORY_SEPARATOR, '', $path);

    foreach ($php82PlusPatterns as $label => $pattern) {
        assertPhp81Compatibility(
            !preg_match($pattern, $source),
            "{$relative} contains {$label}"
        );
    }

    ++$checked;
}

assertPhp81Compatibility($checked > 0, 'no production PHP files were checked');

fwrite(STDOUT, "OK (PHP 8.1 compatibility contract, {$checked} production files, interpreter " . PHP_VERSION . ")\n");
