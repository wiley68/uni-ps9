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

use PrestaShop\Module\Unipayment\Tests\Support\DeploymentEnvironmentFixture;
DeploymentEnvironmentFixture::activate();
$source = include dirname(__DIR__, 2) . '/config/environment.php';
$packaged = new ModuleDeploymentEnvironment();
assertEnv($packaged->controlPanelUrl() === \PrestaShop\Module\Unipayment\Api\ControlPanelDestinationPolicy::canonicalOrigin($source['control_panel_url']), 'source authority');
assertEnv($packaged->controlPanelApiBaseUrl() === $packaged->controlPanelUrl() . '/api/v1', 'derived API base');
DeploymentEnvironmentFixture::configure('https://future-public.example/');
assertEnv((new ModuleDeploymentEnvironment())->controlPanelApiBaseUrl() === 'https://future-public.example/api/v1', 'isolated deployment switch');
DeploymentEnvironmentFixture::remove();
$threw = false;
try { (new ModuleDeploymentEnvironment())->controlPanelUrl(); }
catch (RuntimeException $exception) { $threw = strpos($exception->getMessage(), 'missing or unreadable') !== false; }
assertEnv($threw, 'missing environment fails closed');
fwrite(STDOUT, "OK (module deployment environment)\n");
