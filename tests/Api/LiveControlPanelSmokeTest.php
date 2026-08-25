<?php

declare(strict_types=1);

/**
 * Optional live Control Panel smoke sequence.
 *
 * Not part of the safe default suite path unless opted in:
 *
 *   UNIPAYMENT_LIVE_CP_TEST=1 \
 *   UNIPAYMENT_LIVE_UNICID='...' \
 *   UNIPAYMENT_LIVE_SECRET='...' \
 *   UNIPAYMENT_LIVE_SHOP_NAME='https://presta9.avalonbg.com' \
 *   php tests/Api/LiveControlPanelSmokeTest.php
 *
 * Optional:
 *   UNIPAYMENT_LIVE_BASE_URL='https://uni.avalonbg.com/api/v1'
 *
 * Uses in-memory Configuration stubs (does not mutate the live shop DB).
 * Never prints secrets or tokens.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (getenv('UNIPAYMENT_LIVE_CP_TEST') !== '1') {
    fwrite(STDOUT, "SKIP (set UNIPAYMENT_LIVE_CP_TEST=1 with live credentials to run)\n");
    exit(0);
}

define('_NEW_COOKIE_KEY_', 'live-smoke-key');

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

    /**
     * @return string|false
     */
    public function decrypt(string $ciphertext)
    {
        $decoded = base64_decode($ciphertext, true);

        return is_string($decoded) ? $decoded : false;
    }
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Api\ControlPanelClient;
use PrestaShop\Module\Unipayment\Api\CurlHttpTransport;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Security\TokenRepository;

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
$baseUrl = rtrim(trim((string) (getenv('UNIPAYMENT_LIVE_BASE_URL') ?: ControlPanelClient::DEFAULT_BASE_URL)), '/');

assertLive($unicid !== '' && $secret !== '' && $shopName !== '', 'live credentials env vars are required');

$configuration = new ConfigurationRepository();
assertLive($configuration->save(true, $unicid, $secret), 'could not stage local credentials');

$tokens = new TokenRepository();
$client = new ControlPanelClient(
    $configuration,
    $tokens,
    new CurlHttpTransport(),
    $shopName,
    $baseUrl
);

$client->login();
assertLive($tokens->hasToken(), 'login did not persist a local token');

$shop = $client->getShop();
assertLive(isset($shop['data']) && is_array($shop['data']), 'GET /shop missing data');

$client->refreshToken();
assertLive($tokens->hasToken(), 'refresh did not persist a local token');

$shopAgain = $client->getShop();
assertLive(isset($shopAgain['data']) && is_array($shopAgain['data']), 'GET /shop after refresh missing data');

$client->logout();
assertLive(!$tokens->hasToken(), 'logout did not invalidate local token');

$client->login();
assertLive($tokens->hasToken(), 're-login did not persist a local token');
$client->getShop();

$client->logout();

fwrite(STDOUT, "OK (live Control Panel smoke: login/shop/refresh/logout)\n");
