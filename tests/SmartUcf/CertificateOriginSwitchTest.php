<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/Support/ControlPanelDoubles.php';

use PrestaShop\Module\Unipayment\Api\ControlPanelClient;
use PrestaShop\Module\Unipayment\Api\Exception\AuthenticationException;
use PrestaShop\Module\Unipayment\Api\Exception\HttpException;
use PrestaShop\Module\Unipayment\Api\Exception\InvalidPayloadException;
use PrestaShop\Module\Unipayment\Api\Exception\TimeoutException;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Configuration\ControlPanelOrigin;
use PrestaShop\Module\Unipayment\Security\MtlsPrivateKeyPassphraseProvider;
use PrestaShop\Module\Unipayment\Security\TokenRepository;
use PrestaShop\Module\Unipayment\SmartUcf\Certificate\CertificateLocalStore;
use PrestaShop\Module\Unipayment\SmartUcf\Certificate\CertificatePairValidator;
use PrestaShop\Module\Unipayment\SmartUcf\Certificate\CertificateSynchronizer;
use PrestaShop\Module\Unipayment\SmartUcf\Certificate\CertificateSyncException;
use PrestaShop\Module\Unipayment\Tests\Support\DeploymentEnvironmentFixture;

function rejectCertificate(callable $operation): void
{
    try { $operation(); } catch (CertificateSyncException $exception) { return; }
    throw new RuntimeException('Unproven certificate state unexpectedly trusted.');
}

DeploymentEnvironmentFixture::activate();
DeploymentEnvironmentFixture::configure('https://cp-a.example');
$directory = sys_get_temp_dir() . '/unipayment-cert-origin-test-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
register_shutdown_function(static function () use ($directory): void {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($directory);
});
$phrase = 'synthetic-test-only-passphrase';
$validator = new CertificatePairValidator(new MtlsPrivateKeyPassphraseProvider(null, static fn (): array => ['passphrase' => $phrase]));
$opensslConfig = $directory . '/openssl.cnf';
file_put_contents($opensslConfig, "[req]\ndistinguished_name=dn\n[dn]\nCN=synthetic\n");
$opensslOptions = ['config' => $opensslConfig, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];
$key = openssl_pkey_new($opensslOptions);
cpAssert($key !== false, 'synthetic key generation');
$csr = openssl_csr_new(['commonName' => 'synthetic-certificate.example'], $key, $opensslOptions);
$certificate = openssl_csr_sign($csr, null, $key, 2, $opensslOptions);
cpAssert($certificate !== false && openssl_x509_export($certificate, $certPem) && openssl_pkey_export($key, $keyPem, $phrase, $opensslOptions), 'synthetic certificate export');
$meta = ['available' => true, 'ssl_revision' => 'synthetic-1', 'certificate_sha256' => hash('sha256', $certPem), 'private_key_sha256' => hash('sha256', $keyPem)];
$store = new CertificateLocalStore($directory, $validator);
$store->replacePair($certPem, $keyPem, $meta);
cpAssert($store->validateOriginBoundPair() !== null, 'A pair proven');
$statePath = $directory . '/' . CertificateLocalStore::STATE_FILENAME;
$stateA = file_get_contents($statePath);
$token = new TokenRepository();
$token->save('synthetic-token-a', 'Bearer', time() + 7200);
$transport = new CpCaptureTransport();
$client = new ControlPanelClient(new ConfigurationRepository(), $token, $transport, 'shop.example');
$sync = new CertificateSynchronizer($client, $store, $validator);
foreach ([new TimeoutException('synthetic timeout'), new HttpException(503, ['error' => 'synthetic_unavailable'])] as $exception) {
    $token->save('synthetic-token-a', 'Bearer', time() + 7200);
    $transport->responses = [$exception];
    $lease = $sync->ensureCurrent();
    cpAssert(file_get_contents($lease->certificatePath()) === $certPem, 'same-origin transient fail-open lease');
    $lease->release();
}
foreach ([new AuthenticationException('synthetic rejection'), new InvalidPayloadException('synthetic protocol failure'), new HttpException(403, ['error' => 'synthetic_forbidden'])] as $exception) {
    $token->save('synthetic-token-a', 'Bearer', time() + 7200);
    $transport->responses = [$exception];
    rejectCertificate(static fn () => $sync->ensureCurrent());
}
$token->save('synthetic-token-a', 'Bearer', time() + 7200);
$transport->responses = [cpEnvelope($meta)];
$before = count($transport->requests);
$lease = $sync->ensureCurrent();
cpAssert(count($transport->requests) === $before + 1, 'matching origin metadata avoids bundle download');
$lease->release();

DeploymentEnvironmentFixture::configure('https://cp-b.example');
$token->save('synthetic-token-b', 'Bearer', time() + 7200);
$clientB = new ControlPanelClient(new ConfigurationRepository(), $token, $transport, 'shop.example');
$syncB = new CertificateSynchronizer($clientB, $store, $validator);
cpAssert($store->validateLocalPair() !== null && $store->validateOriginBoundPair() === null, 'crypto validity does not establish B provenance');
$transport->responses = [new TimeoutException('synthetic timeout')];
rejectCertificate(static fn () => $syncB->ensureCurrent());
rejectCertificate(static fn () => $store->createConsumerPairLease());
cpAssert(file_get_contents($statePath) === $stateA && file_get_contents($store->privateKeyPath()) === $keyPem, 'foreign failure preserves files');
// Equal remote hashes alone cannot relabel old bytes; a new B bundle is required.
$transport->responses = [cpEnvelope($meta), cpEnvelope($meta + ['certificate_pem' => $certPem, 'private_key_pem' => $keyPem])];
$before = count($transport->requests);
$lease = $syncB->ensureCurrent();
cpAssert(count($transport->requests) === $before + 2 && str_ends_with($transport->requests[$before + 1]['url'], '/ssl/certificate/bundle'), 'B must fetch bundle even with equal A hashes');
$lease->release();
cpAssert(json_decode(file_get_contents($statePath), true)['cp_origin'] === ControlPanelOrigin::current() && $store->validateOriginBoundPair() !== null, 'B authenticated refresh establishes provenance');
$stateB = json_decode(file_get_contents($statePath), true);
$legacy = $stateB;
unset($legacy['cp_origin']);
file_put_contents($statePath, json_encode($legacy, JSON_THROW_ON_ERROR));
$transport->responses = [new TimeoutException('synthetic timeout')];
rejectCertificate(static fn () => $syncB->ensureCurrent());
cpAssert($store->validateOriginBoundPair() === null, 'legacy certificate metadata fails closed');
$stateB['private_key_sha256'] = str_repeat('0', 64);
file_put_contents($statePath, json_encode($stateB, JSON_THROW_ON_ERROR));
cpAssert($store->validateOriginBoundPair() === null, 'provenance bound to actual bytes');
cpAssert(!str_contains(implode('\n', PrestaShopLogger::$messages), $phrase) && !str_contains(implode('\n', PrestaShopLogger::$messages), 'PRIVATE KEY'), 'no passphrase or PEM logging');
echo "OK (certificate origin binding, same-origin transient fail-open, foreign/legacy denial)\n";
