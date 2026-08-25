<?php

declare(strict_types=1);

/**
 * AUD-012 — signed Control Panel → module request verification.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
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

if (!function_exists('pSQL')) {
    function pSQL(string $string, bool $htmlOK = false): string
    {
        unset($htmlOK);

        return str_replace("'", "\\'", $string);
    }
}

final class Aud012FakeDb
{
    /** @var list<array{unicid:string,nonce_hash:string}> */
    public array $rows = [];

    /** @var list<string> */
    public array $queries = [];

    public function execute(string $sql): bool
    {
        $this->queries[] = $sql;

        return true;
    }

    public function insert(string $table, array $data, $nullValues = false, $useCache = true, $type = 1): bool
    {
        unset($table, $nullValues, $useCache, $type);

        foreach ($this->rows as $row) {
            if ($row['unicid'] === $data['unicid'] && $row['nonce_hash'] === $data['nonce_hash']) {
                return false;
            }
        }

        $this->rows[] = [
            'unicid' => (string) $data['unicid'],
            'nonce_hash' => (string) $data['nonce_hash'],
        ];

        return true;
    }

    public function getMsgError(): string
    {
        return 'Duplicate entry';
    }
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Api\Exception\ModuleApiException;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Security\ApiNonceRepository;
use PrestaShop\Module\Unipayment\Security\FixedClock;
use PrestaShop\Module\Unipayment\Security\ModuleRequestAuthenticator;
use PrestaShop\Module\Unipayment\Security\ModuleRequestSignatureProtocol;
use PrestaShop\Module\Unipayment\Security\ModuleRequestSignatureVerifier;

function assertAud012(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function signedHeaders(
    string $secret,
    string $rawBody,
    string $timestamp = ModuleRequestSignatureProtocol::CONTRACT_TIMESTAMP,
    string $nonce = ModuleRequestSignatureProtocol::CONTRACT_NONCE
): array {
    return [
        ModuleRequestSignatureProtocol::HEADER_TIMESTAMP => $timestamp,
        ModuleRequestSignatureProtocol::HEADER_NONCE => $nonce,
        ModuleRequestSignatureProtocol::HEADER_SIGNATURE => ModuleRequestSignatureProtocol::computeSignature(
            $secret,
            $timestamp,
            $nonce,
            $rawBody
        ),
    ];
}

function makeAuthenticator(FixedClock $clock, Aud012FakeDb $db): ModuleRequestAuthenticator
{
    $configuration = new ConfigurationRepository();
    $configuration->save(true, 'TEST-UNICID', 'test_shared_secret_123');

    $nonceRepository = new ApiNonceRepository($db);
    $verifier = new ModuleRequestSignatureVerifier($clock);

    return new ModuleRequestAuthenticator($configuration, $verifier, $nonceRepository, $clock);
}

assertAud012(
    ModuleRequestSignatureProtocol::computeSignature(
        ModuleRequestSignatureProtocol::CONTRACT_SECRET,
        ModuleRequestSignatureProtocol::CONTRACT_TIMESTAMP,
        ModuleRequestSignatureProtocol::CONTRACT_NONCE,
        ModuleRequestSignatureProtocol::CONTRACT_RAW_BODY
    ) === ModuleRequestSignatureProtocol::CONTRACT_SIGNATURE,
    'shared contract vector mismatch'
);

$clock = new FixedClock((int) ModuleRequestSignatureProtocol::CONTRACT_TIMESTAMP);
$db = new Aud012FakeDb();
$authenticator = makeAuthenticator($clock, $db);

$rawBody = ModuleRequestSignatureProtocol::CONTRACT_RAW_BODY;
$payload = json_decode($rawBody, true);
assertAud012(is_array($payload), 'contract payload decode failed');

$unicid = $authenticator->authenticate($payload, $rawBody, signedHeaders('test_shared_secret_123', $rawBody));
assertAud012($unicid === 'TEST-UNICID', 'valid signed request rejected');
assertAud012(count($db->rows) === 1, 'nonce not reserved after valid auth');

try {
    $authenticator->authenticate($payload, $rawBody, signedHeaders('test_shared_secret_123', $rawBody));
    assertAud012(false, 'exact replay was accepted');
} catch (ModuleApiException $exception) {
    assertAud012($exception->getStatusCode() === 401, 'replay status code mismatch');
    assertAud012(
        $exception->getMessage() === ModuleRequestSignatureProtocol::AUTH_FAILURE_MESSAGE,
        'replay message mismatch'
    );
}

$poisonDb = new Aud012FakeDb();
$poisonAuth = makeAuthenticator($clock, $poisonDb);
$poisonNonce = str_repeat('e', 64);
try {
    $poisonAuth->authenticate($payload, $rawBody, [
        ModuleRequestSignatureProtocol::HEADER_TIMESTAMP => ModuleRequestSignatureProtocol::CONTRACT_TIMESTAMP,
        ModuleRequestSignatureProtocol::HEADER_NONCE => $poisonNonce,
        ModuleRequestSignatureProtocol::HEADER_SIGNATURE => str_repeat('0', 64),
    ]);
    assertAud012(false, 'invalid signature accepted');
} catch (ModuleApiException $exception) {
    assertAud012($exception->getStatusCode() === 401, 'invalid signature status');
}
assertAud012($poisonDb->rows === [], 'invalid signature must not consume nonce');

$validAfterPoison = $poisonAuth->authenticate(
    $payload,
    $rawBody,
    signedHeaders('test_shared_secret_123', $rawBody, ModuleRequestSignatureProtocol::CONTRACT_TIMESTAMP, $poisonNonce)
);
assertAud012($validAfterPoison === 'TEST-UNICID', 'valid request after poison attempt must succeed');

$newNonce = str_repeat('b', 64);
$newHeaders = signedHeaders(
    'test_shared_secret_123',
    $rawBody,
    ModuleRequestSignatureProtocol::CONTRACT_TIMESTAMP,
    $newNonce
);
$unicidAgain = $authenticator->authenticate($payload, $rawBody, $newHeaders);
assertAud012($unicidAgain === 'TEST-UNICID', 'same body with new nonce rejected');

$tamperedBody = '{"unicid":"TEST-UNICID","order_id":"ABC123","status":"approved","status_id":"11"}';
try {
    $authenticator->authenticate(json_decode($tamperedBody, true), $tamperedBody, signedHeaders('test_shared_secret_123', $rawBody));
    assertAud012(false, 'tampered body accepted');
} catch (ModuleApiException $exception) {
    assertAud012($exception->getStatusCode() === 401, 'tampered body status mismatch');
}

$wrongSecretHeaders = signedHeaders('wrong-secret', $rawBody, ModuleRequestSignatureProtocol::CONTRACT_TIMESTAMP, str_repeat('c', 64));
try {
    $authenticator->authenticate($payload, $rawBody, $wrongSecretHeaders);
    assertAud012(false, 'wrong signature accepted');
} catch (ModuleApiException $exception) {
    assertAud012($exception->getStatusCode() === 401, 'wrong signature status mismatch');
}

$staleClock = new FixedClock((int) ModuleRequestSignatureProtocol::CONTRACT_TIMESTAMP + 400);
$staleAuthenticator = makeAuthenticator($staleClock, new Aud012FakeDb());
try {
    $staleAuthenticator->authenticate(
        $payload,
        $rawBody,
        signedHeaders('test_shared_secret_123', $rawBody, ModuleRequestSignatureProtocol::CONTRACT_TIMESTAMP, str_repeat('d', 64))
    );
    assertAud012(false, 'expired timestamp accepted');
} catch (ModuleApiException $exception) {
    assertAud012($exception->getStatusCode() === 401, 'expired timestamp status mismatch');
}

$futureClock = new FixedClock((int) ModuleRequestSignatureProtocol::CONTRACT_TIMESTAMP - 400);
$futureAuthenticator = makeAuthenticator($futureClock, new Aud012FakeDb());
try {
    $futureAuthenticator->authenticate(
        $payload,
        $rawBody,
        signedHeaders('test_shared_secret_123', $rawBody, ModuleRequestSignatureProtocol::CONTRACT_TIMESTAMP, str_repeat('f', 64))
    );
    assertAud012(false, 'future timestamp outside window accepted');
} catch (ModuleApiException $exception) {
    assertAud012($exception->getStatusCode() === 401, 'future timestamp status mismatch');
}

try {
    $authenticator->authenticate($payload, $rawBody, [
        ModuleRequestSignatureProtocol::HEADER_TIMESTAMP => ModuleRequestSignatureProtocol::CONTRACT_TIMESTAMP,
        ModuleRequestSignatureProtocol::HEADER_NONCE => ModuleRequestSignatureProtocol::CONTRACT_NONCE,
    ]);
    assertAud012(false, 'missing signature header accepted');
} catch (ModuleApiException $exception) {
    assertAud012($exception->getStatusCode() === 401, 'missing signature status mismatch');
}

try {
    $authenticator->authenticate($payload, $rawBody, [
        ModuleRequestSignatureProtocol::HEADER_TIMESTAMP => 'not-a-number',
        ModuleRequestSignatureProtocol::HEADER_NONCE => str_repeat('a', 64),
        ModuleRequestSignatureProtocol::HEADER_SIGNATURE => str_repeat('1', 64),
    ]);
    assertAud012(false, 'non-numeric timestamp accepted');
} catch (ModuleApiException $exception) {
    assertAud012($exception->getStatusCode() === 401, 'non-numeric timestamp status');
}

try {
    $authenticator->authenticate($payload, $rawBody, [
        ModuleRequestSignatureProtocol::HEADER_TIMESTAMP => ModuleRequestSignatureProtocol::CONTRACT_TIMESTAMP,
        ModuleRequestSignatureProtocol::HEADER_NONCE => 'short',
        ModuleRequestSignatureProtocol::HEADER_SIGNATURE => str_repeat('1', 64),
    ]);
    assertAud012(false, 'invalid nonce format accepted');
} catch (ModuleApiException $exception) {
    assertAud012($exception->getStatusCode() === 401, 'invalid nonce status');
}

try {
    $authenticator->authenticate([
        'unicid' => 'TEST-UNICID',
        'secret' => 'test_shared_secret_123',
    ], '{"unicid":"TEST-UNICID","secret":"test_shared_secret_123"}', []);
    assertAud012(false, 'legacy unsigned request accepted');
} catch (ModuleApiException $exception) {
    assertAud012($exception->getStatusCode() === 401, 'legacy unsigned status mismatch');
}

$disabled = new ConfigurationRepository();
$disabled->save(false, 'TEST-UNICID', 'test_shared_secret_123');
$disabledAuth = new ModuleRequestAuthenticator(
    $disabled,
    new ModuleRequestSignatureVerifier($clock),
    new ApiNonceRepository(new Aud012FakeDb()),
    $clock
);
try {
    $disabledAuth->authenticate($payload, $rawBody, signedHeaders('test_shared_secret_123', $rawBody, ModuleRequestSignatureProtocol::CONTRACT_TIMESTAMP, str_repeat('1', 64)));
    assertAud012(false, 'disabled module accepted');
} catch (ModuleApiException $exception) {
    assertAud012($exception->getStatusCode() === 403, 'disabled module must be 403');
}

assertAud012(ModuleRequestSignatureProtocol::TIMESTAMP_TOLERANCE_SECONDS === 300, 'timestamp window');
assertAud012(ModuleRequestSignatureProtocol::NONCE_HEX_LENGTH === 64, 'nonce hex length');
assertAud012(ModuleRequestSignatureProtocol::NONCE_RETENTION_SECONDS === 900, 'nonce retention');

fwrite(STDOUT, "OK (AUD-012 module request signature and replay protection)\n");
