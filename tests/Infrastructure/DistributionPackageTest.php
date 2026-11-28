<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/tools/DistributionPackage.php';

use PrestaShop\Module\Unipayment\Build\DistributionPackage;

$packageChecks = 0;

function assertPackage(bool $condition, string $message): void
{
    ++$GLOBALS['packageChecks'];
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectPackageFailure(callable $operation, string $message, ?string $expected = null): void
{
    try {
        $operation();
    } catch (RuntimeException $exception) {
        assertPackage($expected === null || str_starts_with($exception->getMessage(), $expected), $message . ': ' . $exception->getMessage());
        return;
    }
    assertPackage(false, $message);
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
    'dist/previous.zip', 'keys/private.pem', 'secrets/other-key.php',
    'config/environment.local.php', 'var/runtime.php', 'views/cache/private.php', 'src/.env',
    'src/test/Fixture.php', 'src/Service.tmp.php', 'bin/build-distribution.php',
] as $path) {
    assertPackage(!DistributionPackage::isRuntimePath($path), 'Development/secret path allowed: ' . $path);
}
foreach (['src/Security/SystemClock.php', 'controllers/front/shopcache.php', 'views/templates/hook/cart_calculator.tpl',
    'mails/bg/ordersend.html', 'keys/.htaccess', 'secrets/index.php', 'secrets/smartucf-key.php', 'config/services.yml', 'config/environment.php',
] as $path) {
    assertPackage(DistributionPackage::isRuntimePath($path), 'Runtime file excluded: ' . $path);
}

// Build invokes Composer install and publishes dist artifacts: outside safe consent regression.
if (getenv('UNIPAYMENT_TEST_SUITE') === 'safe') {
    fwrite(STDOUT, "OK ({$packageChecks} distribution static path/version checks)\n");
    fwrite(STDOUT, "SKIP (ZIP build requires separate packaging authorization; no dependency install in safe suite)\n");
    exit(0);
}


/** @param list<string> $command */
function packageCommand(array $command, string $cwd, ?array $environment = null): string
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd, $environment);
    assertPackage(is_resource($process), 'Starts fixture command: ' . $command[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    assertPackage(proc_close($process) === 0, 'Fixture command failed: ' . implode(' ', $command));

    return (string) $output;
}

function packageEnvironment(string $name, string|false $value): void
{
    assertPackage(putenv($value === false ? $name : $name . '=' . $value), 'Sets fixture ' . $name);
}

/** @return array<string, string> Relative file names and bytes. */
function packageTree(string $root, string $directory): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $entry) {
        if ($entry->isFile()) {
            $path = $entry->getPathname();
            $files[substr($path, strlen($root) + 1)] = file_get_contents($path);
        }
    }
    ksort($files);

    return $files;
}

/** Read classic ZIP file records directly and calculate CRC from the raw compressed payloads. */
function inspectPackage(string $raw, int $start, int $end): array
{
    $endOffset = strlen($raw) - 22;
    $footer = unpack('a4signature/x4/vdiskCount/vcount/Vsize/Voffset/vcomment', $raw, $endOffset);
    assertPackage($footer['signature'] === "PK\x05\x06" && $footer['comment'] === 0
        && $footer['diskCount'] === $footer['count'] && $footer['offset'] + $footer['size'] === $endOffset,
        'Complete classic ZIP central directory');

    $entries = $dosFields = [];
    $offset = $footer['offset'];
    for ($index = 0; $index < $footer['count']; ++$index) {
        $central = unpack('a4signature/x4/vflags/vmethod/a4dos/Vcrc/Vpacked/Vsize/vname/vextra/vcomment/x4/Vmode/Vlocal', $raw, $offset);
        $local = unpack('a4signature/x2/vflags/vmethod/a4dos/Vcrc/Vpacked/Vsize/vname/vextra', $raw, $central['local']);
        $name = substr($raw, $offset + 46, $central['name']);
        assertPackage($central['signature'] === "PK\x01\x02" && $local['signature'] === "PK\x03\x04"
            && $name === substr($raw, $central['local'] + 30, $local['name'])
            && str_starts_with($name, 'unipayment/') && !isset($entries[$name]), 'Unique entry under module root: ' . $name);
        assertPackage($central['flags'] === $local['flags'] && ($central['flags'] & 9) === 0
            && $central['method'] === ZipArchive::CM_DEFLATE && $central['method'] === $local['method']
            && ($central['mode'] >> 16) === 0100644 && $central['packed'] === $local['packed']
            && $central['size'] === $local['size'], 'ZIP file attributes and matching header sizes: ' . $name);
        $dataOffset = $central['local'] + 30 + $local['name'] + $local['extra'];
        $bytes = gzinflate(substr($raw, $dataOffset, $local['packed']));
        assertPackage(is_string($bytes) && strlen($bytes) === $central['size'] && $central['crc'] === $local['crc']
            && hash('crc32b', $bytes) === sprintf('%08x', $central['crc']), 'CRC-32 mismatch: ' . $name);
        $entries[$name] = $bytes;
        $dosFields[$name] = [$local['dos'], $central['dos']];
        $offset += 46 + $central['name'] + $central['extra'] + $central['comment'];
    }
    assertPackage($offset === $endOffset, 'Read every central directory record');

    $manifest = json_decode($entries['unipayment/package-manifest.json'], true, 512, JSON_THROW_ON_ERROR);
    $epoch = $manifest['source_date_epoch'];
    assertPackage(is_int($epoch) && $epoch % 2 === 0 && $epoch >= ($start - $start % 2)
        && $epoch <= ($end - $end % 2), 'Manifest epoch is in the build interval at DOS precision');
    $dosTime = (int) gmdate('G', $epoch) * 2048 + (int) gmdate('i', $epoch) * 32 + intdiv((int) gmdate('s', $epoch), 2);
    $dosDate = ((int) gmdate('Y', $epoch) - 1980) * 512 + (int) gmdate('n', $epoch) * 32 + (int) gmdate('j', $epoch);
    $dos = pack('vv', $dosTime, $dosDate);
    $hashes = [];
    foreach ($entries as $name => $bytes) {
        assertPackage($dosFields[$name] === [$dos, $dos], 'Local and central DOS times match the UTC manifest epoch: ' . $name);
        if ($name !== 'unipayment/package-manifest.json') {
            $hashes[substr($name, strlen('unipayment/'))] = hash('sha256', $bytes);
        }
    }
    assertPackage($hashes === $manifest['files'], 'Manifest contains every independently calculated SHA-256 in order');

    return [$entries, $manifest];
}

function buildPackageFixture(DistributionPackage $builder, string|false $timezone, string|false $override): array
{
    packageEnvironment('TZ', $timezone);
    packageEnvironment('SOURCE_DATE_EPOCH', $override);
    $start = time();
    $path = $builder->build();
    $end = time();
    assertPackage(getenv('TZ') === $timezone && date_default_timezone_get() === 'Pacific/Auckland',
        'Restores caller process and PHP timezone after build');
    assertPackage(getenv('SOURCE_DATE_EPOCH') === $override, 'Caller override is left intact');
    clearstatcache(true, $path);
    assertPackage((fileperms($path) & 0777) === 0600, 'Published fixture ZIP has mode 0600');
    assertPackage(glob(dirname($path) . '/.build-*') === [], 'Successful build removes staging');
    [$entries, $manifest] = inspectPackage(file_get_contents($path), $start, $end);

    return [$path, $entries, $manifest];
}

function extractPackageFixture(string $path, string $destination, string $timezone = 'UTC'): void
{
    assertPackage(mkdir($destination, 0700), 'Creates private extraction directory');
    $environment = getenv();
    unset($environment['UNZIP'], $environment['UNZIPOPT']);
    $environment['TZ'] = $timezone;
    packageCommand(['unzip', '-tqq', $path], $destination, $environment);
    packageCommand(['unzip', '-q', $path], $destination, $environment);
}

function packageAutoload(string $root): void
{
    packageCommand([PHP_BINARY, '-r', <<<'PHP'
$loader = require 'vendor/autoload.php';
if (!class_exists('PrestaShop\Module\Unipayment\Security\SystemClock')
    || $loader->findFile('PrestaShop\Module\Unipayment\Tests\Support\TestSuiteGuard') !== false) {
    exit(1);
}
PHP
    ], $root);
}

function checkPackageInputs(DistributionPackage $builder, array $entries, array $vendor): void
{
    $expected = [];
    foreach ($builder->sourceFiles() as $path => $absolute) {
        $bytes = file_get_contents($absolute);
        if ($path === 'composer.json') {
            $metadata = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            unset($metadata['require-dev'], $metadata['autoload-dev'], $metadata['scripts']);
            $bytes = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        }
        $expected['unipayment/' . $path] = $bytes;
    }
    foreach ($vendor as $path => $bytes) {
        $expected['unipayment/' . $path] = $bytes;
    }
    $expected['unipayment/package-manifest.json'] = $entries['unipayment/package-manifest.json'];
    ksort($expected);
    assertPackage(array_keys($entries) === array_keys($expected), 'Exact ordered source and generated ZIP inventory');
    foreach ($expected as $path => $bytes) {
        if ($path !== 'unipayment/package-manifest.json') {
            assertPackage($entries[$path] === $bytes, 'Packaged bytes match expected input: ' . $path);
        }
    }
}

function checkPackageFailure(string $root, string $testRoot, string $package): void
{
    $previous = file_get_contents($package);
    $builderCode = file_get_contents($root . '/tools/DistributionPackage.php');
    $cases = [
        ['Timestamp', false, '$zip->setMtimeName($name, $epoch)',
            '$zip->setMtimeName("unipayment/nonexistent.php", $epoch)', 'ZIP entry could not be written: '],
        ['Close', '', 'if (!$zip->close()) {',
            'unlink($module . "/unipayment.php"); if (!@$zip->close()) {', 'ZIP could not be finalized.'],
        ['Verify', 'America/New_York', '$count = $this->verify($temporaryZip);',
            'file_put_contents($temporaryZip, "synthetic invalid ZIP"); $count = $this->verify($temporaryZip);', 'ZIP is missing or invalid.'],
    ];
    foreach ($cases as [$name, $timezone, $before, $after, $error]) {
        $class = 'DistributionPackage' . $name . 'Fixture';
        $copy = str_replace('final class DistributionPackage', 'final class ' . $class, $builderCode);
        $copy = str_replace('if ($zip->open($temporaryZip,', "\\assertPackage(getenv('TZ') === 'UTC', 'Process timezone is UTC at ZIP open');\n"
            . '                if ($zip->open($temporaryZip,', $copy);
        $copy = str_replace('unset($zip);', "\\assertPackage(getenv('TZ') === 'UTC', 'Process timezone is UTC through ZIP cleanup');\n"
            . '                    unset($zip);', $copy);
        $copy = str_replace($before, $after, $copy, $replacements);
        assertPackage($replacements === 1, 'Private failure fixture has one injection point: ' . $name);
        $copyPath = $testRoot . '/' . $class . '.php';
        assertPackage(file_put_contents($copyPath, $copy) === strlen($copy), 'Writes private builder variant');
        require $copyPath;
        $qualified = 'PrestaShop\\Module\\Unipayment\\Build\\' . $class;
        packageEnvironment('TZ', $timezone);
        expectPackageFailure(static fn (): string => (new $qualified($root))->build(), 'Build must fail: ' . $name, $error);
        assertPackage(getenv('TZ') === $timezone && date_default_timezone_get() === 'Pacific/Auckland',
            'Restores caller process and PHP timezone after failure: ' . $name);
        assertPackage(file_get_contents($package) === $previous, 'Previous installer bytes preserved: ' . $name);
        assertPackage(glob($root . '/dist/.build-*') === [], 'Failed build removes staging: ' . $name);
    }
}

$checkout = dirname(__DIR__, 2);
$previousUmask = umask(0077);
$previousTimezone = getenv('TZ');
$previousOverride = getenv('SOURCE_DATE_EPOCH');
$previousPhpTimezone = date_default_timezone_get();
$testRoot = sys_get_temp_dir() . '/unipayment-package-test-' . bin2hex(random_bytes(8));
assertPackage(mkdir($testRoot, 0700), 'Creates private fixture root');
try {
    $root = $testRoot . '/source';
    packageCommand(['git', '-c', 'core.hooksPath=/dev/null', 'clone', '--quiet', '--local', '--no-hardlinks',
        '--no-tags', '--no-recurse-submodules', $checkout, $root], $testRoot);
    assertPackage(copy($checkout . '/tools/DistributionPackage.php', $root . '/tools/DistributionPackage.php'), 'Uses builder under test');
    assertPackage(file_put_contents($root . '/config/environment.php', "<?php return ['control_panel_url' => 'https://cp.example.invalid'];\n") > 0,
        'Creates synthetic deployment environment');
    assertPackage(file_put_contents($root . '/secrets/smartucf-key.php', "<?php return ['passphrase' => 'package-test-placeholder'];\n") > 0,
        'Creates synthetic deployment secret');
    date_default_timezone_set('Pacific/Auckland');

    $builder = new DistributionPackage($root);
    $head = trim(packageCommand(['git', 'rev-parse', 'HEAD'], $root));
    $relative = 'src/Security/SystemClock.php';
    $source = $root . '/' . $relative;
    $firstBytes = file_get_contents($source);
    $secondBytes = $firstBytes . "\n// Changed PHP bytes in the isolated packaging fixture.\n";
    $inputs = $builder->sourceFiles() + ['composer.lock' => $root . '/composer.lock'];
    $before = array_map(static fn (string $path): string => hash_file('sha256', $path), $inputs);

    assertPackage(!file_exists($root . '/vendor'), 'Fixture starts without source vendor');
    assertPackage(touch($source, 1009843200), 'Sets first historical source mtime');
    [$package, $firstEntries, $firstManifest] = buildPackageFixture($builder, false, false);
    $firstEpoch = $firstManifest['source_date_epoch'];
    assertPackage($firstEpoch !== 1009843200, 'Current epoch differs from the historical source mtime');
    assertPackage(!file_exists($root . '/vendor'), 'Build preserves absent source vendor');

    $firstExtracted = $testRoot . '/first';
    extractPackageFixture($package, $firstExtracted);
    packageAutoload($firstExtracted . '/unipayment');

    $composerEnvironment = getenv();
    foreach (array_keys($composerEnvironment) as $key) {
        if ($key === 'COMPOSER' || str_starts_with($key, 'COMPOSER_')) {
            unset($composerEnvironment[$key]);
        }
    }
    $composerEnvironment['COMPOSER_HOME'] = $testRoot . '/composer-home';
    $composerEnvironment['COMPOSER_CACHE_DIR'] = $testRoot . '/composer-cache';
    $composerEnvironment['COMPOSER_DISABLE_NETWORK'] = '1';
    packageCommand(['composer', 'install', '--no-dev', '--prefer-dist', '--optimize-autoloader',
        '--no-interaction', '--no-plugins', '--no-scripts'], $root, $composerEnvironment);
    $productionVendor = packageTree($root, 'vendor');
    foreach (['vendor/composer/autoload_classmap.php', 'vendor/composer/autoload_psr4.php'] as $path) {
        assertPackage(!empty($productionVendor[$path]), 'Composer generated nonempty source vendor file: ' . $path);
    }
    $productionVendor['vendor/.htaccess'] = file_get_contents($root . '/keys/.htaccess');
    checkPackageInputs($builder, $firstEntries, $productionVendor);
    assertPackage(file_put_contents($root . '/vendor/source-marker.txt', 'Private source-vendor marker') > 0, 'Writes source vendor marker');
    $sourceVendor = packageTree($root, 'vendor');

    assertPackage(file_put_contents($source, $secondBytes) === strlen($secondBytes) && touch($source, 1072915200),
        'Changes PHP bytes and sets a different historical source mtime');
    $deadline = hrtime(true) + 5_000_000_000;
    while (time() < $firstEpoch + 2 && hrtime(true) < $deadline) {
        usleep(20_000);
    }
    assertPackage(time() >= $firstEpoch + 2, 'Reaches the next DOS interval within five seconds');
    [$package, $secondEntries, $secondManifest] = buildPackageFixture($builder, '', '');
    $secondEpoch = $secondManifest['source_date_epoch'];
    assertPackage($secondEpoch > $firstEpoch && $secondEpoch !== 1072915200, 'Second archive has a new current timestamp');
    assertPackage(trim(packageCommand(['git', 'rev-parse', 'HEAD'], $root)) === $head, 'Successive fixtures use the same Git HEAD');
    assertPackage($firstManifest['source_git_sha'] === $head && $firstManifest['source_dirty'] === true, 'Manifest records the fixture HEAD and dirty state');
    assertPackage($firstEntries['unipayment/' . $relative] === $firstBytes
        && $secondEntries['unipayment/' . $relative] === $secondBytes && file_get_contents($source) === $secondBytes, 'Changed source and archived PHP bytes');
    $expectedManifest = $firstManifest;
    $expectedManifest['source_date_epoch'] = $secondEpoch;
    $expectedManifest['files'][$relative] = hash('sha256', $secondBytes);
    assertPackage($secondManifest === $expectedManifest, 'Only the epoch and changed PHP digest differ in the manifest');
    $expectedEntries = $firstEntries;
    $expectedEntries['unipayment/' . $relative] = $secondBytes;
    $expectedEntries['unipayment/package-manifest.json'] = $secondEntries['unipayment/package-manifest.json'];
    assertPackage($secondEntries === $expectedEntries, 'All unrelated archive payloads and entry order remain identical');
    checkPackageInputs($builder, $secondEntries, $productionVendor);
    assertPackage(!isset($secondEntries['unipayment/vendor/source-marker.txt']), 'Build excludes the local vendor marker');

    $secondExtracted = $testRoot . '/second';
    extractPackageFixture($package, $secondExtracted);
    packageAutoload($secondExtracted . '/unipayment');
    $firstPhp = $firstExtracted . '/unipayment/' . $relative;
    $secondPhp = $secondExtracted . '/unipayment/' . $relative;
    assertPackage(file_get_contents($firstPhp) === $firstBytes && filemtime($firstPhp) === $firstEpoch, 'First UTC extraction bytes and mtime');
    assertPackage(file_get_contents($secondPhp) === $secondBytes && filemtime($secondPhp) === $secondEpoch, 'Second UTC extraction bytes and mtime');

    $placement = $testRoot . '/placed/modules/unipayment/' . dirname($relative);
    assertPackage(mkdir($placement, 0700, true), 'Creates placement fixture');
    packageCommand(['cp', '-p', $firstPhp, 'preserved.php'], $placement);
    $copyStarted = time();
    packageCommand(['cp', $firstPhp, 'copied.php'], $placement);
    assertPackage(file_get_contents($placement . '/preserved.php') === $firstBytes && filemtime($placement . '/preserved.php') === $firstEpoch,
        'Timestamp-preserving placement keeps the extracted bytes and mtime');
    assertPackage(file_get_contents($placement . '/copied.php') === $firstBytes && filemtime($placement . '/copied.php') >= $copyStarted
        && filemtime($placement . '/copied.php') !== $firstEpoch, 'Ordinary copying keeps bytes and records a new filesystem mtime');

    $destination = $testRoot . '/destination-timezone';
    extractPackageFixture($package, $destination, 'Etc/GMT-3');
    assertPackage(filemtime($destination . '/unipayment/' . $relative) === $secondEpoch - 10800,
        'Destination UTC+3 interprets DOS fields as wall-clock time');

    foreach ([['UTC', 'invalid'], ['Europe/Sofia', '1577836801'], ['America/New_York', '1']] as [$timezone, $override]) {
        [$package, $entries, $manifest] = buildPackageFixture($builder, $timezone, $override);
        $expectedManifest['source_date_epoch'] = $manifest['source_date_epoch'];
        $expectedEntries['unipayment/package-manifest.json'] = $entries['unipayment/package-manifest.json'];
        assertPackage($manifest === $expectedManifest && $entries === $expectedEntries, 'Caller zone and override leave payloads and provenance unchanged');
    }

    $corrupt = file_get_contents($package);
    $central = unpack('V', $corrupt, strlen($corrupt) - 6)[1];
    $local = unpack('V', $corrupt, $central + 42)[1];
    $crc = pack('V', unpack('V', $corrupt, $central + 16)[1] ^ 1);
    $corrupt = substr_replace($corrupt, $crc, $central + 16, 4);
    $corrupt = substr_replace($corrupt, $crc, $local + 14, 4);
    expectPackageFailure(static fn (): array => inspectPackage($corrupt, 0, 0), 'Independent check must reject corrupt CRC', 'CRC-32 mismatch: ');

    $historicalDirectory = $testRoot . '/historical';
    assertPackage(mkdir($historicalDirectory, 0700), 'Creates historical package directory');
    $historical = $historicalDirectory . '/' . basename($package);
    assertPackage(copy($package, $historical), 'Copies archive for historical verifier fixture');
    packageEnvironment('TZ', 'UTC');
    $legacyZip = new ZipArchive();
    assertPackage($legacyZip->open($historical) === true, 'Opens historical fixture for timestamp replacement');
    $legacyEpoch = 1577836801;
    $manifest['source_date_epoch'] = $legacyEpoch;
    assertPackage($legacyZip->addFromString('unipayment/package-manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR)),
        'Historical manifest retains its odd epoch');
    for ($index = 0; $index < $legacyZip->numFiles; ++$index) {
        assertPackage($legacyZip->setMtimeIndex($index, $legacyEpoch), 'Sets historical timestamp for compatibility fixture');
    }
    assertPackage($legacyZip->close(), 'Finalizes historical archive');
    packageCommand([PHP_BINARY, $root . '/bin/verify-distribution.php', $historical], $root);

    checkPackageFailure($root, $testRoot, $package);
    assertPackage(packageTree($root, 'vendor') === $sourceVendor, 'Complete existing source vendor inventory and bytes preserved');
    $before[$relative] = hash('sha256', $secondBytes);
    foreach ($inputs as $path => $absolute) {
        assertPackage(hash_file('sha256', $absolute) === $before[$path], 'Build preserves source input: ' . $path);
    }
    assertPackage(file_get_contents($root . '/dist/.htaccess') === file_get_contents($root . '/keys/.htaccess'), 'Artifact directory denies HTTP access');

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

    $contractRoot = $testRoot . '/regressions';
    assertPackage(mkdir($contractRoot, 0700), 'Creates private regression directory');
    assertPackage($zip->extractTo($contractRoot), 'Verified package extracts');
    $zip->close();
    assertPackage(file_get_contents($contractRoot . '/unipayment/config/environment.php')
        === file_get_contents($root . '/config/environment.php'), 'Runtime environment copied byte-for-byte from source');
    assertPackage(file_get_contents($contractRoot . '/unipayment/secrets/smartucf-key.php')
        === file_get_contents($root . '/secrets/smartucf-key.php'), 'Deployment file copied byte-for-byte from source');
    $missingSource = $root . '/dist/.missing-deployment-source';
    mkdir($missingSource, 0700);
    expectPackageFailure(static fn (): array => (new DistributionPackage($missingSource))->sourceFiles(), 'Missing deployment source accepted', 'Deployment source is missing');
    mkdir($missingSource . '/secrets', 0700);
    symlink($root . '/secrets/smartucf-key.php', $missingSource . '/secrets/smartucf-key.php');
    expectPackageFailure(static fn (): array => (new DistributionPackage($missingSource))->sourceFiles(), 'Symlink deployment source accepted', 'Deployment source is missing');
    // Run production-packaged controllers with the same safe process fixture.
    foreach (['shopcache', 'smartucfdebuglog', 'orderbankstatus'] as $endpoint) {
        foreach (['normal', 'malformed', 'get-malformed'] as $case) {
            $process = proc_open([PHP_BINARY, $root . '/tests/Support/InboundApiProcess.php', $endpoint, $case, $contractRoot . '/unipayment'],
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
    foreach (['missing', 'tampered', 'environment', 'missing-secret', 'tampered-secret', 'dev', 'autoload', 'manifest', 'root', 'version'] as $case) {
        $directory = $contractRoot . '/' . $case;
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
            case 'missing-secret':
                $badZip->deleteName('unipayment/secrets/smartucf-key.php');
                break;
            case 'tampered-secret':
                $changed = "<?php return ['passphrase' => 'synthetic-test-value'];";
                $badZip->addFromString('unipayment/secrets/smartucf-key.php', $changed);
                $changedManifest = $manifest;
                $changedManifest['files']['secrets/smartucf-key.php'] = hash('sha256', $changed);
                $badZip->addFromString('unipayment/package-manifest.json', json_encode($changedManifest, JSON_THROW_ON_ERROR));
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
        assertPackage($badZip->close(), 'Finalizes invalid archive fixture');
        expectPackageFailure(static fn (): int => $builder->verify($candidate), 'Invalid package accepted: ' . $case);
    }
} finally {
    try {
        packageEnvironment('TZ', $previousTimezone);
        packageEnvironment('SOURCE_DATE_EPOCH', $previousOverride);
        date_default_timezone_set($previousPhpTimezone);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($testRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($testRoot);
    } finally {
        umask($previousUmask);
    }
}

fwrite(STDOUT, "UTC ZIP and extracted PHP epochs: {$firstEpoch}, {$secondEpoch}\n");
fwrite(STDOUT, "OK ({$packageChecks} packaging checks, including nine packaged inbound cases and ten archive rejection cases)\n");
