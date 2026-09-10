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
    public function __construct(string $key)
    {
    }

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

/**
 * @param array<string, mixed> $payload
 */
function jsonResponse(int $status, array $payload): HttpResponse
{
    return new HttpResponse($status, json_encode($payload, JSON_THROW_ON_ERROR));
}

/**
 * @param array<string, mixed> $data
 */
function successEnvelope(array $data, string $message = 'ok', int $status = 200): HttpResponse
{
    return jsonResponse($status, [
        'success' => true,
        'error' => null,
        'message' => $message,
        'data' => $data === [] ? new \stdClass() : $data,
    ]);
}

function assertPhase2(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$unicid = '123e4567-e89b-12d3-a456-426614174000';
$configuration = new ConfigurationRepository();
$configuration->save(true, $unicid, 'test-secret');
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

$transport->responses[] = successEnvelope([
    'access_token' => 'token-one',
    'token_type' => 'Bearer',
    'expires_in' => 86400,
    'shop' => ['id' => 10, 'name' => 'https://shop.example', 'unicid' => $unicid],
]);
$client->login();
assertPhase2($transport->requests[0]['url'] === 'https://cp.example/api/v1/auth/login', 'login endpoint mismatch');
assertPhase2($transport->requests[0]['payload']['unicid'] === $unicid, 'login unicid mismatch');
assertPhase2($transport->requests[0]['payload']['name'] === 'https://shop.example', 'login shop name mismatch');
assertPhase2($transport->requests[0]['payload']['secret'] === 'test-secret', 'login secret mismatch');
assertPhase2($tokens->getAccessToken() === 'token-one', 'login token was not stored');
assertPhase2(Configuration::$values[TokenRepository::ACCESS_TOKEN] !== 'token-one', 'token was stored in plain text');
assertPhase2($tokens->getExpiresAt() === $now + 86400, 'token expiration mismatch');

$transport->responses[] = successEnvelope(['unicid' => $unicid]);
$client->getShop();
assertPhase2($transport->requests[1]['method'] === 'GET', 'getShop method mismatch');
assertPhase2($transport->requests[1]['url'] === 'https://cp.example/api/v1/shop', 'getShop endpoint mismatch');
assertPhase2($transport->requests[1]['headers']['Authorization'] === 'Bearer token-one', 'Bearer header mismatch');

$now += 86350;
$transport->responses[] = successEnvelope([
    'access_token' => 'token-two',
    'token_type' => 'Bearer',
    'expires_in' => 86400,
]);
$transport->responses[] = successEnvelope([
    'id' => 55,
    'shop_id' => 10,
    'order_id' => '100',
    'unicid' => $unicid,
    'created_at' => '2026-01-01T00:00:00Z',
], 'created', 201);
$client->createOrder(['order_id' => '100', 'name' => 'Client']);
assertPhase2($transport->requests[2]['url'] === 'https://cp.example/api/v1/auth/refresh', 'proactive refresh endpoint mismatch');
assertPhase2($transport->requests[3]['url'] === 'https://cp.example/api/v1/orders', 'createOrder endpoint mismatch');
assertPhase2($transport->requests[3]['headers']['Authorization'] === 'Bearer token-two', 'refreshed token was not used');

$transport->responses[] = jsonResponse(401, [
    'success' => false,
    'error' => 'token_expired',
    'message' => 'expired',
    'data' => new \stdClass(),
]);
$transport->responses[] = successEnvelope([
    'access_token' => 'token-three',
    'token_type' => 'Bearer',
    'expires_in' => 86400,
    'shop' => ['id' => 10, 'unicid' => $unicid],
]);
$transport->responses[] = successEnvelope([
    'id' => 55,
    'shop_id' => 10,
    'order_id' => '100',
    'status_id' => 'cp_sent',
    'status' => 'sent',
    'updated_at' => '2026-01-01T00:00:01Z',
], 'updated');
$requestCountBefore401 = count($transport->requests);
$client->updateOrderStatus('100', 'sent', 'cp_sent');
assertPhase2($transport->requests[$requestCountBefore401]['method'] === 'PATCH', 'status method mismatch');
assertPhase2($transport->requests[$requestCountBefore401 + 1]['url'] === 'https://cp.example/api/v1/auth/login', '401 did not trigger re-login');
assertPhase2($transport->requests[$requestCountBefore401 + 2]['headers']['Authorization'] === 'Bearer token-three', '401 retry did not use new token');
assertPhase2($transport->requests[$requestCountBefore401 + 2]['payload']['status_id'] === 'cp_sent', 'status_id contract mismatch');
assertPhase2($transport->requests[$requestCountBefore401 + 2]['payload']['status'] === 'sent', 'status contract mismatch');
assertPhase2(count($transport->requests) === $requestCountBefore401 + 3, '401 recovery must retry only once');

$transport->responses[] = jsonResponse(401, [
    'success' => false,
    'error' => 'token_expired',
    'message' => 'expired',
    'data' => new \stdClass(),
]);
$transport->responses[] = successEnvelope([
    'access_token' => 'token-four',
    'token_type' => 'Bearer',
    'expires_in' => 86400,
    'shop' => ['id' => 10, 'unicid' => $unicid],
]);
$transport->responses[] = jsonResponse(401, [
    'success' => false,
    'error' => 'still-bad',
    'message' => 'still-bad',
    'data' => new \stdClass(),
]);
try {
    $client->getShop();
    assertPhase2(false, 'second 401 after recovery should fail');
} catch (AuthenticationException $exception) {
    assertPhase2(!$tokens->hasToken(), 'terminal auth failure must invalidate tokens');
}

$transport->responses[] = successEnvelope([
    'access_token' => 'token-five',
    'token_type' => 'Bearer',
    'expires_in' => 86400,
    'shop' => ['id' => 10, 'unicid' => $unicid],
]);
$client->login();
$transport->responses[] = successEnvelope([], 'ok');
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

// Old top-level token layout / non-object data must be rejected.
$transport->responses[] = new HttpResponse(
    200,
    json_encode([
        'success' => true,
        'error' => null,
        'message' => 'ok',
        'access_token' => 'legacy-token',
        'token_type' => 'Bearer',
        'expires_in' => 86400,
        'shop' => ['unicid' => $unicid],
        'data' => [],
    ], JSON_THROW_ON_ERROR)
);
try {
    $client->login();
    assertPhase2(false, 'legacy top-level token / empty-array data accepted');
} catch (InvalidPayloadException $exception) {
    assertPhase2(true, 'legacy login envelope rejected');
}

// HTTP 2xx alone is not success when success=false.
$transport->responses[] = new HttpResponse(
    200,
    '{"success":false,"error":"authentication_failed","message":"no","data":{}}'
);
try {
    $client->login();
    assertPhase2(false, 'success=false accepted as CP success');
} catch (InvalidPayloadException $exception) {
    assertPhase2(true, 'success=false rejected');
}

$transport->responses[] = successEnvelope([
    'token_type' => 'Bearer',
    'expires_in' => 86400,
    'shop' => ['id' => 10, 'unicid' => $unicid],
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

// Create identity echo mismatch must fail closed.
$tokens->save('echo-token', 'Bearer', $now + 86400);
$transport->responses[] = successEnvelope([
    'id' => 77,
    'shop_id' => 10,
    'order_id' => 'WRONG',
    'unicid' => $unicid,
    'created_at' => '2026-01-01T00:00:00Z',
], 'created', 201);
try {
    $client->createOrder(['order_id' => '100', 'name' => 'Client']);
    assertPhase2(false, 'create order_id echo mismatch accepted');
} catch (InvalidPayloadException $exception) {
    assertPhase2(true, 'create order_id echo mismatch rejected');
}

// PATCH echo mismatch must fail closed.
$transport->responses[] = successEnvelope([
    'id' => 55,
    'shop_id' => 10,
    'order_id' => '100',
    'status_id' => 'other',
    'status' => 'sent',
    'updated_at' => '2026-01-01T00:00:01Z',
], 'updated');
try {
    $client->updateOrderStatus('100', 'sent', 'cp_sent');
    assertPhase2(false, 'PATCH status_id echo mismatch accepted');
} catch (InvalidPayloadException $exception) {
    assertPhase2(true, 'PATCH status_id echo mismatch rejected');
}

// Create response must validate shop_id and created_at structurally.
$tokens->save('create-fields-token', 'Bearer', $now + 86400);
foreach ([
    ['missing shop_id', ['id' => 55, 'order_id' => '100', 'unicid' => $unicid, 'created_at' => '2026-01-01T00:00:00Z']],
    ['zero shop_id', ['id' => 55, 'shop_id' => 0, 'order_id' => '100', 'unicid' => $unicid, 'created_at' => '2026-01-01T00:00:00Z']],
    ['negative shop_id', ['id' => 55, 'shop_id' => -3, 'order_id' => '100', 'unicid' => $unicid, 'created_at' => '2026-01-01T00:00:00Z']],
    ['missing created_at', ['id' => 55, 'shop_id' => 10, 'order_id' => '100', 'unicid' => $unicid]],
    ['empty created_at', ['id' => 55, 'shop_id' => 10, 'order_id' => '100', 'unicid' => $unicid, 'created_at' => '']],
] as [$label, $data]) {
    $transport->responses[] = successEnvelope($data, 'created', 201);
    try {
        $client->createOrder(['order_id' => '100', 'name' => 'Client']);
        assertPhase2(false, $label . ' accepted');
    } catch (InvalidPayloadException $exception) {
        assertPhase2(true, $label . ' rejected');
    }
}

$transport->responses[] = successEnvelope([
    'id' => 55,
    'shop_id' => 10,
    'order_id' => '100',
    'unicid' => $unicid,
    'created_at' => '2026-01-01T00:00:00Z',
], 'created', 201);
$client->createOrder(['order_id' => '100', 'name' => 'Client']);
assertPhase2(true, 'valid create envelope with shop_id/created_at accepted');

// Existing bearer token can GET /shop without re-login — do not treat refresh success as secret proof.
$configuration->save(true, $unicid, 'brand-new-secret');
$tokens->save('lingering-token', 'Bearer', $now + 86400);
$requestCountBeforeReuse = count($transport->requests);
$transport->responses[] = successEnvelope(['unicid' => $unicid]);
$client->getShop();
assertPhase2(count($transport->requests) === $requestCountBeforeReuse + 1, 'valid token must not trigger login');
assertPhase2(
    $transport->requests[array_key_last($transport->requests)]['url'] === 'https://cp.example/api/v1/shop',
    'reuse path must call GET /shop only'
);
assertPhase2(
    $transport->requests[array_key_last($transport->requests)]['headers']['Authorization'] === 'Bearer lingering-token',
    'reuse path must keep the old bearer token'
);
foreach (array_slice($transport->requests, $requestCountBeforeReuse) as $request) {
    assertPhase2(
        strpos((string) $request['url'], '/auth/login') === false,
        'GET /shop success with lingering token must not prove the newly stored secret'
    );
}

$cpSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Api/ControlPanelClient.php');
assertPhase2(strpos($cpSrc, 'ModuleDeploymentEnvironment') !== false, 'deployment environment retained for base URL');

fwrite(STDOUT, "OK (Phase 2 Control Panel API contract and token lifecycle)\n");
