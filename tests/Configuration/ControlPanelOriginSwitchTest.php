<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/Support/ControlPanelDoubles.php';
require dirname(__DIR__) . '/fixtures/shop_snapshot.php';

use PrestaShop\Module\Unipayment\Api\ControlPanelClient;
use PrestaShop\Module\Unipayment\Api\Exception\TimeoutException;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Configuration\ControlPanelOrigin;
use PrestaShop\Module\Unipayment\Configuration\ImmediateShopConfigurationRefreshCoordinator;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationCache;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationService;
use PrestaShop\Module\Unipayment\Infrastructure\MutationBoundaryInterface;
use PrestaShop\Module\Unipayment\Security\TokenRepository;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCredentialRepository;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCredentialSettingStoreInterface;
use PrestaShop\Module\Unipayment\Tests\Support\DeploymentEnvironmentFixture;

DeploymentEnvironmentFixture::activate();
DeploymentEnvironmentFixture::configure('https://cp-a.example');

function pSQL(string $value, bool $htmlOk = false): string { return addslashes($value); }

final class Db
{
    public array $row = [];
    public function getRow(string $sql): array|false
    {
        if (str_contains($sql, 'AND `expires_at`') && strtotime($this->row['expires_at'] . ' UTC') <= time()) { return false; }
        return $this->row ?: false;
    }
    public function execute(string $sql): bool
    {
        // Test seam receives the real cache's escaped JSON INSERT.
        if (preg_match("/VALUES \\('[^']*', '((?:\\\\.|[^'])*)'/s", $sql, $match)) {
            $this->row = ['shop_data' => stripslashes($match[1]), 'fetched_at' => gmdate('Y-m-d H:i:s'), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400)];
        }
        return true;
    }
    public function delete(string $table, string $where = ''): bool { $this->row = []; return true; }
}

final class OriginSettings implements SmartUcfCredentialSettingStoreInterface
{
    public array $values = [];
    public function getPair(int $idShop): array { return ['user' => $this->get($idShop, SmartUcfCredentialRepository::USER_KEY), 'password' => $this->get($idShop, SmartUcfCredentialRepository::PASSWORD_KEY)]; }
    public function get(int $idShop, string $key): ?string { return $this->values[$idShop][$key] ?? null; }
    public function set(int $idShop, string $key, string $value): void { $this->values[$idShop][$key] = $value; }
    public function delete(int $idShop, string $key): void { unset($this->values[$idShop][$key]); }
    public function deleteByNameAllShops(string $key): void { foreach ($this->values as &$values) { unset($values[$key]); } }
}

$configuration = new ConfigurationRepository();
$unicid = '123e4567-e89b-12d3-a456-426614174000';
$configuration->save(true, $unicid, 'synthetic-merchant-secret');
$tokens = new TokenRepository();
$tokens->save('synthetic-token-a', 'Bearer', time() + 7200);
$tokenA = Configuration::$values[TokenRepository::ACCESS_TOKEN];
$settings = new OriginSettings();
$credentials = new SmartUcfCredentialRepository($settings, null, 1);
$credentials->saveCompletePair('synthetic-user-a', 'synthetic-password-a');
$pairA = $credentials->captureRawPair();
$database = new Db();
$cache = new ShopConfigurationCache($database);
$snapshotA = unipayment_valid_shop_snapshot();
unset($snapshotA['uni_user'], $snapshotA['uni_password']);
$cache->replace($unicid, $snapshotA);
cpAssert($cache->getFresh($unicid) === $snapshotA && $cache->getMetadata($unicid)['is_fresh'], 'matching fresh cache');
cpAssert($credentials->hydrateShopSnapshot($snapshotA)['uni_user'] === 'synthetic-user-a', 'matching credential hydration');
$cachedA = $database->row;
$transport = new CpCaptureTransport();
$clientA = new ControlPanelClient($configuration, $tokens, $transport, 'shop.example');

DeploymentEnvironmentFixture::configure('https://cp-b.example:443/');
cpAssert(ControlPanelOrigin::current() === 'https://cp-b.example', 'normalized effective HTTPS/443 origin');
cpAssert($tokens->getAccessToken() === null, 'CP A token rejected at CP B');
cpAssert($cache->getFresh($unicid) === null && $cache->getRetained($unicid) === null && $cache->getMetadata($unicid) === null, 'CP A cache and metadata rejected at CP B');
cpAssert(!$credentials->hasCompleteReadablePair() && !isset($credentials->hydrateShopSnapshot($snapshotA)['uni_user']), 'CP A credentials rejected');
cpAssert($credentials->captureRawPair() === $pairA && $database->row === $cachedA, 'mismatch preserves stored bytes');
cpRejects(static fn () => $clientA->getShop(), 'retained A client cannot send to B');
cpAssert($transport->requests === [], 'no retained-client HTTP');
$client = new ControlPanelClient($configuration, $tokens, $transport, 'shop.example');
$transport->responses = [cpEnvelope(['access_token' => 'synthetic-token-b', 'token_type' => 'Bearer', 'expires_in' => 7200, 'shop' => ['unicid' => $unicid]]), new TimeoutException('synthetic timeout')];
$boundary = new class implements MutationBoundaryInterface {
    public function runExclusive(string $lockName, callable $callback): mixed { return $callback(); }
};
$service = new ShopConfigurationService($configuration, $cache, $client, $tokens, null, $credentials, null, $boundary, new ImmediateShopConfigurationRefreshCoordinator());
$database->row['expires_at'] = gmdate('Y-m-d H:i:s', time() - 10);
cpRejects(static fn () => $service->get(), 'CP A LKG cannot mask a CP B timeout');
cpAssert(count($transport->requests) === 2 && str_ends_with($transport->requests[0]['url'], '/auth/login'), 'CP B login precedes fresh GET');
cpAssert(!isset($transport->requests[0]['headers']['Authorization']) && $transport->requests[1]['headers']['Authorization'] === 'Bearer synthetic-token-b', 'no old token sent once to B');
$transport->responses = [cpEnvelope(unipayment_valid_shop_snapshot(['uni_user' => 'synthetic-user-b', 'uni_password' => 'synthetic-password-b']))];
$snapshotB = $service->get();
cpAssert($snapshotB['cp_origin'] === ControlPanelOrigin::current() && $snapshotB['uni_user'] === 'synthetic-user-b', 'new B GET replaces cache and credential provenance');
cpAssert(!str_contains($database->row['shop_data'], 'synthetic-password-b'), 'cache excludes credentials');
$database->row['expires_at'] = gmdate('Y-m-d H:i:s', time() - 10);
$transport->responses = [new TimeoutException('synthetic timeout')];
cpAssert($service->get()['uni_user'] === 'synthetic-user-b', 'matching B LKG allowed on transient presentation failure');
cpAssert($cache->getFresh($unicid) === null, 'TTL unchanged');

// Legacy ephemeral state cannot become authoritative just by switching the file.
Configuration::$values[TokenRepository::ACCESS_TOKEN] = 'enc:v1:' . base64_encode('synthetic-legacy-token');
cpAssert($tokens->getAccessToken() === null, 'legacy encrypted plaintext token rejected');
$legacy = $snapshotA;
unset($legacy['cp_origin']);
$database->row['shop_data'] = json_encode($legacy, JSON_THROW_ON_ERROR);
$database->row['expires_at'] = gmdate('Y-m-d H:i:s', time() + 86400);
cpAssert($cache->getFresh($unicid) === null && $cache->getRetained($unicid) === null, 'legacy cache rejected');
$settings->values[1] = [SmartUcfCredentialRepository::USER_KEY => 'enc:v1:' . base64_encode('legacy-user'), SmartUcfCredentialRepository::PASSWORD_KEY => 'enc:v1:' . base64_encode('legacy-password')];
cpAssert(!$credentials->hasCompleteReadablePair() && !isset($credentials->hydrateShopSnapshot($snapshotB)['uni_user']), 'legacy pair rejected even with current snapshot');
$transport->responses = [cpEnvelope(['access_token' => 'synthetic-fresh-token', 'token_type' => 'Bearer', 'expires_in' => 7200, 'shop' => ['unicid' => $unicid]]), cpEnvelope(unipayment_valid_shop_snapshot())];
cpAssert($service->get()['cp_origin'] === ControlPanelOrigin::current(), 'legacy ephemeral state lazily replaced');
foreach ($transport->requests as $request) { cpAssert(str_starts_with($request['url'], 'https://cp-b.example/api/v1/'), 'every new operation uses B canonical base'); }
cpAssert(!str_contains(implode('\n', PrestaShopLogger::$messages), 'synthetic-password'), 'no credential logging');
echo "OK (token/cache/LKG/SmartUCF CP switch and legacy state)\n";
