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

    /** @var bool */
    public static $failTokenDeletes = false;

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
        if (self::$failTokenDeletes && in_array($key, [
            'UNIPAYMENT_CP_ACCESS_TOKEN',
            'UNIPAYMENT_CP_TOKEN_TYPE',
            'UNIPAYMENT_CP_TOKEN_EXPIRES_AT',
        ], true)) {
            return false;
        }

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
    public static function addLog(string $message, int $severity = 1): void {}
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/fixtures/shop_snapshot.php';

use PrestaShop\Module\Unipayment\Api\ShopConfigurationProviderInterface;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Configuration\CredentialChangeSideEffectHandler;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationCacheInterface;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationService;
use PrestaShop\Module\Unipayment\Security\TokenRepository;

final class MemoryShopConfigurationCache implements ShopConfigurationCacheInterface
{
    /** @var array<string, array<string, mixed>> */
    public $rows = [];

    /** @var bool */
    public $clearResult = true;

    /** @var int */
    public $clearCount = 0;

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
        ++$this->clearCount;
        if (!$this->clearResult) {
            return false;
        }
        $this->rows = [];

        return true;
    }

    public function getMetadata(string $unicid): ?array
    {
        return isset($this->rows[$unicid])
            ? ['fetched_at' => '2026-08-25 10:00:00', 'expires_at' => '2026-08-26 10:00:00', 'is_fresh' => true]
            : null;
    }
}

final class CountingShopProvider implements ShopConfigurationProviderInterface
{
    /** @var int */
    public $calls = 0;

    public function getShop(): array
    {
        ++$this->calls;

        return ['success' => true, 'data' => unipayment_valid_shop_snapshot()];
    }
}

function assertCred(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * Mirrors BO credential-change detection in unipayment.php.
 */
function credentialsChanged(string $storedUnicid, string $submittedUnicid, string $submittedSecret): bool
{
    return $storedUnicid !== $submittedUnicid || $submittedSecret !== '';
}

$configuration = new ConfigurationRepository();
$configuration->save(true, '123e4567-e89b-12d3-a456-426614174000', 'original-secret');
$unicid = $configuration->getUnicid();
$unicidB = '223e4567-e89b-12d3-a456-426614174000';
$tokens = new TokenRepository();
$cache = new MemoryShopConfigurationCache();
$provider = new CountingShopProvider();
$service = new ShopConfigurationService($configuration, $cache, $provider, $tokens);

$snapshot = unipayment_valid_shop_snapshot(['unicid' => $unicid]);
$cache->replace($unicid, $snapshot);
$tokens->save('live-token', 'Bearer', 2000000000);

// 1. Secret change → invalidate token + clear cache; no CP refresh
assertCred(credentialsChanged($unicid, $unicid, 'new-secret-value') === true, 'non-empty secret must count as credential change');
assertCred($configuration->save(true, $unicid, 'new-secret-value'), 'secret save must succeed');
$ok = (new CredentialChangeSideEffectHandler($tokens, $cache))->onCredentialsChanged();
assertCred($ok === true, 'credential side effects must report success');
assertCred(!$tokens->hasToken(), 'secret change must invalidate token');
assertCred($cache->rows === [], 'secret change must clear shop cache');
assertCred($provider->calls === 0, 'secret change must not call Control Panel');

// 2. UNICID change
$cache->replace($unicid, $snapshot);
$tokens->save('unicid-token', 'Bearer', 2000000000);
assertCred(credentialsChanged($unicid, $unicidB, '') === true, 'UNICID change must count as credential change');
assertCred($configuration->save(true, $unicidB, null), 'UNICID save must succeed');
assertCred((new CredentialChangeSideEffectHandler($tokens, $cache))->onCredentialsChanged() === true, 'UNICID side effects must succeed');
assertCred(!$tokens->hasToken(), 'UNICID change must invalidate token');
assertCred($cache->rows === [], 'UNICID change must clear shop cache');

// Restore unicid for later assertions
$configuration->save(true, $unicid, 'original-secret');

// 3. Blank secret + non-credential flag → no invalidation
$cache->replace($unicid, $snapshot);
$tokens->save('keep-token', 'Bearer', 2000000000);
$clearBeforeBlank = $cache->clearCount;
assertCred(credentialsChanged($unicid, $unicid, '') === false, 'blank secret alone must not count as credential change');
assertCred($configuration->save(true, $unicid, null, false, true), 'blank-secret save of other flags must succeed');
assertCred($tokens->hasToken(), 'blank secret must preserve token');
assertCred(isset($cache->rows[$unicid]), 'blank secret must preserve cache');
assertCred($cache->clearCount === $clearBeforeBlank, 'blank secret must not clear cache');

// 4. Cache clear failure → side effects report failure (BO must not claim full success)
$cache->replace($unicid, $snapshot);
$tokens->save('fail-clear-token', 'Bearer', 2000000000);
$cache->clearResult = false;
$failedClear = (new CredentialChangeSideEffectHandler($tokens, $cache))->onCredentialsChanged();
assertCred($failedClear === false, 'failed cache clear must not report success');
assertCred(!$tokens->hasToken(), 'token invalidation still runs before failed clear');
assertCred(isset($cache->rows[$unicid]), 'failed clear must leave rows for caller visibility');
$cache->clearResult = true;

// 5. Token invalidation failure — TokenRepository::invalidate() returns bool from Configuration::deleteByName
$cache->rows = [];
$cache->replace($unicid, $snapshot);
$tokens->save('fail-token', 'Bearer', 2000000000);
Configuration::$failTokenDeletes = true;
$failedToken = (new CredentialChangeSideEffectHandler($tokens, $cache))->onCredentialsChanged();
Configuration::$failTokenDeletes = false;
assertCred($failedToken === false, 'failed token invalidation must not report success');
assertCred($cache->rows === [], 'cache clear still runs after failed token invalidation');
assertCred($tokens->hasToken(), 'failed invalidate must leave token keys present');

// 6. Save path must not force-refresh / call provider
$callsBefore = $provider->calls;
$meta = $service->getMetadata();
assertCred($provider->calls === $callsBefore, 'getMetadata must be read-only (no CP fetch)');
assertCred($meta === null || is_array($meta), 'metadata may be null after clears');

// Empty cache + metadata must not repopulate
$cache->rows = [];
$replaceBefore = $cache->replaceCount;
assertCred($service->getMetadata() === null, 'metadata on empty cache must be null');
assertCred($cache->replaceCount === $replaceBefore, 'metadata must not repopulate cache');
assertCred($provider->calls === $callsBefore, 'metadata must not trigger provider');

// Contract: configuration Save code must not call get(true)
$module = (string) file_get_contents(dirname(__DIR__, 2) . '/unipayment.php');
assertCred(
    (bool) preg_match(
        '/function handleConfigurationSubmit\(.*?\{.*?\n    \}/s',
        $module,
        $match
    ),
    'handleConfigurationSubmit not found'
);
$submitBody = $match[0] ?? '';
assertCred(strpos($submitBody, 'get(true)') === false, 'configuration Save must not call get(true)');
assertCred(strpos($submitBody, 'getShop(') === false, 'configuration Save must not call getShop');
assertCred(strpos($submitBody, 'onCredentialsChanged') !== false, 'configuration Save must invoke credential side effects');
assertCred(
    strpos($submitBody, 'sideEffectsApplied') !== false
        || strpos($submitBody, 'onCredentialsChanged()') !== false,
    'configuration Save must check credential side-effect outcome'
);

$template = (string) file_get_contents(dirname(__DIR__, 2) . '/views/templates/admin/configuration.tpl');
assertCred(
    (bool) preg_match(
        '/<form id="unipayment-settings-form"[\s\S]*name="submitUnipaymentConfiguration"[\s\S]*<\/form>/',
        $template
    ),
    'Save button must be inside the settings form so SECRET posts with Save'
);
assertCred(
    strpos($template, 'name="submitUnipaymentConfiguration" form="unipayment-settings-form"') === false,
    'Save must not depend solely on external form= association for credentials'
);

fwrite(STDOUT, "OK (credential-change invalidation remediation)\n");
