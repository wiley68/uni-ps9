<?php

declare(strict_types=1);

// Isolated controller execution: no shop bootstrap, database writes or HTTP calls.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

class ModuleFrontController
{
}

final class Configuration
{
    public static function get(
        string $key,
        ?int $idLang = null,
        ?int $idShopGroup = null,
        ?int $idShop = null,
        mixed $default = false
    ): mixed {
        return match ($key) {
            'UNIPAYMENT_ENABLED' => true,
            'UNIPAYMENT_UNICID' => 'AUDIT-SHOP',
            'UNIPAYMENT_SECRET' => 'enc:v1:synthetic-ciphertext',
            default => $default,
        };
    }
}

final class PhpEncryption
{
    public function __construct(string $key)
    {
    }

    public function decrypt(string $ciphertext): string
    {
        return 'sensitive-fixture-secret';
    }
}

final class Db
{
    public static bool $fail = false;

    public static function getInstance(bool $master = true): object
    {
        if (self::$fail) {
            throw new RuntimeException('sensitive-fixture-secret sensitive-fixture-body sensitive-fixture-signature');
        }

        return new self();
    }
}

final class PrestaShopLogger
{
    /** @var list<array{message: string, severity: int}> */
    public static array $logs = [];

    public static function addLog(string $message, int $severity = 1): bool
    {
        self::$logs[] = ['message' => $message, 'severity' => $severity];

        return true;
    }
}

final class InboundApiInputStream
{
    public mixed $context;
    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return $path === 'php://input';
    }

    public function stream_read(int $count): string
    {
        $chunk = substr('{}', $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= 2;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return [];
    }
}

$endpoint = $argv[1] ?? '';
$case = $argv[2] ?? '';
$root = $argv[3] ?? dirname(__DIR__, 2);
if (!in_array($endpoint, ['shopcache', 'smartucfdebuglog', 'orderbankstatus'], true)
    || !in_array($case, ['normal', 'malformed', 'get-malformed', 'fallback', 'logging'], true)
) {
    exit(1);
}

if ($case !== 'fallback') {
    function getallheaders(): array
    {
        return ['X-Audit-Header' => 'sensitive-fixture-header', 0 => 'ignored', 'X-Invalid' => ['ignored']];
    }
}

define('_NEW_COOKIE_KEY_', 'synthetic-key');
require $root . '/vendor/autoload.php';
require $root . '/controllers/front/' . $endpoint . '.php';

$_SERVER = [
    'REQUEST_METHOD' => $case === 'get-malformed' ? 'GET' : 'POST',
    'CONTENT_LENGTH' => '2',
    'HTTP_X_AUDIT_SERVER' => 'sensitive-fixture-server',
];
if (in_array($case, ['malformed', 'get-malformed'], true)) {
    $_SERVER[0] = 'sensitive-fixture-numeric';
    $_SERVER[-1] = 'ignored';
    $_SERVER['7'] = 'ignored';
    $_SERVER['HTTP_X_INVALID_ARRAY'] = ['ignored'];
    $_SERVER['HTTP_X_INVALID_NULL'] = null;
    $_SERVER['HTTP_X_INVALID_NUMBER'] = 42;
}
Db::$fail = $case === 'logging';

stream_wrapper_unregister('php');
stream_wrapper_register('php', InboundApiInputStream::class);
ob_start();

$class = 'Unipayment' . ucfirst($endpoint) . 'ModuleFrontController';
$controller = new $class();
$extract = new ReflectionMethod($controller, 'extractRequestHeaders');
$headers = $extract->invoke($controller);
register_shutdown_function(static function () use ($headers): void {
    $body = (string) ob_get_clean();
    echo json_encode([
        'status' => http_response_code(),
        'body' => json_decode($body, true, 512, JSON_THROW_ON_ERROR),
        'headers' => $headers,
        'logs' => PrestaShopLogger::$logs,
    ], JSON_THROW_ON_ERROR);
});
$controller->postProcess();
