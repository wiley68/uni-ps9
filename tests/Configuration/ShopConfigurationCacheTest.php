<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
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
    public function __construct(string $key) {}

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

final class PrestaShopLogger
{
    /** @var list<string> */
    public static $messages = [];

    public static function addLog(string $message, int $severity = 1): void
    {
        self::$messages[] = $message;
    }
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/fixtures/shop_snapshot.php';

use PrestaShop\Module\Unipayment\Api\Exception\AuthenticationException;
use PrestaShop\Module\Unipayment\Api\Exception\ConnectionException;
use PrestaShop\Module\Unipayment\Api\Exception\HttpException;
use PrestaShop\Module\Unipayment\Api\ShopConfigurationProviderInterface;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Configuration\CredentialChangeSideEffectHandler;
use PrestaShop\Module\Unipayment\Configuration\Exception\ShopConfigurationSnapshotValidationException;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationCacheInterface;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationService;
use PrestaShop\Module\Unipayment\Security\TokenRepository;

final class MemoryShopConfigurationCache implements ShopConfigurationCacheInterface
{
    /** @var array<string, array<string, mixed>> */
    public $rows = [];

    /** @var bool */
    public $fresh = true;

    /** @var int */
    public $replaceCount = 0;

    public function getFresh(string $unicid): ?array
    {
        return $this->fresh ? ($this->rows[$unicid] ?? null) : null;
    }

    public function replace(string $unicid, array $shopData): bool
    {
        ++$this->replaceCount;
        $this->rows[$unicid] = $shopData;
        $this->fresh = true;

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
            ? ['fetched_at' => '2026-08-17 10:00:00', 'expires_at' => '2026-08-18 10:00:00', 'is_fresh' => $this->fresh]
            : null;
    }
}

final class FakeShopConfigurationProvider implements ShopConfigurationProviderInterface
{
    /** @var int */
    public $calls = 0;

    /** @var array<int, array<string, mixed>|\Throwable> */
    public $responses = [];

    public function getShop(): array
    {
        ++$this->calls;
        $response = array_shift($this->responses);
        if ($response instanceof \Throwable) {
            throw $response;
        }
        if (!is_array($response)) {
            throw new RuntimeException('No provider response queued.');
        }

        return $response;
    }
}

function assertPhase3(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$configuration = new ConfigurationRepository();
$configuration->save(true, '123e4567-e89b-12d3-a456-426614174000', 'test-secret');
$tokens = new TokenRepository();
$tokens->save('test-token', 'Bearer', 2000000000);
$cache = new MemoryShopConfigurationCache();
$provider = new FakeShopConfigurationProvider();
$service = new ShopConfigurationService($configuration, $cache, $provider, $tokens);
$unicid = $configuration->getUnicid();
$unicidB = '223e4567-e89b-12d3-a456-426614174000';

$provider->responses[] = ['success' => true, 'data' => unipayment_valid_shop_snapshot(['id' => 10])];
$initial = $service->get();
assertPhase3($provider->calls === 1, 'missing cache did not call Control Panel');
assertPhase3($initial['id'] === 10 && $cache->rows[$unicid] === $initial, 'initial snapshot was not cached');

$hit = $service->get();
assertPhase3($provider->calls === 1 && $hit === $initial, 'fresh cache did not avoid a Control Panel request');

$cache->fresh = false;
$provider->responses[] = ['success' => true, 'data' => unipayment_valid_shop_snapshot(['id' => 10, 'uni_zaglavie' => 'v2'])];
$expiredRefresh = $service->get();
assertPhase3($provider->calls === 2 && $expiredRefresh['uni_zaglavie'] === 'v2', 'expired cache was not refreshed');

$provider->responses[] = ['success' => true, 'data' => unipayment_valid_shop_snapshot(['id' => 10, 'uni_zaglavie' => 'v3'])];
$manualRefresh = $service->get(true);
assertPhase3($provider->calls === 3 && $manualRefresh['uni_zaglavie'] === 'v3', 'forced refresh used cached data');

$beforeInvalid = $cache->rows[$unicid];
$replaceBefore = $cache->replaceCount;
$provider->responses[] = ['success' => true, 'data' => unipayment_valid_shop_snapshot(['uni_typekop' => 'x'])];
try {
    $service->get(true);
    assertPhase3(false, 'invalid snapshot was accepted');
} catch (ShopConfigurationSnapshotValidationException $exception) {
    assertPhase3($cache->replaceCount === $replaceBefore, 'invalid snapshot overwrote cache');
    assertPhase3($cache->rows[$unicid] === $beforeInvalid, 'invalid snapshot changed previous good cache');
    assertPhase3($tokens->hasToken(), 'invalid snapshot purged tokens');
}

$cache->fresh = false;
$provider->responses[] = new ConnectionException('temporary transport failure');
try {
    $service->get();
    assertPhase3(false, 'transient Control Panel error was hidden');
} catch (ConnectionException $exception) {
    assertPhase3(isset($cache->rows[$unicid]), 'transient failure deleted the stored snapshot');
}

$cache->fresh = false;
$provider->responses[] = new HttpException(500, ['message' => 'upstream']);
try {
    $service->get();
    assertPhase3(false, 'HTTP 500 was hidden');
} catch (HttpException $exception) {
    assertPhase3(isset($cache->rows[$unicid]), 'HTTP 500 must not purge valid cache');
    assertPhase3($tokens->hasToken(), 'HTTP 500 must not invalidate tokens');
}

$provider->responses[] = new AuthenticationException('invalid credentials');
try {
    $service->get(true);
    assertPhase3(false, 'authentication failure was hidden');
} catch (AuthenticationException $exception) {
    assertPhase3(!isset($cache->rows[$unicid]), 'authentication failure retained the cache');
    assertPhase3(!$tokens->hasToken(), 'authentication failure retained the token');
}

$cache->replace($unicid, unipayment_valid_shop_snapshot(['id' => 10]));
$tokens->save('replacement-token', 'Bearer', 2000000000);
$provider->responses[] = new HttpException(404, ['message' => 'shop not found']);
try {
    $service->get(true);
    assertPhase3(false, 'shop-not-found failure was hidden');
} catch (HttpException $exception) {
    assertPhase3(!isset($cache->rows[$unicid]), 'shop-not-found failure retained the cache');
    assertPhase3(!$tokens->hasToken(), 'shop-not-found failure retained the token');
}

$callsBeforePush = $provider->calls;
$pushed = unipayment_valid_shop_snapshot(['id' => 10, 'consents' => [['id' => 5, 'name' => 'C', 'mandatory' => 1]]]);
assertPhase3($service->replaceSnapshot($unicid, $pushed), 'push snapshot replacement failed');
assertPhase3($cache->rows[$unicid] === $pushed, 'push snapshot was merged instead of replaced');
assertPhase3($provider->calls === $callsBeforePush, 'push replacement made an outbound request');

$snapshotA = unipayment_valid_shop_snapshot(['unicid' => $unicid, 'uni_zaglavie' => 'shop-a']);
$snapshotB = unipayment_valid_shop_snapshot(['unicid' => $unicidB, 'uni_zaglavie' => 'shop-b']);
$cache->replace($unicid, $snapshotA);
$cache->replace($unicidB, $snapshotB);
assertPhase3($cache->getFresh($unicid)['uni_zaglavie'] === 'shop-a', 'unicid A isolation failed');
assertPhase3($cache->getFresh($unicidB)['uni_zaglavie'] === 'shop-b', 'unicid B isolation failed');
$cache->delete($unicid);
assertPhase3($cache->getFresh($unicid) === null, 'delete removed wrong row scope');
assertPhase3($cache->getFresh($unicidB)['uni_zaglavie'] === 'shop-b', 'delete leaked into shop B');

$tokens->save('cred-token', 'Bearer', 2000000000);
$cache->replace($unicid, $snapshotA);
assertPhase3(
    (new CredentialChangeSideEffectHandler($tokens, $cache))->onCredentialsChanged() === true,
    'credential change side effects must succeed'
);
assertPhase3(!$tokens->hasToken(), 'credential change must invalidate tokens');
assertPhase3($cache->rows === [], 'credential change must clear shop cache');

fwrite(STDOUT, "OK (Phase 3 shop configuration cache semantics)\n");
