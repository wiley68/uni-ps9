<?php

declare(strict_types=1);

/**
 * ModuleDeploymentEnvironment: single authoritative CP host from config/environment.php.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Configuration\ModuleDeploymentEnvironment;

function assertEnv(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$packaged = new ModuleDeploymentEnvironment();
assertEnv($packaged->controlPanelUrl() === 'https://uni.avalonbg.com', 'packaged host');
assertEnv($packaged->controlPanelApiBaseUrl() === 'https://uni.avalonbg.com/api/v1', 'packaged API base');

$tmp = sys_get_temp_dir() . '/unipayment-env-' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tmp, "<?php\nreturn ['control_panel_url' => 'https://test.example'];\n");
$switched = new ModuleDeploymentEnvironment($tmp);
assertEnv($switched->controlPanelApiBaseUrl() === 'https://test.example/api/v1', 'switch via one file');

$threw = false;
try {
    (new ModuleDeploymentEnvironment($tmp . '.missing'))->controlPanelUrl();
} catch (RuntimeException $exception) {
    $threw = strpos($exception->getMessage(), 'missing or unreadable') !== false;
}
assertEnv($threw, 'missing environment file fails closed');

@unlink($tmp);

fwrite(STDOUT, "OK (module deployment environment)\n");
