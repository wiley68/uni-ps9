<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertModuleSkeleton(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$modulePath = $root . '/unipayment.php';
$composerPath = $root . '/composer.json';

assertModuleSkeleton(is_file($modulePath), 'unipayment.php must exist');
assertModuleSkeleton(is_file($composerPath), 'composer.json must exist');

$module = (string) file_get_contents($modulePath);
$composerJson = (string) file_get_contents($composerPath);
$composer = json_decode($composerJson, true);
assertModuleSkeleton(is_array($composer), 'composer.json must be valid JSON');

assertModuleSkeleton(
    (bool) preg_match('/\bclass\s+Unipayment\s+extends\s+PaymentModule\b/', $module),
    'module class must extend PaymentModule'
);
assertModuleSkeleton(
    (bool) preg_match('/\$this->name\s*=\s*[\'"]unipayment[\'"]/', $module),
    'technical name must be unipayment'
);
assertModuleSkeleton(
    (bool) preg_match('/\$this->version\s*=\s*[\'"][^\'"]+[\'"]/', $module),
    'module version must be present'
);
assertModuleSkeleton(
    (bool) preg_match('/[\'"]min[\'"]\s*=>\s*[\'"]9\.0\.0[\'"]/', $module),
    'PrestaShop minimum version must be 9.0.0'
);

assertModuleSkeleton(
    (bool) preg_match('/\$this->ps_versions_compliancy\s*=\s*\[(.*?)\]/s', $module, $compliancyMatch),
    'ps_versions_compliancy must be declared'
);
$compliancyBlock = $compliancyMatch[1];
assertModuleSkeleton(
    !str_contains($compliancyBlock, '_PS_VERSION_'),
    'ps_versions_compliancy max must not use _PS_VERSION_'
);
assertModuleSkeleton(
    (bool) preg_match('/[\'"]max[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/', $compliancyBlock, $maxMatch),
    'ps_versions_compliancy max must be an explicit version string'
);
assertModuleSkeleton(
    version_compare($maxMatch[1], '10.0.0', '<'),
    'ps_versions_compliancy max must not imply PrestaShop 10'
);

$psr4 = $composer['autoload']['psr-4'] ?? null;
assertModuleSkeleton(is_array($psr4), 'composer autoload psr-4 must be defined');
assertModuleSkeleton(
    ($psr4['PrestaShop\\Module\\Unipayment\\'] ?? null) === 'src/',
    'Composer namespace must map PrestaShop\\Module\\Unipayment\\ to src/'
);

assertModuleSkeleton(!is_dir($root . '/override'), 'override/ directory must not exist');

$frontControllers = glob($root . '/controllers/front/*.php') ?: [];
foreach ($frontControllers as $file) {
    assertModuleSkeleton(
        basename($file) === 'index.php',
        'no functional front controllers are allowed: ' . basename($file)
    );
}

$assetIterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/views', FilesystemIterator::SKIP_DOTS)
);
foreach ($assetIterator as $fileInfo) {
    if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
        continue;
    }
    $extension = strtolower($fileInfo->getExtension());
    assertModuleSkeleton(
        !in_array($extension, ['js', 'css'], true),
        'no functional JS/CSS files are allowed: ' . $fileInfo->getFilename()
    );
}

assertModuleSkeleton(
    !preg_match('/\bCREATE\s+TABLE\b/i', $module),
    'module entry must not install database tables'
);
assertModuleSkeleton(
    !preg_match('/\bnew\s+OrderState\b|\bOrderStateInstaller\b/i', $module),
    'module entry must not install custom order states'
);
assertModuleSkeleton(
    !preg_match('/\bregisterHook\s*\(/', $module),
    'module entry must not register functional hooks'
);
assertModuleSkeleton(
    !preg_match('/\bfunction\s+hook[A-Z]\w*/', $module),
    'module entry must not define functional hook handlers'
);

fwrite(STDOUT, "OK (module skeleton contract)\n");
