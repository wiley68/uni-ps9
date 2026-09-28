<?php

declare(strict_types=1);

/**
 * REM-PS9-CACHE-001: homepage presentation uses the shared lazy resolver.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('_NEW_COOKIE_KEY_', 'test-key');

final class Configuration
{
    /** @var array<string, mixed> */
    public static $values = [];

    public static function updateValue(string $key, mixed $value, bool $html = false, $idShopGroup = null, $idShop = null): bool
    {
        unset($html, $idShopGroup, $idShop);
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
use PrestaShop\Module\Unipayment\Tests\Support\ShopConfigurationCredentialWiring;

function assertAud022(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class Aud022ObservedProvider implements ShopConfigurationProviderInterface
{
    public int $calls = 0;

    public function getShop(): array
    {
        ++$this->calls;
        throw new RuntimeException('Observed presentation refresh failure');
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

$provider = new Aud022ObservedProvider();
$cache = new Aud022MemoryCache();
[$service] = ShopConfigurationCredentialWiring::service($configuration, $cache, $provider, new TokenRepository());

$shop = unipayment_valid_shop_snapshot(['uni_status' => 1, 'uni_container_status' => 1, 'uni_zaglavie' => 'cached-ad']);

// A: fresh cached → returned, provider not called
$cache->fresh[$unicid] = $shop;
$cached = $service->getForPresentationWithoutCredentials();
assertAud022(is_array($cached) && ($cached['uni_zaglavie'] ?? '') === 'cached-ad', 'A: fresh cache returned');
assertAud022(!array_key_exists('uni_user', $cached), 'A: cached-only strips credentials');
assertAud022($provider->calls === 0, 'A: provider not called');

// B: cache miss → shared resolver attempts CP and fails closed.
unset($cache->fresh[$unicid]);
try {
    $service->getForPresentationWithoutCredentials();
    assertAud022(false, 'B: miss unexpectedly resolved');
} catch (RuntimeException $exception) {
    assertAud022($provider->calls === 1, 'B: provider not called on miss');
}

// C: stale follows the same remote-capable resolver.
$cache->staleOnly[$unicid] = $shop;
try {
    $service->getForPresentationWithoutCredentials();
    assertAud022(false, 'C: stale unexpectedly resolved');
} catch (RuntimeException $exception) {
    assertAud022($provider->calls === 2, 'C: provider not called on stale');
}

// D: empty/malformed style via getFresh returning null already covered; empty array delete path
$cache->fresh[$unicid] = [];
// Real getFresh deletes an empty row; simulate that unavailable state here.
// Simulate real behavior: getFresh returns null for empty.
unset($cache->fresh[$unicid]);
try {
    $service->getForPresentationWithoutCredentials();
    assertAud022(false, 'D: unavailable cache unexpectedly resolved');
} catch (RuntimeException $exception) {
    assertAud022($provider->calls === 3, 'D: provider not called');
}

// empty UNICID → null without provider
Configuration::$values = [];
$emptyConfig = new ConfigurationRepository();
[$emptyService] = ShopConfigurationCredentialWiring::service($emptyConfig, $cache, $provider, new TokenRepository());
try {
    $emptyService->getForPresentationWithoutCredentials();
    assertAud022(false, 'empty UNICID unexpectedly resolved');
} catch (\PrestaShop\Module\Unipayment\Api\Exception\AuthenticationException $exception) {
    assertAud022($provider->calls === 3, 'empty UNICID must not call provider');
}

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
[$liveService] = ShopConfigurationCredentialWiring::service(
    $configuration,
    $liveCache,
    $liveProvider,
    new TokenRepository()
);
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
[$liveService2] = ShopConfigurationCredentialWiring::service(
    $configuration,
    $liveCache2,
    $liveProvider2,
    new TokenRepository()
);
$auto = $liveService2->get(false);
assertAud022(($auto['uni_zaglavie'] ?? '') === 'auto-refresh', 'G: get(false) still refreshes on miss');
assertAud022($liveProvider2->calls === 1, 'G: provider called on get miss');

fwrite(STDOUT, "OK (REM-PS9 homepage shared resolver)\n");
