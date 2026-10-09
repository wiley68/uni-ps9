<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/Support/ControlPanelDoubles.php';

use PrestaShop\Module\Unipayment\Api\ControlPanelClient;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Configuration\ModuleDeploymentEnvironment;
use PrestaShop\Module\Unipayment\Security\TokenRepository;
use PrestaShop\Module\Unipayment\Tests\Support\DeploymentEnvironmentFixture;

DeploymentEnvironmentFixture::activate();
$configuration = new ConfigurationRepository();
$unicid = '123e4567-e89b-12d3-a456-426614174000';
$configuration->save(true, $unicid, 'synthetic-merchant-secret');
foreach (['https://uni.avalonbg.com', 'https://cptest.ucfinonline.bg', 'https://cp.ucfinonline.bg', 'https://future-public.example'] as $origin) {
    DeploymentEnvironmentFixture::configure($origin);
    $transport = new CpCaptureTransport();
    $tokens = new TokenRepository();
    $tokens->invalidate();
    $client = new ControlPanelClient($configuration, $tokens, $transport, 'shop.example');
    $tokenData = ['access_token' => 'synthetic-token', 'token_type' => 'Bearer', 'expires_in' => 7200, 'shop' => ['unicid' => $unicid]];
    $meta = ['available' => true, 'certificate_sha256' => str_repeat('a', 64), 'private_key_sha256' => str_repeat('b', 64)];
    $transport->responses = [cpEnvelope($tokenData), cpEnvelope($tokenData), cpEnvelope(['unicid' => $unicid]),
        cpEnvelope(['id' => 71, 'shop_id' => 1, 'order_id' => 'REFERENCE', 'unicid' => $unicid, 'created_at' => '2026-10-09T00:00:00Z']),
        cpEnvelope(['order_id' => 'REFERENCE', 'id' => 71, 'shop_id' => 1, 'status_id' => 'cp_sent', 'status' => 'sent', 'updated_at' => '2026-10-09T00:00:00Z']),
        cpEnvelope($meta), cpEnvelope($meta + ['certificate_pem' => 'synthetic-cert', 'private_key_pem' => 'synthetic-key']), cpEnvelope(['logged_out' => true])];
    $client->login();
    $client->refreshToken();
    $client->getShop();
    $client->createOrder(['order_id' => 'REFERENCE']);
    $client->updateOrderStatus('REFERENCE', 'sent', 'cp_sent');
    $client->getSslCertificateMetadata();
    $client->downloadSslCertificateBundle();
    $client->logout();
    cpAssert(count($transport->requests) === 8, 'all CP operations exercised');
    foreach ($transport->requests as $request) { cpAssert(str_starts_with($request['url'], $origin . '/api/v1/'), 'independent API base divergence'); }
    cpRejects(static fn () => new ControlPanelClient($configuration, $tokens, $transport, 'shop.example', 'https://independent.example'), 'old origin override accepted');
    cpAssert((new ModuleDeploymentEnvironment('/tmp/independent-environment.php'))->controlPanelUrl() === $origin, 'no independent environment path authority');
}

// Wiring coverage complements behavioral client, orchestrator and lifecycle tests.
$root = dirname(__DIR__, 2);
$contexts = [
    'unipayment.php' => ['createControlPanelClient()', 'createShopConfigurationService()', 'get(true)', 'CurlHttpTransport()'],
    'controllers/front/shopcache.php' => ['new ControlPanelClient(', 'new CurlHttpTransport()'],
    'controllers/front/productpopup.php' => ['getControlPanelClient()'],
    'controllers/front/cartpopup.php' => ['getControlPanelClient()'],
    'controllers/front/validatecheckout.php' => ['getControlPanelClient()'],
    'src/Order/ControlPanelOrderClientAdapter.php' => ['$this->client->createOrder(', '$this->client->updateOrderStatus('],
    'src/Order/OrderOrchestrator.php' => ['ControlPanelOrigin::assertMatches', '$this->cp->createOrder('],
    'src/Order/ControlPanelSuccessReplayGuard.php' => ['ControlPanelOrigin::assertMatches'],
    'src/Order/ControlPanelStatusSyncService.php' => ['ControlPanelOrigin::assertMatches', '$this->cpClient->updateOrderStatus('],
    'src/SmartUcf/Certificate/CertificateSynchronizer.php' => ['$this->client->getSslCertificateMetadata()', '$this->client->downloadSslCertificateBundle()'],
    'src/Uninstall/ModuleDataPurger.php' => ['$this->controlPanelClient->logout()'],
];
foreach ($contexts as $file => $needles) {
    $source = file_get_contents($root . '/' . $file);
    foreach ($needles as $needle) { cpAssert(str_contains($source, $needle), 'CP resolver wiring: ' . $file); }
}
foreach (['src', 'controllers'] as $directory) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') { continue; }
        cpAssert(!preg_match('/(?:uni\.avalonbg\.com|cptest\.ucfinonline\.bg|cp\.ucfinonline\.bg)/i', file_get_contents($file->getPathname())), 'deployment hostname in runtime code');
    }
}
echo "OK (all CP operations share one deployment authority; runtime context wiring)\n";
