<?php

declare(strict_types=1);

/**
 * AUD-022: ShopConfigurationService::getCachedOnly never contacts CP.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('_NEW_COOKIE_KEY_', 'test-key');

final class Configuration
{
    /** @var array<string, mixed> */
    public static $values = [];

    public static function updateValue(string $key, mixed $value): bool
    {
        self::$values[$key] = $value;

        return true;
    }

    public static function get(
        string $key,
        ?int $idLang = null,
        ?int $idShopGroup = null,
        ?int $idShop = null,
        mixed $default = false
    ): mixed {
        return self::$values[$key] ?? $default;
    }

    public static function deleteByName(string $key): bool
    {
        unset(self::$values[$key]);

        return true;
    }
}

final class PhpEncryption
{
    public function __construct(string $key)
    {
    }

    public function encrypt(string $plaintext): string
    {
        return base64_encode(strrev($plaintext));
    }

    /** @return string|false */
    public function decrypt(string $ciphertext)
    {
        $decoded = base64_decode($ciphertext, true);

        return is_string($decoded) ? strrev($decoded) : false;
    }
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/fixtures/shop_snapshot.php';

use PrestaShop\Module\Unipayment\Api\ShopConfigurationProviderInterface;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationCacheInterface;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationService;
use PrestaShop\Module\Unipayment\Security\TokenRepository;

function assertAud022(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class Aud022FailIfCalledProvider implements ShopConfigurationProviderInterface
{
    public int $calls = 0;

    public function getShop(): array
    {
        ++$this->calls;
        throw new RuntimeException('AUD-022: remote provider must not be called');
    }
}

final class Aud022MemoryCache implements ShopConfigurationCacheInterface
{
    /** @var array<string, array<string, mixed>> */
    public $fresh = [];

    /** @var array<string, array<string, mixed>> */
    public $staleOnly = [];

    public function getFresh(string $unicid): ?array
    {
        return $this->fresh[$unicid] ?? null;
    }

    public function replace(string $unicid, array $shopData): bool
    {
        $this->fresh[$unicid] = $shopData;

        return true;
    }

    public function delete(string $unicid): bool
    {
        unset($this->fresh[$unicid], $this->staleOnly[$unicid]);

        return true;
    }

    public function clear(): bool
    {
        $this->fresh = [];
        $this->staleOnly = [];

        return true;
    }

    public function getMetadata(string $unicid): ?array
    {
        if (isset($this->fresh[$unicid])) {
            return ['is_fresh' => true];
        }
        if (isset($this->staleOnly[$unicid])) {
            return ['is_fresh' => false];
        }

        return null;
    }
}

$unicid = '123e4567-e89b-12d3-a456-426614174000';
Configuration::$values = [];
$configuration = new ConfigurationRepository();
assertAud022($configuration->save(true, $unicid, 'secret'), 'stage credentials');

$provider = new Aud022FailIfCalledProvider();
$cache = new Aud022MemoryCache();
$service = new ShopConfigurationService($configuration, $cache, $provider, new TokenRepository());

$shop = unipayment_valid_shop_snapshot(['uni_status' => 1, 'uni_container_status' => 1, 'uni_zaglavie' => 'cached-ad']);

// A: fresh cached → returned, provider not called
$cache->fresh[$unicid] = $shop;
$cached = $service->getCachedOnly();
assertAud022(is_array($cached) && ($cached['uni_zaglavie'] ?? '') === 'cached-ad', 'A: fresh cache returned');
assertAud022($provider->calls === 0, 'A: provider not called');

// B: cache miss → null, provider not called
unset($cache->fresh[$unicid]);
assertAud022($service->getCachedOnly() === null, 'B: miss returns null');
assertAud022($provider->calls === 0, 'B: provider not called on miss');

// C: stale treated as miss by getFresh contract → null, no refresh
$cache->staleOnly[$unicid] = $shop;
assertAud022($service->getCachedOnly() === null, 'C: stale/unavailable fresh row → null');
assertAud022($provider->calls === 0, 'C: provider not called on stale');

// D: empty/malformed style via getFresh returning null already covered; empty array delete path
$cache->fresh[$unicid] = [];
// Memory fake returns [] which getCachedOnly would return — real getFresh deletes empty.
// Simulate real behavior: getFresh returns null for empty.
unset($cache->fresh[$unicid]);
assertAud022($service->getCachedOnly() === null, 'D: unavailable cache → null');
assertAud022($provider->calls === 0, 'D: provider not called');

// empty UNICID → null without provider
Configuration::$values = [];
$emptyConfig = new ConfigurationRepository();
$emptyService = new ShopConfigurationService($emptyConfig, $cache, $provider, new TokenRepository());
assertAud022($emptyService->getCachedOnly() === null, 'empty UNICID → null');
assertAud022($provider->calls === 0, 'empty UNICID does not call provider');

// G: explicit get()/refresh still can call provider
Configuration::$values = [];
$configuration = new ConfigurationRepository();
assertAud022($configuration->save(true, $unicid, 'secret'), 'restage credentials');
$liveProvider = new class implements ShopConfigurationProviderInterface {
    public int $calls = 0;

    public function getShop(): array
    {
        ++$this->calls;

        return ['data' => unipayment_valid_shop_snapshot(['uni_zaglavie' => 'from-cp'])];
    }
};
$liveCache = new Aud022MemoryCache();
$liveService = new ShopConfigurationService($configuration, $liveCache, $liveProvider, new TokenRepository());
$forced = $liveService->get(true);
assertAud022(($forced['uni_zaglavie'] ?? '') === 'from-cp', 'G: explicit refresh still works');
assertAud022($liveProvider->calls === 1, 'G: provider called on force refresh');

// Miss + get(false) still refreshes (non-advertising path preserved)
$liveCache2 = new Aud022MemoryCache();
$liveProvider2 = new class implements ShopConfigurationProviderInterface {
    public int $calls = 0;

    public function getShop(): array
    {
        ++$this->calls;

        return ['data' => unipayment_valid_shop_snapshot(['uni_zaglavie' => 'auto-refresh'])];
    }
};
$liveService2 = new ShopConfigurationService($configuration, $liveCache2, $liveProvider2, new TokenRepository());
$auto = $liveService2->get(false);
assertAud022(($auto['uni_zaglavie'] ?? '') === 'auto-refresh', 'G: get(false) still refreshes on miss');
assertAud022($liveProvider2->calls === 1, 'G: provider called on get miss');

fwrite(STDOUT, "OK (AUD-022 getCachedOnly network isolation)\n");
