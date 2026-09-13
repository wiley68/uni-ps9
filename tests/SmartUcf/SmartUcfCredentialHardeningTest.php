<?php

declare(strict_types=1);

/**
 * SmartUCF credential hardening — dedicated encrypted storage, pair contract, fail-before-network.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require $root . '/tests/fixtures/shop_snapshot.php';

use PrestaShop\Module\Unipayment\Configuration\Exception\ShopConfigurationSnapshotValidationException;
use PrestaShop\Module\Unipayment\Configuration\ShopSnapshotSanitizer;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCredentialCipher;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCredentialPairClassifier;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCredentialPersistence;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCredentialRepository;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDiagnosticJournal;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfPayloadBuilder;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfSessionCoordinator;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfSessionGatewayInterface;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfLifecycleStates;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDebugLogStoreInterface;
use PrestaShop\Module\Unipayment\Tests\Support\CredentialAtomicFakeBoundary;
use PrestaShop\Module\Unipayment\Tests\Support\InMemorySmartUcfCredentialSettingStore;
use PrestaShop\Module\Unipayment\Tests\Support\TransactionalMemoryShopConfigurationCache;

if (!defined('_NEW_COOKIE_KEY_')) {
    define('_NEW_COOKIE_KEY_', 'test-key-for-smartucf-credentials-01');
}

if (!class_exists('PhpEncryption', false)) {
    final class PhpEncryption
    {
        /** @var string */
        private $key;

        public function __construct(string $key)
        {
            $this->key = $key;
        }

        public function encrypt(string $plaintext): string
        {
            // Nondeterministic ciphertext for roundtrip / rotation assertions.
            return base64_encode(random_bytes(8) . $this->key . "\0" . $plaintext);
        }

        /** @return string|false */
        public function decrypt(string $ciphertext)
        {
            $decoded = base64_decode($ciphertext, true);
            if (!is_string($decoded)) {
                return false;
            }
            $needle = $this->key . "\0";
            $pos = strpos($decoded, $needle);
            if ($pos === false) {
                return false;
            }

            return substr($decoded, $pos + strlen($needle));
        }
    }
}

if (!class_exists('Configuration', false)) {
    class Configuration
    {
        /** @var array<string, mixed> */
        public static $values = [];

        /**
         * @param mixed $value
         */
        public static function updateValue(string $key, $value, bool $html = false, $idShopGroup = null, $idShop = null): bool
        {
            self::$values[$key] = $value;

            return true;
        }

        /** @return mixed */
        public static function get(string $key, $idLang = null, $idShopGroup = null, $idShop = null, $default = false)
        {
            return self::$values[$key] ?? $default;
        }

        public static function deleteByName(string $key): bool
        {
            unset(self::$values[$key]);

            return true;
        }
    }
}

if (!class_exists('PrestaShopLogger', false)) {
    class PrestaShopLogger
    {
        public static function addLog(string $message, int $severity = 1): void
        {
        }
    }
}

function assertCred(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class CredentialGuardSmartUcfClient implements SmartUcfSessionGatewayInterface
{
    /** @var int */
    public $calls = 0;

    /** @var bool */
    public $succeed = false;

    public function createSession(array $shop, array $snapshot, $certificateLease = null): array
    {
        ++$this->calls;
        if (!$this->succeed) {
            throw new RuntimeException('SmartUCF client should not have been called');
        }

        return [
            'session_id' => 'sess-1',
            'redirect_url' => 'https://www.ucfin.bg/sucf-online/Request/Start?sid=sess-1',
            'http_code' => 200,
            'raw_request' => '{}',
            'raw_response' => '{}',
        ];
    }
}

/**
 * In-memory lifecycle injected via reflection (SmartUcfLifecycleRepository is final).
 */
final class CredentialMemoryLifecycle
{
    /** @var array<string, mixed> */
    public $row;

    public function __construct(array $row)
    {
        $this->row = $row;
    }

    public function readAndNormalize(int $attemptId): ?array
    {
        return $this->row;
    }

    public function claimForSubmitting(int $attemptId): ?array
    {
        if (($this->row['smartucf_state'] ?? '') !== SmartUcfLifecycleStates::NOT_STARTED) {
            return null;
        }
        $this->row['smartucf_state'] = SmartUcfLifecycleStates::SUBMITTING;

        return $this->row;
    }

    public function markCreated(int $attemptId, string $sessionId, string $redirectUrl, int $httpCode = 0): void
    {
        $this->row['smartucf_state'] = SmartUcfLifecycleStates::CREATED;
        $this->row['smartucf_session_id'] = $sessionId;
        $this->row['smartucf_redirect_url'] = $redirectUrl;
    }

    public function markFailed(int $attemptId, string $errorClass, bool $retryable, int $httpCode = 0): void
    {
        $this->row['smartucf_state'] = SmartUcfLifecycleStates::FAILED;
        $this->row['smartucf_error_class'] = $errorClass;
    }

    public function markOutcomeUnknown(int $attemptId, string $errorClass, int $httpCode = 0): void
    {
        $this->row['smartucf_state'] = SmartUcfLifecycleStates::OUTCOME_UNKNOWN;
    }
}

/**
 * @return array{0: InMemorySmartUcfCredentialSettingStore, 1: SmartUcfCredentialRepository, 2: SmartUcfCredentialPersistence, 3: TransactionalMemoryShopConfigurationCache, 4: CredentialAtomicFakeBoundary}
 */
function credWiring(int $shopId = 1): array
{
    $settings = new InMemorySmartUcfCredentialSettingStore();
    $cache = new TransactionalMemoryShopConfigurationCache();
    $boundary = new CredentialAtomicFakeBoundary($settings, $cache);
    $repo = new SmartUcfCredentialRepository($settings, new SmartUcfCredentialCipher(), $shopId);
    $persistence = new SmartUcfCredentialPersistence($repo, $cache, $boundary);

    return [$settings, $repo, $persistence, $cache, $boundary];
}

const CRED_UNICID = '123e4567-e89b-12d3-a456-426614174000';

// --- Pair classifier ---
assertCred(
    SmartUcfCredentialPairClassifier::classify(['uni_user' => 'u', 'uni_password' => 'p'])
        === SmartUcfCredentialPairClassifier::COMPLETE,
    'complete pair'
);
assertCred(
    SmartUcfCredentialPairClassifier::classify(['uni_proces' => 0]) === SmartUcfCredentialPairClassifier::ABSENT,
    'absent pair'
);
assertCred(
    SmartUcfCredentialPairClassifier::classify(['uni_user' => 'u']) === SmartUcfCredentialPairClassifier::INVALID,
    'user only'
);
assertCred(
    SmartUcfCredentialPairClassifier::classify(['uni_password' => 'p']) === SmartUcfCredentialPairClassifier::INVALID,
    'password only'
);
assertCred(
    SmartUcfCredentialPairClassifier::classify(['uni_user' => '', 'uni_password' => 'p'])
        === SmartUcfCredentialPairClassifier::INVALID,
    'blank user'
);
assertCred(
    SmartUcfCredentialPairClassifier::classify(['uni_user' => '  ', 'uni_password' => 'p'])
        === SmartUcfCredentialPairClassifier::INVALID,
    'whitespace user'
);
assertCred(
    SmartUcfCredentialPairClassifier::classify(['uni_user' => 1, 'uni_password' => 'p'])
        === SmartUcfCredentialPairClassifier::INVALID,
    'integer user'
);
assertCred(
    SmartUcfCredentialPairClassifier::classify(['uni_user' => true, 'uni_password' => 'p'])
        === SmartUcfCredentialPairClassifier::INVALID,
    'bool user'
);
assertCred(
    SmartUcfCredentialPairClassifier::classify(['uni_user' => ['x'], 'uni_password' => 'p'])
        === SmartUcfCredentialPairClassifier::INVALID,
    'array user'
);
assertCred(
    SmartUcfCredentialPairClassifier::classify(['uni_user' => 'u', 'uni_password' => (object) ['p' => 1]])
        === SmartUcfCredentialPairClassifier::INVALID,
    'object password'
);

// --- Cache isolation / encryption / Process 1+2 ---
[$settings, $repo, $persistence, $cache] = credWiring();
$snapshot = unipayment_valid_shop_snapshot([
    'uni_user' => 'demo-user',
    'uni_password' => 'demo-secret-password',
    'api_token' => 'should-strip',
]);
$written = $persistence->persistValidatedSnapshot(CRED_UNICID, $snapshot);
assertCred(!array_key_exists('uni_user', $written), 'stripped uni_user from cache write');
assertCred(!array_key_exists('uni_password', $written), 'stripped uni_password from cache write');
assertCred(!array_key_exists('api_token', $written), 'stripped unrelated secret api_token');
assertCred(($written['uni_status'] ?? null) === 1, 'safe field survived');
assertCred(($written['kop']['by_default']['uni_kop_default'] ?? '') === 'KOPSTD', 'nested safe field survived');
assertCred($cache->wroteShopCache, 'cache write executed');
assertCred(!$cache->sqlContainedPlaintextCredential, 'no plaintext credential in cache payload');

$rawUser = $settings->get(1, SmartUcfCredentialRepository::USER_KEY);
$rawPass = $settings->get(1, SmartUcfCredentialRepository::PASSWORD_KEY);
assertCred(is_string($rawUser) && strpos($rawUser, SmartUcfCredentialCipher::PREFIX) === 0, 'user encrypted');
assertCred(is_string($rawPass) && strpos($rawPass, SmartUcfCredentialCipher::PREFIX) === 0, 'password encrypted');
assertCred($rawUser !== 'demo-user' && $rawPass !== 'demo-secret-password', 'ciphertext != plaintext');
assertCred($repo->getUsername() === 'demo-user', 'username roundtrip');
assertCred($repo->getPassword() === 'demo-secret-password', 'password roundtrip');

$firstUser = (string) $rawUser;
$repo->saveCompletePair('demo-user', 'demo-secret-password');
$secondUser = (string) $settings->get(1, SmartUcfCredentialRepository::USER_KEY);
assertCred($firstUser !== $secondUser, 'fresh encryption differs (nondeterministic)');

$settings->set(1, SmartUcfCredentialRepository::USER_KEY, $firstUser . 'x');
assertCred($repo->getUsername() === null && $repo->getPassword() === null, 'tampered envelope rejects whole pair');

$settings->set(1, SmartUcfCredentialRepository::USER_KEY, 'plaintext-not-allowed');
$settings->set(1, SmartUcfCredentialRepository::PASSWORD_KEY, $secondUser);
assertCred($repo->getUsername() === null, 'plaintext fallback rejected');

$repo->saveCompletePair('keep-user', 'keep-pass');
$prior = $repo->captureRawPair();
foreach ([
    'absent' => (static function (): array {
        $s = unipayment_valid_shop_snapshot(['uni_proces' => 0]);
        unset($s['uni_user'], $s['uni_password']);

        return $s;
    })(),
    'user only' => (static function (): array {
        $s = unipayment_valid_shop_snapshot(['uni_user' => 'u']);
        unset($s['uni_password']);

        return $s;
    })(),
] as $label => $bad) {
    try {
        $persistence->persistValidatedSnapshot(CRED_UNICID, $bad);
        assertCred(false, 'P1 should reject: ' . $label);
    } catch (ShopConfigurationSnapshotValidationException $e) {
        assertCred($e->violations() !== [], 'P1 rejection violations: ' . $label);
    }
    assertCred($repo->captureRawPair() === $prior, 'P1 reject no credential mutation: ' . $label);
}

// Process 2 absent preserves byte-for-byte
$repo->saveCompletePair('p2-user', 'p2-pass');
$prior = $repo->captureRawPair();
$p2 = unipayment_valid_shop_snapshot(['uni_proces' => 1]);
unset($p2['uni_user'], $p2['uni_password']);
$writtenP2 = $persistence->persistValidatedSnapshot(CRED_UNICID, $p2);
assertCred($repo->captureRawPair() === $prior, 'P2 absent preserves raw pair');
assertCred(!array_key_exists('uni_user', $writtenP2), 'P2 cache still credential-free');

$rotated = unipayment_valid_shop_snapshot([
    'uni_proces' => 1,
    'uni_user' => 'new-user',
    'uni_password' => 'new-pass',
]);
$persistence->persistValidatedSnapshot(CRED_UNICID, $rotated);
assertCred($repo->getUsername() === 'new-user' && $repo->getPassword() === 'new-pass', 'P2 complete rotates');
$afterRotate = $repo->captureRawPair();
$partial = unipayment_valid_shop_snapshot(['uni_proces' => 1, 'uni_user' => 'only']);
unset($partial['uni_password']);
try {
    $persistence->persistValidatedSnapshot(CRED_UNICID, $partial);
    assertCred(false, 'P2 partial should reject');
} catch (ShopConfigurationSnapshotValidationException $e) {
    assertCred(true, 'P2 partial rejected');
}
assertCred($repo->captureRawPair() === $afterRotate, 'P2 partial no mutation');

// Multishop isolation
[, $repoA] = credWiring(10);
[, $repoB, $persistenceB] = credWiring(20);
// shared settings store for true isolation test:
$shared = new InMemorySmartUcfCredentialSettingStore();
$repoA = new SmartUcfCredentialRepository($shared, new SmartUcfCredentialCipher(), 10);
$repoB = new SmartUcfCredentialRepository($shared, new SmartUcfCredentialCipher(), 20);
$repoA->saveCompletePair('shop-a', 'pass-a');
$repoB->saveCompletePair('shop-b', 'pass-b');
assertCred($repoA->getUsername() === 'shop-a' && $repoB->getUsername() === 'shop-b', 'multishop isolated');
$repoA->saveCompletePair('shop-a2', 'pass-a2');
assertCred($repoB->getUsername() === 'shop-b' && $repoB->getPassword() === 'pass-b', 'rotation A leaves B');
$repoA->uninstallAllShops();
assertCred($repoA->getUsername() === null && $repoB->getUsername() === null, 'uninstall clears all shops');

// Atomicity
[$settings, $repo, $persistence, $cache, $boundary] = credWiring();
$repo->saveCompletePair('old-u', 'old-p');
$prior = $repo->captureRawPair();
$settings->setCount = 0;
$settings->failOnSetNumber = 1;
try {
    $persistence->persistValidatedSnapshot(
        CRED_UNICID,
        unipayment_valid_shop_snapshot(['uni_user' => 'new-u', 'uni_password' => 'new-p'])
    );
    assertCred(false, 'expected first write failure');
} catch (RuntimeException $e) {
    assertCred(strpos($e->getMessage(), 'Forced setting write failure') !== false, 'first write failure message');
}
assertCred($repo->captureRawPair() === $prior, 'rollback after first write failure');
assertCred($cache->getCommitted(CRED_UNICID) === null, 'cache not committed after first failure');

$settings->setCount = 0;
$settings->failOnSetNumber = 2;
$cache->wroteShopCache = false;
try {
    $persistence->persistValidatedSnapshot(
        CRED_UNICID,
        unipayment_valid_shop_snapshot(['uni_user' => 'new-u', 'uni_password' => 'new-p'])
    );
    assertCred(false, 'expected second write failure');
} catch (RuntimeException $e) {
    assertCred(true, 'second write failure');
}
assertCred($repo->captureRawPair() === $prior, 'rollback after second write failure');
assertCred($repo->getUsername() === 'old-u', 'no mixed pair after second failure');

$settings->failOnSetNumber = 0;
$cache->failNextReplace = true;
try {
    $persistence->persistValidatedSnapshot(
        CRED_UNICID,
        unipayment_valid_shop_snapshot(['uni_user' => 'new-u', 'uni_password' => 'new-p'])
    );
    assertCred(false, 'expected cache failure');
} catch (RuntimeException $e) {
    assertCred(strpos($e->getMessage(), 'Forced cache persistence failure') !== false, 'cache failure');
}
assertCred($repo->captureRawPair() === $prior, 'rollback after cache failure');

$writtenOk = $persistence->persistValidatedSnapshot(
    CRED_UNICID,
    unipayment_valid_shop_snapshot(['uni_user' => 'ok-u', 'uni_password' => 'ok-p'])
);
assertCred($repo->getUsername() === 'ok-u', 'successful pair commit');
assertCred(!array_key_exists('uni_user', $writtenOk), 'successful sanitized cache');
assertCred($boundary->openTransactions === 0, 'no open transactions after success');

$boundary->failNextLock = true;
$prior = $repo->captureRawPair();
try {
    $persistence->persistValidatedSnapshot(
        CRED_UNICID,
        unipayment_valid_shop_snapshot(['uni_user' => 'x', 'uni_password' => 'y'])
    );
    assertCred(false, 'expected lock failure');
} catch (RuntimeException $e) {
    assertCred(strpos($e->getMessage(), 'exclusive mutation lock') !== false, 'lock failure');
}
assertCred($repo->captureRawPair() === $prior, 'lock failure no mutation');

// Runtime hydration
$hydrated = $repo->hydrateShopSnapshot($cache->getCommitted(CRED_UNICID) ?? []);
assertCred(($hydrated['uni_user'] ?? '') === 'ok-u', 'hydrate user');
assertCred(($hydrated['uni_password'] ?? '') === 'ok-p', 'hydrate password');
$committed = $cache->getCommitted(CRED_UNICID);
assertCred($committed !== null && !array_key_exists('uni_user', $committed), 'hydrate never writes back');

$settings->set(1, SmartUcfCredentialRepository::USER_KEY, (string) $settings->get(1, SmartUcfCredentialRepository::USER_KEY) . 'corrupt');
$neither = $repo->hydrateShopSnapshot(['foo' => 1]);
assertCred(!array_key_exists('uni_user', $neither) && !array_key_exists('uni_password', $neither), 'corrupt → neither');

// Process 1 fail-before-network
$client = new CredentialGuardSmartUcfClient();
$lifecycle = new CredentialMemoryLifecycle([
    'id_attempt' => 1,
    'order_reference' => 'REF-1',
    'smartucf_state' => SmartUcfLifecycleStates::NOT_STARTED,
    'smartucf_redirect_url' => '',
    'smartucf_session_id' => '',
]);
$snapshotRow = [
    'id_attempt' => 1,
    'id_order' => 9,
    'order_reference' => 'REF-1',
    'customer_json' => ['first_name' => 'A', 'last_name' => 'B', 'phone' => '1', 'email' => 'a@b.c'],
    'lines_json' => [['name' => 'Item', 'id_product' => 1, 'quantity' => 1, 'total' => 100]],
    'address_json' => ['address1' => 'Addr', 'city' => 'Sofia', 'postcode' => '1000'],
    'kop_code' => 'KOP',
    'order_total' => 100,
    'first_installment' => 0,
    'months' => 12,
    'monthly_installment' => 10,
    'currency_iso' => 'BGN',
];
$ref = new ReflectionClass(SmartUcfSessionCoordinator::class);
/** @var SmartUcfSessionCoordinator $coordinator */
$coordinator = $ref->newInstanceWithoutConstructor();
foreach ([
    'lifecycle' => $lifecycle,
    'client' => $client,
    'payloadBuilder' => new SmartUcfPayloadBuilder(),
    'classifier' => new \PrestaShop\Module\Unipayment\SmartUcf\SmartUcfFailureClassifier(),
    'snapshots' => null,
    'cpClient' => null,
    'controlPanelApi' => null,
    'certificateSynchronizer' => null,
    'module' => null,
    'context' => null,
    'statusSync' => null,
] as $name => $value) {
    $prop = $ref->getProperty($name);
    $prop->setAccessible(true);
    $prop->setValue($coordinator, $value);
}
$shopMissing = unipayment_valid_shop_snapshot(['uni_sertificat' => 0]);
unset($shopMissing['uni_user'], $shopMissing['uni_password']);
$result = $coordinator->run(1, $shopMissing, false, $snapshotRow);
assertCred($result->isFailed(), 'missing credentials fail locally');
assertCred($result->errorClass() === SmartUcfSessionCoordinator::ERROR_CREDENTIALS_UNAVAILABLE, 'credentials unavailable class');
assertCred($client->calls === 0, 'no SmartUCF client call');
assertCred(($lifecycle->row['smartucf_state'] ?? '') === SmartUcfLifecycleStates::NOT_STARTED, 'no claim on credential miss');

$client->succeed = true;
$lifecycle->row['smartucf_state'] = SmartUcfLifecycleStates::NOT_STARTED;
$shopOk = unipayment_valid_shop_snapshot([
    'uni_user' => 'demo-user',
    'uni_password' => 'demo-secret-password',
    'uni_sertificat' => 0,
]);
$resultOk = $coordinator->run(1, $shopOk, false, $snapshotRow);
assertCred($resultOk->isCreated(), 'repaired pair proceeds');
assertCred($client->calls === 1, 'client called once');

try {
    (new SmartUcfPayloadBuilder())->build(['uni_user' => '', 'uni_password' => 'x'], [
        'order_reference' => 'R',
        'customer_json' => [],
        'lines_json' => [],
        'address_json' => [],
        'kop_code' => 'K',
        'order_total' => 1,
        'first_installment' => 0,
        'months' => 3,
        'monthly_installment' => 1,
    ]);
    assertCred(false, 'payload builder must reject empty credentials');
} catch (InvalidArgumentException $e) {
    assertCred(true, 'payload defense');
}

// Diagnostics
Configuration::$values[ConfigurationRepository::DEBUG_ENABLED] = true;
$store = new class implements SmartUcfDebugLogStoreInterface {
    /** @var list<array<string, mixed>> */
    public $entries = [];

    public function insert(array $entry): bool
    {
        $this->entries[] = $entry;

        return true;
    }

    public function findLatestByOrderIdAndShop(string $orderId, int $idShop): ?array
    {
        unset($orderId, $idShop);

        return null;
    }

    public function findAll(): array
    {
        return $this->entries;
    }

    public function prune(?DateTimeImmutable $now = null): bool
    {
        unset($now);

        return true;
    }
};
$journal = new SmartUcfDiagnosticJournal(new ConfigurationRepository(), $store);
$journal->record(1, 99, 'ORD', 200, [
    'user' => 'u-secret',
    'pass' => 'p-secret',
    'uni_user' => 'uu',
    'uni_password' => 'up',
    'sucfOnlineSessionID' => 'KEEP-SESSION-ID',
], []);
$entry = $store->entries[0];
$req = $entry['request'];
assertCred($req['user'] === '[REDACTED]', 'redact user');
assertCred($req['pass'] === '[REDACTED]', 'redact pass');
assertCred($req['uni_user'] === '[REDACTED]', 'redact uni_user');
assertCred($req['uni_password'] === '[REDACTED]', 'redact uni_password');
assertCred($req['sucfOnlineSessionID'] === 'KEEP-SESSION-ID', 'session id visible');

// Sanitizer keeps known credentials, strips unknown secrets and credential case-variants
$san = ShopSnapshotSanitizer::sanitize([
    'uni_user' => 'keep-user',
    'uni_password' => 'keep-pass',
    'UNI_PASSWORD' => 'variant',
    'uni_zaglavie' => 'Title',
    'access_token' => 'nope',
]);
assertCred(($san['uni_user'] ?? '') === 'keep-user', 'sanitizer keeps uni_user');
assertCred(!array_key_exists('UNI_PASSWORD', $san), 'sanitizer strips UNI_PASSWORD variant');
assertCred(($san['uni_zaglavie'] ?? '') === 'Title', 'sanitizer keeps safe fields');
assertCred(!array_key_exists('access_token', $san), 'sanitizer strips access_token');

// Install without credentials: empty repo is fine
$fresh = new SmartUcfCredentialRepository(new InMemorySmartUcfCredentialSettingStore(), new SmartUcfCredentialCipher(), 1);
assertCred(!$fresh->hasCompleteReadablePair(), 'installable before provisioning');

fwrite(STDOUT, "OK (SmartUCF credential hardening)\n");
