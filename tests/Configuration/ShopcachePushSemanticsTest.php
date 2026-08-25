<?php

declare(strict_types=1);

/**
 * shopcache push semantics via ShopConfigurationService::replaceSnapshot.
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

    public function decrypt(string $ciphertext): string|false
    {
        $decoded = base64_decode($ciphertext, true);

        return is_string($decoded) ? strrev($decoded) : false;
    }
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/fixtures/shop_snapshot.php';

use PrestaShop\Module\Unipayment\Api\ShopConfigurationProviderInterface;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Configuration\Exception\ShopConfigurationSnapshotValidationException;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationCacheInterface;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationService;
use PrestaShop\Module\Unipayment\Security\TokenRepository;

function assertPush(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class MemoryShopCache implements ShopConfigurationCacheInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $rows = [];

    public function getFresh(string $unicid): ?array
    {
        return $this->rows[$unicid] ?? null;
    }

    public function replace(string $unicid, array $shopData): bool
    {
        $this->rows[$unicid] = $shopData;

        return true;
    }

    public function clear(): bool
    {
        $this->rows = [];

        return true;
    }

    public function delete(string $unicid): bool
    {
        unset($this->rows[$unicid]);

        return true;
    }

    public function getMetadata(string $unicid): ?array
    {
        if (!isset($this->rows[$unicid])) {
            return null;
        }

        return [
            'unicid' => $unicid,
            'is_fresh' => true,
            'fetched_at' => '2026-01-01 00:00:00',
            'expires_at' => '2026-01-02 00:00:00',
        ];
    }
}

final class NullProvider implements ShopConfigurationProviderInterface
{
    public function getShop(): array
    {
        throw new RuntimeException('pull must not run during push test');
    }
}

$unicid = '123e4567-e89b-12d3-a456-426614174000';
$configuration = new ConfigurationRepository();
$configuration->save(true, $unicid, 'secret');
$cache = new MemoryShopCache();
$service = new ShopConfigurationService($configuration, $cache, new NullProvider(), new TokenRepository());

$good = unipayment_valid_shop_snapshot();
$cache->replace($unicid, $good);
assertPush(isset($cache->rows[$unicid]['uni_status']), 'seed cache');

$replaced = unipayment_valid_shop_snapshot(['uni_minstojnost' => 250]);
assertPush($service->replaceSnapshot($unicid, $replaced), 'valid push replaces');
assertPush((int) $cache->rows[$unicid]['uni_minstojnost'] === 250, 'full replacement applied');
assertPush(!array_key_exists('extra_old_field', $cache->rows[$unicid]), 'no merge of old keys');

$beforeInvalid = $cache->rows[$unicid];
try {
    $service->replaceSnapshot($unicid, ['unicid' => $unicid]);
    assertPush(false, 'invalid snapshot must throw');
} catch (ShopConfigurationSnapshotValidationException $exception) {
    assertPush($exception->errorCode() === 'shop_snapshot_invalid', 'error code');
}
assertPush($cache->rows[$unicid] === $beforeInvalid, 'stale-good preserved on invalid push');

try {
    $service->replaceSnapshot($unicid, unipayment_valid_shop_snapshot(['unicid' => '00000000-0000-0000-0000-000000000099']));
    assertPush(false, 'wrong unicid must throw');
} catch (ShopConfigurationSnapshotValidationException $exception) {
    assertPush(true, 'wrong unicid rejected');
}
assertPush($cache->rows[$unicid] === $beforeInvalid, 'wrong unicid keeps old cache');

fwrite(STDOUT, "OK (shopcache push replaceSnapshot semantics)\n");
