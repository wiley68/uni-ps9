<?php

declare(strict_types=1);

/**
 * Codex remediation: exact-context credentials, coherent runtime read, boundary failures, sanitizer variants.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require $root . '/tests/fixtures/shop_snapshot.php';

use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationService;
use PrestaShop\Module\Unipayment\Configuration\ShopSnapshotSanitizer;
use PrestaShop\Module\Unipayment\Infrastructure\DbMutationBoundary;
use PrestaShop\Module\Unipayment\Infrastructure\ImmediateMutationBoundary;
use PrestaShop\Module\Unipayment\Security\TokenRepository;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCredentialCipher;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCredentialPersistence;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCredentialRepository;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCredentialSettingStoreInterface;
use PrestaShop\Module\Unipayment\Tests\Support\CredentialAtomicFakeBoundary;
use PrestaShop\Module\Unipayment\Tests\Support\InMemorySmartUcfCredentialSettingStore;
use PrestaShop\Module\Unipayment\Tests\Support\TransactionalMemoryShopConfigurationCache;
use PrestaShop\Module\Unipayment\Api\ShopConfigurationProviderInterface;

if (!defined('_NEW_COOKIE_KEY_')) {
    define('_NEW_COOKIE_KEY_', 'remediation-cookie-key-32bytes!!');
}
if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}

if (!function_exists('pSQL')) {
    /**
     * @param mixed $string
     */
    function pSQL($string, $htmlOK = false)
    {
        return addslashes((string) $string);
    }
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
            return base64_encode($this->key . "\0" . $plaintext);
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
            if ($pos !== 0) {
                return false;
            }

            return substr($decoded, strlen($needle));
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
        /** @var list<string> */
        public static $messages = [];

        public static function addLog(string $message, int $severity = 1): void
        {
            self::$messages[] = $message;
        }
    }
}

function assertRem(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * Exact-context store: records SQL pair reads; never returns group/global pollution.
 */
final class ExactContextFakeCredentialStore implements SmartUcfCredentialSettingStoreInterface
{
    /** @var array<int, array{user: ?string, password: ?string}> */
    public $shopPairs = [];

    /** @var array{user: ?string, password: ?string}|null */
    public $groupPair = null;

    /** @var array{user: ?string, password: ?string}|null */
    public $globalPair = null;

    /** @var int */
    public $pairQueryCount = 0;

    /** @var list<int> */
    public $pairQueryShops = [];

    /** @return array{user: ?string, password: ?string} */
    public function getPair(int $idShop): array
    {
        ++$this->pairQueryCount;
        $this->pairQueryShops[] = $idShop;

        return $this->shopPairs[$idShop] ?? ['user' => null, 'password' => null];
    }

    public function get(int $idShop, string $key): ?string
    {
        $pair = $this->getPair($idShop);
        if ($key === SmartUcfCredentialRepository::USER_KEY) {
            return $pair['user'];
        }
        if ($key === SmartUcfCredentialRepository::PASSWORD_KEY) {
            return $pair['password'];
        }

        return null;
    }

    public function set(int $idShop, string $key, string $value): void
    {
        if (!isset($this->shopPairs[$idShop])) {
            $this->shopPairs[$idShop] = ['user' => null, 'password' => null];
        }
        if ($key === SmartUcfCredentialRepository::USER_KEY) {
            $this->shopPairs[$idShop]['user'] = $value;
        } elseif ($key === SmartUcfCredentialRepository::PASSWORD_KEY) {
            $this->shopPairs[$idShop]['password'] = $value;
        }
    }

    public function delete(int $idShop, string $key): void
    {
        if (!isset($this->shopPairs[$idShop])) {
            return;
        }
        if ($key === SmartUcfCredentialRepository::USER_KEY) {
            $this->shopPairs[$idShop]['user'] = null;
        } elseif ($key === SmartUcfCredentialRepository::PASSWORD_KEY) {
            $this->shopPairs[$idShop]['password'] = null;
        }
    }

    public function deleteByNameAllShops(string $key): void
    {
        $this->shopPairs = [];
        $this->groupPair = null;
        $this->globalPair = null;
    }
}

final class RecordingCacheForCoherence extends TransactionalMemoryShopConfigurationCache
{
    /** @var list<string> */
    public $events = [];

    /** @var CredentialAtomicFakeBoundary|null */
    public $boundary = null;

    public function getFresh(string $unicid): ?array
    {
        $holdingLock = $this->boundary !== null && $this->boundary->heldLocks !== [];
        $this->events[] = $holdingLock ? 'cache_read_under_lock' : 'cache_read_without_lock';

        return parent::getFresh($unicid);
    }
}

final class RemediationFakeProvider implements ShopConfigurationProviderInterface
{
    /** @var int */
    public $calls = 0;

    /** @var array<int, array<string, mixed>> */
    public $responses = [];

    public function getShop(): array
    {
        ++$this->calls;
        $response = array_shift($this->responses);
        if (!is_array($response)) {
            throw new RuntimeException('No provider response');
        }

        return $response;
    }
}

final class FakeDbForBoundary
{
    /** @var list<string> */
    public $queries = [];

    public $failGetLock = false;
    public $failStart = false;
    public $failCommit = false;
    public $failRollback = false;
    public $rollbackThrows = false;
    public $releaseValue = 1;
    public $releaseThrows = false;

    public function getValue(string $sql)
    {
        $this->queries[] = $sql;
        if (stripos($sql, 'GET_LOCK') !== false) {
            return $this->failGetLock ? 0 : 1;
        }
        if (stripos($sql, 'RELEASE_LOCK') !== false) {
            if ($this->releaseThrows) {
                throw new RuntimeException('forced RELEASE_LOCK exception');
            }

            return $this->releaseValue;
        }

        return false;
    }

    public function execute(string $sql): bool
    {
        $this->queries[] = $sql;
        if (stripos($sql, 'START TRANSACTION') !== false) {
            return !$this->failStart;
        }
        if (stripos($sql, 'COMMIT') !== false) {
            return !$this->failCommit;
        }
        if (stripos($sql, 'ROLLBACK') !== false) {
            if ($this->rollbackThrows) {
                throw new RuntimeException('forced ROLLBACK exception');
            }

            return !$this->failRollback;
        }

        return true;
    }
}

const REM_UNICID = '123e4567-e89b-12d3-a456-426614174000';

// --- Sanitizer: exact keep, variants strip ---
$san = ShopSnapshotSanitizer::sanitize([
    'uni_user' => 'keep',
    'uni_password' => 'keep-pass',
    'UNI_USER' => 'strip-me',
    'UNI_PASSWORD' => 'strip-me',
    'Uni_User' => 'strip-me',
    'Uni_Password' => 'strip-me',
    'UniPassword' => 'strip-me',
    'future_safe_field' => 'ok',
    'access_token' => 'nope',
]);
assertRem(($san['uni_user'] ?? null) === 'keep', 'exact uni_user kept for classification');
assertRem(($san['uni_password'] ?? null) === 'keep-pass', 'exact uni_password kept');
assertRem(!isset($san['UNI_USER']), 'UNI_USER stripped');
assertRem(!isset($san['UNI_PASSWORD']), 'UNI_PASSWORD stripped');
assertRem(!isset($san['Uni_User']), 'Uni_User stripped');
assertRem(!isset($san['Uni_Password']), 'Uni_Password stripped');
assertRem(!isset($san['UniPassword']), 'UniPassword stripped');
assertRem(($san['future_safe_field'] ?? null) === 'ok', 'safe unknown survives');
assertRem(!isset($san['access_token']), 'access_token stripped');

// Persistence leaves neither canonical nor variants in cache
$settings = new InMemorySmartUcfCredentialSettingStore();
$cache = new TransactionalMemoryShopConfigurationCache();
$boundary = new CredentialAtomicFakeBoundary($settings, $cache);
$repo = new SmartUcfCredentialRepository($settings, new SmartUcfCredentialCipher(), 1);
$persistence = new SmartUcfCredentialPersistence($repo, $cache, $boundary);
$written = $persistence->persistValidatedSnapshot(REM_UNICID, unipayment_valid_shop_snapshot([
    'UNI_USER' => 'variant-user',
    'UNI_PASSWORD' => 'variant-pass',
    'UniPassword' => 'variant-camel',
]));
assertRem(!isset($written['uni_user']) && !isset($written['uni_password']), 'canonical stripped after persist');
assertRem(!isset($written['UNI_USER']) && !isset($written['UNI_PASSWORD']) && !isset($written['UniPassword']), 'variants stripped after persist');

// --- Multishop exact isolation / no group-global fallback ---
$exactStore = new ExactContextFakeCredentialStore();
$cipher = new SmartUcfCredentialCipher();
$encAUser = $cipher->encrypt('shop-a-user');
$encAPass = $cipher->encrypt('shop-a-pass');
$encBUser = $cipher->encrypt('shop-b-user');
$encBPass = $cipher->encrypt('shop-b-pass');
$exactStore->shopPairs[10] = ['user' => $encAUser, 'password' => $encAPass];
$exactStore->shopPairs[20] = ['user' => $encBUser, 'password' => $encBPass];
$exactStore->groupPair = ['user' => $cipher->encrypt('group-user'), 'password' => $cipher->encrypt('group-pass')];
$exactStore->globalPair = ['user' => $cipher->encrypt('global-user'), 'password' => $cipher->encrypt('global-pass')];

$repoA = new SmartUcfCredentialRepository($exactStore, $cipher, 10);
$repoB = new SmartUcfCredentialRepository($exactStore, $cipher, 20);
assertRem($repoA->getUsername() === 'shop-a-user', 'shop A exact user');
assertRem($repoB->getUsername() === 'shop-b-user', 'shop B exact user');

$exactStore->pairQueryCount = 0;
unset($exactStore->shopPairs[20]);
$repoBMissing = new SmartUcfCredentialRepository($exactStore, $cipher, 20);
assertRem($repoBMissing->hasCompleteReadablePair() === false, 'missing B does not inherit A/group/global');
assertRem($exactStore->pairQueryCount === 1, 'pair fetched in one exact-context query for decrypt');
// hydrate uses one captureRawPair / getPair
$exactStore->pairQueryCount = 0;
$hydratedMissing = $repoBMissing->hydrateShopSnapshot(['x' => 1]);
assertRem(!isset($hydratedMissing['uni_user']) && !isset($hydratedMissing['uni_password']), 'missing exact → neither hydrated');
assertRem($exactStore->pairQueryCount === 1, 'hydrate uses single pair query');

// one missing exact side → neither
$exactStore->shopPairs[30] = ['user' => $encAUser, 'password' => null];
$repoPartial = new SmartUcfCredentialRepository($exactStore, $cipher, 30);
$exactStore->pairQueryCount = 0;
assertRem($repoPartial->hasCompleteReadablePair() === false, 'one missing side → neither');
assertRem($exactStore->pairQueryCount === 1, 'partial pair still one query');

// prove group/global fixtures are never consulted by getPair
assertRem($exactStore->groupPair !== null && $exactStore->globalPair !== null, 'group/global fixtures present');
assertRem($repoBMissing->hasCompleteReadablePair() === false, 'unavailable without exact shop pair');

// --- Coherent reader/writer lock ---
$settings = new InMemorySmartUcfCredentialSettingStore();
$cache = new RecordingCacheForCoherence();
$boundary = new CredentialAtomicFakeBoundary($settings, $cache);
$cache->boundary = $boundary;
$repo = new SmartUcfCredentialRepository($settings, new SmartUcfCredentialCipher(), 7);
$persistence = new SmartUcfCredentialPersistence($repo, $cache, $boundary);
Configuration::$values[ConfigurationRepository::UNICID] = REM_UNICID;
Configuration::$values[ConfigurationRepository::SECRET] = 'enc:v1:x';
$tokens = new TokenRepository();
$tokens->save('t', 'Bearer', time() + 1000);
$provider = new RemediationFakeProvider();
$service = new ShopConfigurationService(
    new ConfigurationRepository(),
    $cache,
    $provider,
    $tokens,
    null,
    $repo,
    $persistence,
    $boundary
);

$expectedLock = DbMutationBoundary::smartUcfCredentialLockName(7, REM_UNICID);
$provider->responses[] = ['success' => true, 'data' => unipayment_valid_shop_snapshot([
    'uni_user' => 'old-u',
    'uni_password' => 'old-p',
    'uni_env' => 0,
    'uni_test_service' => 'https://onlinetest.ucfin.bg/suos/api/otp/',
])];
$runtime1 = $service->get(true);
assertRem(($runtime1['uni_user'] ?? '') === 'old-u', 'initial coherent hydrate');
assertRem(in_array('cache_read_under_lock', $cache->events, true), 'cache read under lock');
assertRem(!in_array('cache_read_without_lock', $cache->events, true), 'no cache read before lock');
assertRem($boundary->lockAcquireOrder !== [], 'reader/writer acquired lock');
assertRem($boundary->lockAcquireOrder[0] === $expectedLock, 'same lock scope as writer');
assertRem($boundary->heldLocks === [], 'lock released after normal read');
assertRem(in_array($expectedLock, $boundary->lockReleaseOrder, true), 'lock release recorded');

// Writer cannot commit between cache and credential reads: mid-callback mutation attempt
$midEvents = [];
$boundary->onBeforeCallback = function (string $lock) use ($boundary, &$midEvents): void {
    $midEvents[] = 'entered_with_lock:' . $lock;
    assertRem(isset($boundary->heldLocks[$lock]), 'lock held before cache/credential reads');
};
$cache->events = [];
$runtimeHit = $service->get();
assertRem(($runtimeHit['uni_user'] ?? '') === 'old-u', 'coherent hit preserves pair');
assertRem($cache->events === ['cache_read_under_lock'], 'hit cache only under lock');

// Version skew blocked: update under exclusive lock yields old/old or new/new only
$provider->responses[] = ['success' => true, 'data' => unipayment_valid_shop_snapshot([
    'uni_user' => 'new-u',
    'uni_password' => 'new-p',
    'uni_env' => 1,
    'uni_production_service' => 'https://online.ucfin.bg/suos/api/otp/',
    'uni_production_application' => 'https://online.ucfin.bg/sucf-online/Request/Start',
])];
$runtime2 = $service->get(true);
$user = (string) ($runtime2['uni_user'] ?? '');
$env = (int) ($runtime2['uni_env'] ?? -1);
assertRem(
    ($user === 'new-u' && $env === 1) || ($user === 'old-u' && $env === 0),
    'no mixed endpoint/env + credential skew'
);
assertRem($user === 'new-u' && $env === 1, 'after refresh see new cache + new credentials');

// Lock released on read exception
$boundary->lockReleaseOrder = [];
$boundary->onBeforeCallback = function (): void {
    throw new RuntimeException('forced read failure');
};
try {
    $service->get();
    assertRem(false, 'expected read exception');
} catch (RuntimeException $e) {
    assertRem(strpos($e->getMessage(), 'forced read failure') !== false, 'read exception surfaced');
}
assertRem($boundary->heldLocks === [], 'lock released on read exception');
assertRem($boundary->lockReleaseOrder !== [], 'release after read exception');
$boundary->onBeforeCallback = null;

// --- DbMutationBoundary failure injection ---
$fakeDb = new FakeDbForBoundary();
$realBoundary = new DbMutationBoundary($fakeDb);
PrestaShopLogger::$messages = [];

try {
    $fakeDb->failGetLock = true;
    $realBoundary->runExclusive('unipay_sucf_cred_1_abc', static function () {
        return true;
    });
    assertRem(false, 'lock acquire failure expected');
} catch (RuntimeException $e) {
    assertRem(strpos($e->getMessage(), 'exclusive mutation lock') !== false, 'lock acquire failure');
}

$fakeDb = new FakeDbForBoundary();
$realBoundary = new DbMutationBoundary($fakeDb);
try {
    $fakeDb->failStart = true;
    $realBoundary->runExclusive('unipay_sucf_cred_1_abc', static function () {
        return true;
    });
    assertRem(false, 'start failure expected');
} catch (RuntimeException $e) {
    assertRem(strpos($e->getMessage(), 'start') !== false, 'start transaction failure');
}

$fakeDb = new FakeDbForBoundary();
$realBoundary = new DbMutationBoundary($fakeDb);
try {
    $fakeDb->failCommit = true;
    $realBoundary->runExclusive('unipay_sucf_cred_1_abc', static function () {
        return 'x';
    });
    assertRem(false, 'commit failure expected');
} catch (RuntimeException $e) {
    assertRem(strpos($e->getMessage(), 'commit') !== false, 'commit failure');
}

$fakeDb = new FakeDbForBoundary();
$realBoundary = new DbMutationBoundary($fakeDb);
PrestaShopLogger::$messages = [];
try {
    $fakeDb->failRollback = true;
    $realBoundary->runExclusive('unipay_sucf_cred_1_abc', static function () {
        throw new RuntimeException('write boom');
    });
    assertRem(false, 'rollback failure expected');
} catch (RuntimeException $e) {
    assertRem(strpos($e->getMessage(), 'ROLLBACK also failed') !== false, 'rollback failure surfaced');
    assertRem($e->getPrevious() instanceof RuntimeException, 'original exception preserved');
}
assertRem(PrestaShopLogger::$messages !== [], 'rollback failure logged');

$fakeDb = new FakeDbForBoundary();
$realBoundary = new DbMutationBoundary($fakeDb);
try {
    $fakeDb->rollbackThrows = true;
    $realBoundary->runExclusive('unipay_sucf_cred_1_abc', static function () {
        throw new RuntimeException('write boom');
    });
    assertRem(false, 'rollback throw expected');
} catch (RuntimeException $e) {
    assertRem(strpos($e->getMessage(), 'ROLLBACK also failed') !== false, 'rollback throw surfaced');
}

// Successful commit + bad RELEASE_LOCK: must still return committed result (not retryable mutation failure)
$fakeDb = new FakeDbForBoundary();
$fakeDb->releaseValue = 0;
$realBoundary = new DbMutationBoundary($fakeDb);
PrestaShopLogger::$messages = [];
$result = $realBoundary->runExclusive('unipay_sucf_cred_1_abc', static function () {
    return 'committed-ok';
});
assertRem($result === 'committed-ok', 'post-commit release anomaly does not undo success');
assertRem(PrestaShopLogger::$messages !== [], 'release failure logged after commit');

$fakeDb = new FakeDbForBoundary();
$fakeDb->releaseThrows = true;
$realBoundary = new DbMutationBoundary($fakeDb);
PrestaShopLogger::$messages = [];
$result = $realBoundary->runExclusive('unipay_sucf_cred_1_abc', static function () {
    return 'committed-ok-2';
});
assertRem($result === 'committed-ok-2', 'RELEASE_LOCK throw after commit does not fail mutation');

// Release failure without commit (during error path) surfaces
$fakeDb = new FakeDbForBoundary();
$fakeDb->releaseValue = 0;
$realBoundary = new DbMutationBoundary($fakeDb);
try {
    $realBoundary->runExclusive('unipay_sucf_cred_1_abc', static function () {
        throw new RuntimeException('write boom');
    });
    assertRem(false, 'expected release failure on error path');
} catch (RuntimeException $e) {
    // Either original write boom or release failure — both acceptable; must not claim safe rollback silence.
    assertRem(
        strpos($e->getMessage(), 'write boom') !== false
        || strpos($e->getMessage(), 'not released') !== false
        || strpos($e->getMessage(), 'RELEASE_LOCK') !== false,
        'error-path release/write failure surfaced'
    );
}

fwrite(STDOUT, "OK (SmartUCF credential hardening Codex remediation)\n");
