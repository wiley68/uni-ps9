<?php

declare(strict_types=1);

/**
 * Optional live shop-cache smoke (pull + validate + in-memory persist).
 *
 *   UNIPAYMENT_LIVE_CP_TEST=1 \
 *   UNIPAYMENT_LIVE_UNICID='...' \
 *   UNIPAYMENT_LIVE_SECRET='...' \
 *   UNIPAYMENT_LIVE_SHOP_NAME='https://presta9.avalonbg.com' \
 *   php tests/Configuration/LiveShopCacheSmokeTest.php
 *
 * Proves CP GET /shop + validation + service cache hit/miss without writing the shop DB.
 * Real table persistence is verified via BO „Обнови данните от банката“ / uninstall-reinstall.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (getenv('UNIPAYMENT_LIVE_CP_TEST') !== '1') {
    fwrite(STDOUT, "SKIP (set UNIPAYMENT_LIVE_CP_TEST=1 with live credentials to run)\n");
    exit(0);
}

define('_NEW_COOKIE_KEY_', 'live-cache-smoke-key');

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
    public function __construct(string $key) {}

    public function encrypt(string $plaintext): string
    {
        return base64_encode($plaintext);
    }

    /** @return string|false */
    public function decrypt(string $ciphertext)
    {
        $decoded = base64_decode($ciphertext, true);

        return is_string($decoded) ? $decoded : false;
    }
}

final class PrestaShopLogger
{
    public static function addLog(string $message, int $severity = 1): void {}
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Api\ControlPanelClient;
use PrestaShop\Module\Unipayment\Configuration\ModuleDeploymentEnvironment;
use PrestaShop\Module\Unipayment\Api\CurlHttpTransport;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationCacheInterface;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationService;
use PrestaShop\Module\Unipayment\Security\TokenRepository;

final class MemoryShopConfigurationCache implements ShopConfigurationCacheInterface
{
    /** @var array<string, array<string, mixed>> */
    public $rows = [];

    /** @var int */
    public $replaceCount = 0;

    public function getFresh(string $unicid): ?array
    {
        return $this->rows[$unicid] ?? null;
    }

    public function replace(string $unicid, array $shopData): bool
    {
        ++$this->replaceCount;
        $this->rows[$unicid] = $shopData;

        return true;
    }

    public function delete(string $unicid): bool
    {
        unset($this->rows[$unicid]);

        return true;
    }

    public function clear(): bool
    {
        $this->rows = [];

        return true;
    }

    public function getMetadata(string $unicid): ?array
    {
        return isset($this->rows[$unicid])
            ? ['fetched_at' => gmdate('Y-m-d H:i:s'), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400), 'is_fresh' => true]
            : null;
    }
}

function assertLive(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$unicid = trim((string) getenv('UNIPAYMENT_LIVE_UNICID'));
$secret = trim((string) getenv('UNIPAYMENT_LIVE_SECRET'));
$shopName = rtrim(trim((string) getenv('UNIPAYMENT_LIVE_SHOP_NAME')), '/');
$liveBase = getenv('UNIPAYMENT_LIVE_BASE_URL');
$baseUrl = rtrim(trim((string) (
    (is_string($liveBase) && $liveBase !== '')
        ? $liveBase
        : (new ModuleDeploymentEnvironment())->controlPanelApiBaseUrl()
)), '/');
assertLive($unicid !== '' && $secret !== '' && $shopName !== '', 'live credentials required');

$configuration = new ConfigurationRepository();
assertLive($configuration->save(true, $unicid, $secret), 'could not stage credentials');
$tokens = new TokenRepository();
$cache = new MemoryShopConfigurationCache();
$client = new ControlPanelClient($configuration, $tokens, new CurlHttpTransport(), $shopName, $baseUrl);
$service = new ShopConfigurationService($configuration, $cache, $client, $tokens);

$cache->clear();
$first = $service->get(true);
assertLive($first !== [], 'forced refresh returned empty snapshot');
assertLive(isset($cache->rows[$unicid]), 'snapshot was not cached');
$replaceAfterFirst = $cache->replaceCount;

$second = $service->get(false);
assertLive($second === $first, 'fresh get must return cached snapshot');
assertLive($cache->replaceCount === $replaceAfterFirst, 'fresh get must not refetch CP');

$forced = $service->get(true);
assertLive($cache->replaceCount === $replaceAfterFirst + 1, 'forced refresh must replace snapshot');
assertLive(isset($forced['kop']) || isset($forced['unicid']), 'refreshed snapshot missing expected structure');

$client->logout();

fwrite(STDOUT, "OK (live shop cache smoke: pull/validate/cache-hit/force-refresh)\n");
