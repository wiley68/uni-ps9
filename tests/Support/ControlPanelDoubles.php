<?php

declare(strict_types=1);

define('_NEW_COOKIE_KEY_', 'synthetic-cp-test-key');
define('_DB_PREFIX_', 'ps_');

final class Configuration
{
    public static array $values = [];
    public static function get(string $key, ?int $lang = null, ?int $group = null, ?int $shop = null, mixed $default = false): mixed
    {
        return self::$values[$key] ?? $default;
    }
    public static function updateValue(string $key, mixed $value): bool
    {
        self::$values[$key] = $value;
        return true;
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
    public function encrypt(string $value): string { return base64_encode($value); }
    public function decrypt(string $value): string|false { return base64_decode($value, true); }
}

final class PrestaShopLogger
{
    public static array $messages = [];
    public static function addLog(string $message, int $severity = 1): void { self::$messages[] = $message; }
}

final class CpCaptureTransport implements \PrestaShop\Module\Unipayment\Api\HttpTransportInterface
{
    public array $requests = [];
    public array $responses = [];
    public function request(string $method, string $url, array $headers, ?array $payload): \PrestaShop\Module\Unipayment\Api\HttpResponse
    {
        $this->requests[] = compact('method', 'url', 'headers', 'payload');
        $response = array_shift($this->responses);
        if ($response instanceof Throwable) { throw $response; }
        if (!$response instanceof \PrestaShop\Module\Unipayment\Api\HttpResponse) { throw new RuntimeException('Missing CP fixture response.'); }
        return $response;
    }
}

function cpEnvelope(array $data): \PrestaShop\Module\Unipayment\Api\HttpResponse
{
    return new \PrestaShop\Module\Unipayment\Api\HttpResponse(200, json_encode(['success' => true, 'error' => null, 'data' => $data], JSON_THROW_ON_ERROR));
}

function cpAssert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function cpRejects(callable $operation, string $message): void
{
    try { $operation(); } catch (Throwable $exception) { return; }
    throw new RuntimeException($message);
}
