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

    /**
     * @return string|false
     */
    public function decrypt(string $ciphertext)
    {
        $decoded = base64_decode($ciphertext, true);

        return is_string($decoded) ? strrev($decoded) : false;
    }
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Configuration\CredentialChangeSideEffectHandler;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationCacheInterface;
use PrestaShop\Module\Unipayment\Security\TokenRepository;

final class MemoryShopConfigurationCacheForTokenTest implements ShopConfigurationCacheInterface
{
    /** @var int */
    public $clearCount = 0;

    public function getFresh(string $unicid): ?array
    {
        return null;
    }

    public function replace(string $unicid, array $shopData): bool
    {
        return true;
    }

    public function delete(string $unicid): bool
    {
        return true;
    }

    public function clear(): bool
    {
        ++$this->clearCount;

        return true;
    }

    public function getMetadata(string $unicid): ?array
    {
        return null;
    }
}

function assertTokenRepo(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$tokens = new TokenRepository();
assertTokenRepo(!$tokens->hasToken(), 'empty state must have no token');
assertTokenRepo($tokens->getAccessToken() === null, 'empty access token must be null');
assertTokenRepo($tokens->getExpiresAt() === 0, 'empty expiry must be 0');
assertTokenRepo($tokens->getTokenType() === 'Bearer', 'default token type must be Bearer');

assertTokenRepo($tokens->save('access-1', 'Bearer', 1700000100), 'save must succeed');
assertTokenRepo($tokens->hasToken(), 'saved token must exist');
assertTokenRepo($tokens->getAccessToken() === 'access-1', 'saved token must decrypt');
assertTokenRepo($tokens->getExpiresAt() === 1700000100, 'expiry must round-trip');
assertTokenRepo(
    Configuration::$values[TokenRepository::ACCESS_TOKEN] !== 'access-1',
    'access token must not be stored in plain text'
);
assertTokenRepo(
    strpos((string) Configuration::$values[TokenRepository::ACCESS_TOKEN], 'enc:v1:') === 0,
    'access token must use enc:v1 prefix'
);

assertTokenRepo(!$tokens->save('', 'Bearer', 1700000100), 'empty access token must be rejected');
assertTokenRepo(!$tokens->save('access-2', 'Bearer', 0), 'non-positive expiry must be rejected');

Configuration::$values[TokenRepository::ACCESS_TOKEN] = 'plain-not-encrypted';
assertTokenRepo($tokens->getAccessToken() === null, 'malformed plain token must be unreadable');
assertTokenRepo(!$tokens->hasToken(), 'malformed token must report no token');

Configuration::$values[TokenRepository::ACCESS_TOKEN] = 'enc:v1:!!!not-base64!!!';
assertTokenRepo($tokens->getAccessToken() === null, 'unreadable encrypted token must return null');

assertTokenRepo($tokens->save('access-3', 'Bearer', 1700000200), 're-save after malformed state must work');
assertTokenRepo($tokens->invalidate(), 'invalidate must succeed');
assertTokenRepo(!$tokens->hasToken(), 'invalidate must clear token');
assertTokenRepo(!isset(Configuration::$values[TokenRepository::ACCESS_TOKEN]), 'invalidate must delete access token key');
assertTokenRepo(!isset(Configuration::$values[TokenRepository::TOKEN_TYPE]), 'invalidate must delete token type key');
assertTokenRepo(!isset(Configuration::$values[TokenRepository::EXPIRES_AT]), 'invalidate must delete expiry key');

assertTokenRepo($tokens->save('access-4', 'Bearer', 1700000300), 'token for credential-change check');
$cache = new MemoryShopConfigurationCacheForTokenTest();
assertTokenRepo(
    (new CredentialChangeSideEffectHandler($tokens, $cache))->onCredentialsChanged() === true,
    'credential change side effects must succeed'
);
assertTokenRepo(!$tokens->hasToken(), 'credential change must invalidate tokens');
assertTokenRepo($cache->clearCount === 1, 'credential change must clear shop configuration cache');

fwrite(STDOUT, "OK (TokenRepository lifecycle and credential-change invalidation)\n");
