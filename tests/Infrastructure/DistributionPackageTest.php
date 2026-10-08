<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/tools/DistributionPackage.php';

use PrestaShop\Module\Unipayment\Build\DistributionPackage;

function assertPackage(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectPackageFailure(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (RuntimeException $exception) {
        return;
    }
    throw new RuntimeException($message);
}

foreach ([
    '<?php // $this->version = "9.9.9";' . "\n" . '$this->version = "2.0.3";',
    '<?php $this /* comment */ -> version = \'2.0.3\';',
] as $php) {
    assertPackage(DistributionPackage::resolveVersion($php) === '2.0.3', 'Literal version parsing');
}
foreach ([
    '<?php $this->version = getenv("VERSION");',
    '<?php $this->version = "2.0.3"; $this->version = "2.0.3";',
    '<?php $this->version = "2.0." . "3";',
    '<?php // $this->version = "2.0.3";',
    '<?php $this->version = "../../private";',
] as $php) {
    expectPackageFailure(static fn (): string => DistributionPackage::resolveVersion($php), 'Ambiguous version accepted');
}
foreach (['tests/Api/Test.php', 'docs/RELEASE.md', '.git/config', 'ps92-installed/unipayment.php',
    'dist/previous.zip', 'keys/private.pem', 'secrets/smartucf-key.php',
    'config/environment.local.php', 'var/runtime.php', 'views/cache/private.php', 'src/.env',
    'src/test/Fixture.php', 'src/Service.tmp.php', 'bin/build-distribution.php',
] as $path) {
    assertPackage(!DistributionPackage::isRuntimePath($path), 'Development/secret path allowed: ' . $path);
}
foreach (['src/Security/SystemClock.php', 'controllers/front/shopcache.php', 'views/templates/hook/cart_calculator.tpl',
    'mails/bg/ordersend.html', 'keys/.htaccess', 'secrets/index.php', 'config/services.yml', 'config/environment.php',
] as $path) {
    assertPackage(DistributionPackage::isRuntimePath($path), 'Runtime file excluded: ' . $path);
}

$root = dirname(__DIR__, 2);
$builder = new DistributionPackage($root);
$before = [];
foreach (['composer.json', 'composer.lock', 'config/environment.php', 'vendor/composer/autoload_classmap.php', 'vendor/composer/autoload_psr4.php'] as $path) {
    $before[$path] = hash_file('sha256', $root . '/' . $path);
}
$package = $builder->build();
$firstHash = hash_file('sha256', $package);
$builder->build();
assertPackage(hash_file('sha256', $package) === $firstHash, 'Identical inputs must produce identical ZIP bytes');
foreach ($before as $path => $hash) {
    assertPackage(hash_file('sha256', $root . '/' . $path) === $hash, 'Source tree mutated: ' . $path);
}

$zip = new ZipArchive();
assertPackage($zip->open($package) === true, 'Package opens');
$manifest = json_decode((string) $zip->getFromName('unipayment/package-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
assertPackage(basename($package) === DistributionPackage::artifactName($manifest['version']), 'Exact versioned artifact name');
assertPackage(count($manifest['files']) + 1 === $zip->numFiles, 'Complete SHA-256 inventory');
foreach (['src', 'controllers'] as $directory) {
    $runtime = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($runtime as $entry) {
        if ($entry->isFile() && $entry->getExtension() === 'php') {
            $relative = substr($entry->getPathname(), strlen($root) + 1);
            assertPackage(isset($manifest['files'][$relative]), 'Production PHP file omitted: ' . $relative);
        }
    }
}
$metadata = json_decode((string) $zip->getFromName('unipayment/composer.json'), true, 512, JSON_THROW_ON_ERROR);
assertPackage(!isset($metadata['autoload-dev']) && !isset($metadata['scripts']), 'Production metadata only');
assertPackage($zip->locateName('unipayment/composer.lock') === false, 'Source build lockfile not shipped after metadata normalization');

$testRoot = $root . '/dist/.package-test-' . bin2hex(random_bytes(8));
mkdir($testRoot, 0700);
try {
    assertPackage($zip->extractTo($testRoot), 'Verified package extracts');
    $zip->close();
    assertPackage(file_get_contents($testRoot . '/unipayment/config/environment.php')
        === file_get_contents($root . '/config/environment.php'), 'Runtime environment copied byte-for-byte from source');
    // Run production-packaged controllers with the same safe process fixture.
    foreach (['shopcache', 'smartucfdebuglog', 'orderbankstatus'] as $endpoint) {
        foreach (['normal', 'malformed', 'get-malformed'] as $case) {
            $process = proc_open([PHP_BINARY, $root . '/tests/Support/InboundApiProcess.php', $endpoint, $case, $testRoot . '/unipayment'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            assertPackage(is_resource($process), 'Packaged controller starts');
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            assertPackage(proc_close($process) === 0 && $errors === '', 'Packaged controller execution: ' . $errors);
            $result = json_decode((string) $output, true, 512, JSON_THROW_ON_ERROR);
            assertPackage($result['status'] === ($case === 'get-malformed' ? 405 : 401), 'Packaged inbound status');
            assertPackage($result['body']['error'] === ($case === 'get-malformed' ? 'method_not_allowed' : 'invalid_signature'), 'Packaged inbound envelope');
        }
    }
    foreach (['missing', 'tampered', 'environment', 'dev', 'autoload', 'manifest', 'root', 'version'] as $case) {
        $directory = $testRoot . '/' . $case;
        mkdir($directory, 0700);
        $candidate = $directory . '/' . basename($package);
        copy($package, $candidate);
        $badZip = new ZipArchive();
        assertPackage($badZip->open($candidate) === true, 'Negative-case ZIP opens');
        switch ($case) {
            case 'missing':
                $badZip->deleteName('unipayment/src/Security/SystemClock.php');
                break;
            case 'tampered':
                $changed = '<?php // changed';
                $badZip->addFromString('unipayment/src/Security/SystemClock.php', $changed);
                // Even a recomputed unsigned manifest must not bypass source parity.
                $changedManifest = $manifest;
                $changedManifest['files']['src/Security/SystemClock.php'] = hash('sha256', $changed);
                $badZip->addFromString('unipayment/package-manifest.json', json_encode($changedManifest, JSON_THROW_ON_ERROR));
                break;
            case 'dev':
                $badZip->addFromString('unipayment/tests/private.php', '<?php');
                break;
            case 'environment':
                $changed = "<?php return ['control_panel_url' => 'https://example.invalid'];";
                $badZip->addFromString('unipayment/config/environment.php', $changed);
                $changedManifest = $manifest;
                $changedManifest['files']['config/environment.php'] = hash('sha256', $changed);
                $badZip->addFromString('unipayment/package-manifest.json', json_encode($changedManifest, JSON_THROW_ON_ERROR));
                break;
            case 'autoload':
                $badZip->addFromString('unipayment/vendor/composer/autoload_psr4.php', "<?php // '/tests/Support'");
                break;
            case 'manifest':
                $badZip->deleteName('unipayment/package-manifest.json');
                break;
            case 'root':
                $badZip->addFromString('unexpected/index.php', '<?php');
                break;
            case 'version':
                $badZip->addFromString('unipayment/unipayment.php', '<?php $this->version = "99.99.99";');
                break;
        }
        $badZip->close();
        expectPackageFailure(static fn (): int => $builder->verify($candidate), 'Invalid package accepted: ' . $case);
    }
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($testRoot);
}

fwrite(STDOUT, "OK (repeatable production ZIP, source parity, nine packaged inbound cases and eight archive rejection cases)\n");
