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

use PrestaShop\Module\Unipayment\Api\ControlPanelClient;
use PrestaShop\Module\Unipayment\Api\Exception\AuthenticationException;
use PrestaShop\Module\Unipayment\Api\Exception\ConnectionException;
use PrestaShop\Module\Unipayment\Api\Exception\HttpException;
use PrestaShop\Module\Unipayment\Api\Exception\InvalidPayloadException;
use PrestaShop\Module\Unipayment\Api\Exception\MalformedJsonException;
use PrestaShop\Module\Unipayment\Api\Exception\TimeoutException;
use PrestaShop\Module\Unipayment\Api\HttpResponse;
use PrestaShop\Module\Unipayment\Api\HttpTransportInterface;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Security\TokenRepository;

final class FakeTransport implements HttpTransportInterface
{
    /** @var HttpResponse[]|\Throwable[] */
    public $responses = [];

    /** @var array<int, array<string, mixed>> */
    public $requests = [];

    public function request(string $method, string $url, array $headers, ?array $payload): HttpResponse
    {
        $this->requests[] = compact('method', 'url', 'headers', 'payload');
        $response = array_shift($this->responses);
        if ($response instanceof \Throwable) {
            throw $response;
        }
        if (!$response instanceof HttpResponse) {
            throw new RuntimeException('No fake response queued.');
        }

        return $response;
    }
}

function jsonResponse(int $status, array $payload): HttpResponse
{
    return new HttpResponse($status, json_encode($payload, JSON_THROW_ON_ERROR));
}

function assertPhase2(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$configuration = new ConfigurationRepository();
$configuration->save(true, '123e4567-e89b-12d3-a456-426614174000', 'test-secret');
$tokens = new TokenRepository();
$transport = new FakeTransport();
$now = 1700000000;
$client = new ControlPanelClient(
    $configuration,
    $tokens,
    $transport,
    'https://shop.example',
    'https://cp.example/api/v1',
    static function () use (&$now): int {
        return $now;
    }
);

$transport->responses[] = jsonResponse(200, [
    'success' => true,
    'access_token' => 'token-one',
    'token_type' => 'Bearer',
    'expires_in' => 86400,
    'shop' => ['id' => 10, 'name' => 'https://shop.example', 'unicid' => '123'],
]);
$client->login();
assertPhase2($transport->requests[0]['url'] === 'https://cp.example/api/v1/auth/login', 'login endpoint mismatch');
assertPhase2($transport->requests[0]['payload']['unicid'] === '123e4567-e89b-12d3-a456-426614174000', 'login unicid mismatch');
assertPhase2($transport->requests[0]['payload']['name'] === 'https://shop.example', 'login shop name mismatch');
assertPhase2($transport->requests[0]['payload']['secret'] === 'test-secret', 'login secret mismatch');
assertPhase2($tokens->getAccessToken() === 'token-one', 'login token was not stored');
assertPhase2(Configuration::$values[TokenRepository::ACCESS_TOKEN] !== 'token-one', 'token was stored in plain text');
assertPhase2($tokens->getExpiresAt() === $now + 86400, 'token expiration mismatch');

$transport->responses[] = jsonResponse(200, [
    'success' => true,
    'data' => ['unicid' => '123e4567-e89b-12d3-a456-426614174000'],
]);
$client->getShop();
assertPhase2($transport->requests[1]['method'] === 'GET', 'getShop method mismatch');
assertPhase2($transport->requests[1]['url'] === 'https://cp.example/api/v1/shop', 'getShop endpoint mismatch');
assertPhase2($transport->requests[1]['headers']['Authorization'] === 'Bearer token-one', 'Bearer header mismatch');

$now += 86350;
$transport->responses[] = jsonResponse(200, [
    'success' => true,
    'access_token' => 'token-two',
    'token_type' => 'Bearer',
    'expires_in' => 86400,
]);
$transport->responses[] = jsonResponse(201, [
    'success' => true,
    'data' => ['id' => 55],
]);
$client->createOrder(['order_id' => '100', 'name' => 'Client']);
assertPhase2($transport->requests[2]['url'] === 'https://cp.example/api/v1/auth/refresh', 'proactive refresh endpoint mismatch');
assertPhase2($transport->requests[3]['url'] === 'https://cp.example/api/v1/orders', 'createOrder endpoint mismatch');
assertPhase2($transport->requests[3]['headers']['Authorization'] === 'Bearer token-two', 'refreshed token was not used');

$transport->responses[] = jsonResponse(401, ['error' => 'expired']);
$transport->responses[] = jsonResponse(200, [
    'success' => true,
    'access_token' => 'token-three',
    'token_type' => 'Bearer',
    'expires_in' => 86400,
    'shop' => ['id' => 10],
]);
$transport->responses[] = jsonResponse(200, [
    'success' => true,
    'data' => ['order_id' => '100', 'status' => 'sent'],
]);
$requestCountBefore401 = count($transport->requests);
$client->updateOrderStatus('100', 'sent', 'cp_sent');
assertPhase2($transport->requests[$requestCountBefore401]['method'] === 'PATCH', 'status method mismatch');
assertPhase2($transport->requests[$requestCountBefore401 + 1]['url'] === 'https://cp.example/api/v1/auth/login', '401 did not trigger re-login');
assertPhase2($transport->requests[$requestCountBefore401 + 2]['headers']['Authorization'] === 'Bearer token-three', '401 retry did not use new token');
assertPhase2(count($transport->requests) === $requestCountBefore401 + 3, '401 recovery must retry only once');

$transport->responses[] = jsonResponse(401, ['error' => 'expired']);
$transport->responses[] = jsonResponse(200, [
    'success' => true,
    'access_token' => 'token-four',
    'token_type' => 'Bearer',
    'expires_in' => 86400,
    'shop' => ['id' => 10],
]);
$transport->responses[] = jsonResponse(401, ['error' => 'still-bad']);
try {
    $client->getShop();
    assertPhase2(false, 'second 401 after recovery should fail');
} catch (AuthenticationException $exception) {
    assertPhase2(!$tokens->hasToken(), 'terminal auth failure must invalidate tokens');
}

$transport->responses[] = jsonResponse(200, [
    'success' => true,
    'access_token' => 'token-five',
    'token_type' => 'Bearer',
    'expires_in' => 86400,
    'shop' => ['id' => 10],
]);
$client->login();
$transport->responses[] = jsonResponse(200, ['success' => true, 'message' => 'ok']);
$client->logout();
assertPhase2(str_ends_with((string) $transport->requests[array_key_last($transport->requests)]['url'], '/auth/logout'), 'logout endpoint mismatch');
assertPhase2(!$tokens->hasToken(), 'logout did not invalidate the local token');

$transport->responses[] = new HttpResponse(403, '<html>challenge</html>');
try {
    $client->login();
    assertPhase2(false, 'HTML HTTP error was accepted');
} catch (HttpException $exception) {
    assertPhase2($exception->getStatusCode() === 403, 'HTML HTTP error status mismatch');
}

$transport->responses[] = new HttpResponse(200, '<html>not json</html>');
try {
    $client->login();
    assertPhase2(false, 'malformed successful response was accepted');
} catch (MalformedJsonException $exception) {
    assertPhase2(true, 'malformed successful response classification');
}

$transport->responses[] = jsonResponse(200, [
    'success' => true,
    'token_type' => 'Bearer',
    'expires_in' => 86400,
    'shop' => ['id' => 10],
]);
try {
    $client->login();
    assertPhase2(false, 'missing access_token was accepted');
} catch (InvalidPayloadException $exception) {
    assertPhase2(!$tokens->hasToken(), 'invalid token payload must invalidate storage');
}

$transport->responses[] = new TimeoutException('The Control Panel request timed out.');
try {
    $client->login();
    assertPhase2(false, 'timeout was not classified');
} catch (TimeoutException $exception) {
    assertPhase2(true, 'timeout classification');
}

$transport->responses[] = new ConnectionException('The Control Panel connection failed: refused');
try {
    $client->login();
    assertPhase2(false, 'connection error was not classified');
} catch (ConnectionException $exception) {
    assertPhase2(!($exception instanceof TimeoutException), 'connection must not be timeout');
}

fwrite(STDOUT, "OK (Phase 2 Control Panel API contract and token lifecycle)\n");
