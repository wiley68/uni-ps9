<?php

declare(strict_types=1);

/**
 * Exact-context SmartUCF credential store V2:
 * - no automatic global relocation
 * - duplicate exact rows fail closed
 * - writes replace to exactly one USER + one PASSWORD
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require $root . '/tests/fixtures/shop_snapshot.php';

use PrestaShop\Module\Unipayment\Api\ShopConfigurationProviderInterface;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Infrastructure\ImmediateMutationBoundary;
use PrestaShop\Module\Unipayment\Order\BankStatus;
use PrestaShop\Module\Unipayment\Order\BankStatusPersistencePort;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotStoreInterface;
use PrestaShop\Module\Unipayment\Order\LeasingMailDispatchPort;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrationResult;
use PrestaShop\Module\Unipayment\Order\PostControlPanelLifecycleContext;
use PrestaShop\Module\Unipayment\Order\PostControlPanelLifecycleService;
use PrestaShop\Module\Unipayment\Order\PostControlPanelSmartUcfPort;
use PrestaShop\Module\Unipayment\Security\TokenRepository;
use PrestaShop\Module\Unipayment\SmartUcf\ConfigurationSmartUcfCredentialSettingStore;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCoordinationResult;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCredentialCipher;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCredentialPersistence;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCredentialRepository;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfFailureClassification;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfSessionCoordinator;
use PrestaShop\Module\Unipayment\Tests\Support\CredentialAtomicFakeBoundary;
use PrestaShop\Module\Unipayment\Tests\Support\InMemorySmartUcfCredentialSettingStore;
use PrestaShop\Module\Unipayment\Tests\Support\ShopConfigurationCredentialWiring;
use PrestaShop\Module\Unipayment\Tests\Support\TransactionalMemoryShopConfigurationCache;

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'test_');
}

if (!defined('_NEW_COOKIE_KEY_')) {
    define('_NEW_COOKIE_KEY_', 'test-key-for-smartucf-credentials-01');
}

if (!function_exists('pSQL')) {
    function pSQL(string $string, bool $htmlOK = false): string
    {
        unset($htmlOK);

        return addslashes($string);
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
            return base64_encode($this->key . '|' . $plaintext);
        }

        /** @return string|false */
        public function decrypt(string $ciphertext)
        {
            $decoded = base64_decode($ciphertext, true);
            if ($decoded === false) {
                return false;
            }
            $prefix = $this->key . '|';
            if (strpos($decoded, $prefix) !== 0) {
                return false;
            }

            return substr($decoded, strlen($prefix));
        }
    }
}

if (!class_exists('Shop', false)) {
    class Shop
    {
        /** @var bool */
        public static $featureActive = false;

        /** @var array<int, array{id_shop:int, id_shop_group:int}> */
        public static $shops = [
            1 => ['id_shop' => 1, 'id_shop_group' => 1],
        ];

        /** @var int */
        public int $id_shop_group = 0;

        public function __construct(int $idShop)
        {
            $this->id_shop_group = (int) (self::$shops[$idShop]['id_shop_group'] ?? 0);
        }

        public static function isFeatureActive(): bool
        {
            return self::$featureActive;
        }

        /** @return int|false */
        public static function getGroupFromShop(int $idShop, bool $asId = true)
        {
            unset($asId);
            if (!isset(self::$shops[$idShop])) {
                return false;
            }

            return (int) self::$shops[$idShop]['id_shop_group'];
        }

        /** @return array<int, array{id_shop:int, id_shop_group:int}> */
        public static function getShops(bool $active = true)
        {
            unset($active);

            return self::$shops;
        }

        /** @return null */
        public static function getContextShopID(bool $null_if_not_exist = false)
        {
            unset($null_if_not_exist);

            return null;
        }

        /** @return null */
        public static function getContextShopGroupID(bool $null_if_not_exist = false)
        {
            unset($null_if_not_exist);

            return null;
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
            unset($html, $idShopGroup, $idShop);
            self::$values[$key] = $value;

            return true;
        }

        /** @return mixed */
        public static function get(string $key, $idLang = null, $idShopGroup = null, $idShop = null, $default = false)
        {
            unset($idLang, $idShopGroup, $idShop);

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
        public static $logs = [];

        public static function addLog(string $message, int $severity = 1): void
        {
            unset($severity);
            self::$logs[] = $message;
        }
    }
}

function assertScope(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * In-memory Db double modelling duplicate rows, counts, and exact-context mutations.
 */
final class ExactScopeFakeDb
{
    /** @var int */
    private $nextId = 1;

    /** @var array<int, array{id_configuration:int, name:string, value:?string, id_shop:?int, id_shop_group:?int}> */
    public $rows = [];

    /** @var bool */
    public $failNextInsert = false;

    /** @return list<array<string, mixed>>|false */
    public function executeS(string $sql)
    {
        if (!preg_match('/FROM `?test_configuration`?.*WHERE `name` IN \(\'([^\']+)\', \'([^\']+)\'\)(.*)$/s', $sql, $m)) {
            return [];
        }
        $names = [$m[1], $m[2]];
        $restriction = $m[3];
        $out = [];
        foreach ($this->rows as $row) {
            if (!in_array($row['name'], $names, true)) {
                continue;
            }
            if (!$this->matchesRestriction($row, $restriction)) {
                continue;
            }
            $out[] = ['name' => $row['name'], 'value' => $row['value']];
        }

        return $out;
    }

    /** @return mixed */
    public function getValue(string $sql)
    {
        if (preg_match('/SELECT COUNT\(\*\) FROM `?test_configuration`? WHERE `name` = \'([^\']+)\'(.*)$/s', $sql, $m)) {
            $count = 0;
            foreach ($this->rows as $row) {
                if ($row['name'] !== $m[1]) {
                    continue;
                }
                if ($this->matchesRestriction($row, $m[2])) {
                    ++$count;
                }
            }

            return $count;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data, bool $nullValues = false): bool
    {
        unset($nullValues);
        assertScope($table === 'configuration', 'insert table must be configuration');
        if ($this->failNextInsert) {
            $this->failNextInsert = false;

            return false;
        }
        $id = $this->nextId++;
        $this->rows[$id] = [
            'id_configuration' => $id,
            'name' => stripslashes((string) $data['name']),
            'value' => stripslashes((string) $data['value']),
            'id_shop' => array_key_exists('id_shop', $data) && $data['id_shop'] !== null ? (int) $data['id_shop'] : null,
            'id_shop_group' => array_key_exists('id_shop_group', $data) && $data['id_shop_group'] !== null
                ? (int) $data['id_shop_group']
                : null,
        ];

        return true;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(string $table, array $data, string $where, int $limit = 0, bool $nullValues = false): bool
    {
        unset($table, $data, $where, $limit, $nullValues);

        return false;
    }

    public function delete(string $table, string $where): bool
    {
        assertScope($table === 'configuration', 'delete table must be configuration');
        foreach ($this->rows as $id => $row) {
            if (!preg_match('/`name` = \'([^\']+)\'(.*)$/s', $where, $m)) {
                continue;
            }
            if ($row['name'] !== $m[1]) {
                continue;
            }
            if ($this->matchesRestriction($row, $m[2])) {
                unset($this->rows[$id]);
            }
        }

        return true;
    }

    /**
     * @param array{id_shop:?int, id_shop_group:?int} $row
     */
    private function matchesRestriction(array $row, string $restriction): bool
    {
        if (preg_match('/`id_shop` = (\d+).*`id_shop_group` = (\d+)/s', $restriction, $m)) {
            return (int) $row['id_shop'] === (int) $m[1]
                && (int) $row['id_shop_group'] === (int) $m[2];
        }
        if (strpos($restriction, '`id_shop` IS NULL') !== false || strpos($restriction, 'id_shop` IS NULL') !== false) {
            $shopNull = $row['id_shop'] === null || (int) $row['id_shop'] === 0;
            $groupNull = $row['id_shop_group'] === null || (int) $row['id_shop_group'] === 0;

            return $shopNull && $groupNull;
        }

        return true;
    }

    public function seed(string $name, ?string $value, ?int $idShop, ?int $idShopGroup): void
    {
        $id = $this->nextId++;
        $this->rows[$id] = [
            'id_configuration' => $id,
            'name' => $name,
            'value' => $value,
            'id_shop' => $idShop,
            'id_shop_group' => $idShopGroup,
        ];
    }

    public function countExact(string $name, int $idShop, int $idShopGroup): int
    {
        $n = 0;
        foreach ($this->rows as $row) {
            if ($row['name'] === $name && (int) $row['id_shop'] === $idShop && (int) $row['id_shop_group'] === $idShopGroup) {
                ++$n;
            }
        }

        return $n;
    }
}

final class ExactScopeSnapStore implements FinancingSnapshotStoreInterface
{
    /** @var array<int, array<string, mixed>> */
    public $rows = [];

    public function save(int $attemptId, array $snapshot): void
    {
        $this->rows[$attemptId] = $snapshot;
    }

    public function findByAttempt(int $attemptId): ?array
    {
        return $this->rows[$attemptId] ?? null;
    }

    public function update(int $attemptId, array $changes): void
    {
        $this->rows[$attemptId] = array_merge($this->rows[$attemptId] ?? [], $changes);
    }
}

final class ExactScopeBankSpy implements BankStatusPersistencePort
{
    /** @var list<string> */
    public $updates = [];

    public function updateByOrderIdentifier(int $idShop, string $orderReference, string $statusId, string $statusLabel): ?array
    {
        unset($idShop, $orderReference, $statusLabel);
        $this->updates[] = $statusId;

        return ['status_id' => $statusId];
    }
}

final class ExactScopeMailNoop implements LeasingMailDispatchPort
{
    public function send(array $snapshot, int $attemptId, array $shop, array $status): void
    {
        unset($snapshot, $attemptId, $shop, $status);
    }
}

final class ExactScopeSmartPort implements PostControlPanelSmartUcfPort
{
    /** @var SmartUcfCoordinationResult */
    public $result;

    public function __construct(SmartUcfCoordinationResult $result)
    {
        $this->result = $result;
    }

    public function run(int $attemptId, array $shop, bool $process2, ?array $snapshot = null): SmartUcfCoordinationResult
    {
        unset($attemptId, $shop, $process2, $snapshot);

        return $this->result;
    }

    public function resume(int $attemptId, array $shop, bool $process2): SmartUcfCoordinationResult
    {
        return $this->run($attemptId, $shop, $process2, null);
    }
}

$cipher = new SmartUcfCredentialCipher();
$userEnv = $cipher->encrypt('smart-user');
$passEnv = $cipher->encrypt('smart-pass');
$userEnvB = $cipher->encrypt('other-user');
$passEnvB = $cipher->encrypt('other-pass');
$userKey = SmartUcfCredentialRepository::USER_KEY;
$passKey = SmartUcfCredentialRepository::PASSWORD_KEY;

Shop::$featureActive = false;
Shop::$shops = [1 => ['id_shop' => 1, 'id_shop_group' => 1]];

function newStore(ExactScopeFakeDb $db): ConfigurationSmartUcfCredentialSettingStore
{
    return new ConfigurationSmartUcfCredentialSettingStore($db);
}

function pairState(ExactScopeFakeDb $db, int $idShop = 1): array
{
    $store = newStore($db);
    $pair = $store->getPair($idShop);

    return [
        'user' => $pair['user'] !== null,
        'password' => $pair['password'] !== null,
        'complete' => $pair['user'] !== null && $pair['password'] !== null,
    ];
}

// --- Exact read ---
$db = new ExactScopeFakeDb();
assertScope(pairState($db) === ['user' => false, 'password' => false, 'complete' => false], '1: 0/0 absent');

$db = new ExactScopeFakeDb();
$db->seed($userKey, $userEnv, 1, 1);
$db->seed($passKey, $passEnv, 1, 1);
assertScope(pairState($db)['complete'] === true, '2: 1/1 complete');

$db = new ExactScopeFakeDb();
$db->seed($userKey, $userEnv, 1, 1);
assertScope(pairState($db)['complete'] === false, '3: 1/0 fail closed');

$db = new ExactScopeFakeDb();
$db->seed($passKey, $passEnv, 1, 1);
assertScope(pairState($db)['complete'] === false, '4: 0/1 fail closed');

$db = new ExactScopeFakeDb();
$db->seed($userKey, $userEnv, 1, 1);
$db->seed($userKey, $userEnvB, 1, 1);
$db->seed($passKey, $passEnv, 1, 1);
assertScope(pairState($db)['complete'] === false, '5: 2/1 fail closed');

$db = new ExactScopeFakeDb();
$db->seed($userKey, $userEnv, 1, 1);
$db->seed($passKey, $passEnv, 1, 1);
$db->seed($passKey, $passEnvB, 1, 1);
assertScope(pairState($db)['complete'] === false, '6: 1/2 fail closed');

$db = new ExactScopeFakeDb();
$db->seed($userKey, $userEnv, 1, 1);
$db->seed($userKey, $userEnvB, 1, 1);
$db->seed($passKey, $passEnv, 1, 1);
$db->seed($passKey, $passEnvB, 1, 1);
assertScope(pairState($db)['complete'] === false, '7: 2/2 fail closed');

// Physical row counts include empty/NULL duplicates (not filtered before counting)
$db = new ExactScopeFakeDb();
$db->seed($userKey, $userEnv, 1, 1);
$db->seed($userKey, '', 1, 1);
$db->seed($passKey, $passEnv, 1, 1);
assertScope(pairState($db)['complete'] === false, '7b: valid USER + empty USER duplicate + valid PASSWORD fail closed');

$db = new ExactScopeFakeDb();
$db->seed($userKey, $userEnv, 1, 1);
$db->seed($userKey, null, 1, 1);
$db->seed($passKey, $passEnv, 1, 1);
assertScope(pairState($db)['complete'] === false, '7c: valid USER + NULL USER duplicate + valid PASSWORD fail closed');

$db = new ExactScopeFakeDb();
$db->seed($userKey, $userEnv, 1, 1);
$db->seed($passKey, $passEnv, 1, 1);
$db->seed($passKey, '', 1, 1);
assertScope(pairState($db)['complete'] === false, '7d: valid PASSWORD + empty PASSWORD duplicate fail closed');

$db = new ExactScopeFakeDb();
$db->seed($userKey, $userEnv, 1, 1);
$db->seed($passKey, $passEnv, 1, 1);
$db->seed($passKey, null, 1, 1);
assertScope(pairState($db)['complete'] === false, '7e: valid PASSWORD + NULL PASSWORD duplicate fail closed');

$db = new ExactScopeFakeDb();
$db->seed($userKey, '', 1, 1);
$db->seed($passKey, $passEnv, 1, 1);
assertScope(pairState($db)['complete'] === false, '7f: single empty USER + valid PASSWORD fail closed');

$db = new ExactScopeFakeDb();
$db->seed($userKey, $userEnv, 1, 1);
$db->seed($passKey, '', 1, 1);
assertScope(pairState($db)['complete'] === false, '7g: valid USER + single empty PASSWORD fail closed');

// --- Cross-scope ---
$db = new ExactScopeFakeDb();
$db->seed($userKey, $userEnv, null, null);
$db->seed($passKey, $passEnv, null, null);
assertScope(pairState($db)['complete'] === false, '8: global pair does not hydrate exact shop');
assertScope((int) $db->rows[1]['id_shop'] === 0 || $db->rows[1]['id_shop'] === null, '8: global rows untouched');

Shop::$shops = [
    1 => ['id_shop' => 1, 'id_shop_group' => 1],
    2 => ['id_shop' => 2, 'id_shop_group' => 1],
];
$db = new ExactScopeFakeDb();
$db->seed($userKey, $userEnv, 2, 1);
$db->seed($passKey, $passEnv, 2, 1);
assertScope(pairState($db, 1)['complete'] === false, '9: another shop pair does not hydrate');

$db = new ExactScopeFakeDb();
$db->seed($userKey, $userEnv, 1, 1);
$db->seed($passKey, $passEnv, 1, 1);
$db->seed($userKey, $userEnvB, null, null);
$db->seed($passKey, $passEnvB, null, null);
$repo = new SmartUcfCredentialRepository(newStore($db), $cipher, 1);
assertScope($repo->getUsername() === 'smart-user', '10: exact pair preferred over global');
assertScope($repo->getPassword() === 'smart-pass', '10: exact password preferred');

// Formerly multishop → now single shop: global still NOT adopted
Shop::$featureActive = false;
Shop::$shops = [1 => ['id_shop' => 1, 'id_shop_group' => 1]];
$db = new ExactScopeFakeDb();
$db->seed($userKey, $userEnvB, null, null);
$db->seed($passKey, $passEnvB, null, null);
assertScope(pairState($db)['complete'] === false, '11: formerly-multishop global NOT adopted');
assertScope(strpos($db->rows[1]['value'], SmartUcfCredentialCipher::PREFIX) === 0, '11: global envelope remains ignored');

// --- Writes ---
$db = new ExactScopeFakeDb();
$store = newStore($db);
$store->set(1, $userKey, $userEnv);
$store->set(1, $passKey, $passEnv);
assertScope($db->countExact($userKey, 1, 1) === 1, '12: exactly one USER row');
assertScope($db->countExact($passKey, 1, 1) === 1, '12: exactly one PASSWORD row');

$rotatedUser = $cipher->encrypt('rotated-user');
$rotatedPass = $cipher->encrypt('rotated-pass');
$store->set(1, $userKey, $rotatedUser);
$store->set(1, $passKey, $rotatedPass);
assertScope($db->countExact($userKey, 1, 1) === 1, '13: rotation still one USER');
assertScope($db->countExact($passKey, 1, 1) === 1, '13: rotation still one PASSWORD');
$repo = new SmartUcfCredentialRepository($store, $cipher, 1);
assertScope($repo->getUsername() === 'rotated-user' && $repo->getPassword() === 'rotated-pass', '13: rotated pair readable');

// Duplicate exact state: write normalizes to 1+1, never mixes arbitrarily on read before write
$db = new ExactScopeFakeDb();
$db->seed($userKey, $userEnv, 1, 1);
$db->seed($userKey, $userEnvB, 1, 1);
$db->seed($passKey, $passEnv, 1, 1);
assertScope(pairState($db)['complete'] === false, '14a: duplicates fail closed before write');
$store = newStore($db);
$store->set(1, $userKey, $rotatedUser);
$store->set(1, $passKey, $rotatedPass);
assertScope($db->countExact($userKey, 1, 1) === 1 && $db->countExact($passKey, 1, 1) === 1, '14b: write postcondition 1+1');
$repo = new SmartUcfCredentialRepository($store, $cipher, 1);
assertScope($repo->getUsername() === 'rotated-user' && $repo->getPassword() === 'rotated-pass', '14c: no mixed generation pair');

// Failed write rolls back via mutation boundary
$memoryStore = new InMemorySmartUcfCredentialSettingStore();
$memoryRepo = new SmartUcfCredentialRepository($memoryStore, $cipher, 1);
$cache = new TransactionalMemoryShopConfigurationCache();
$boundary = new CredentialAtomicFakeBoundary($memoryStore, $cache);
$persistence = new SmartUcfCredentialPersistence($memoryRepo, $cache, $boundary);
$unicid = '11111111-1111-1111-1111-111111111111';
$persistence->persistValidatedSnapshot($unicid, unipayment_valid_shop_snapshot([
    'uni_user' => 'keep-user',
    'uni_password' => 'keep-pass',
]));
$prior = $memoryRepo->captureRawPair();
$memoryStore->failOnSetNumber = $memoryStore->setCount + 2; // fail on password of next rotation
$boundary = new CredentialAtomicFakeBoundary($memoryStore, $cache);
$persistence = new SmartUcfCredentialPersistence($memoryRepo, $cache, $boundary);
try {
    $persistence->persistValidatedSnapshot($unicid, unipayment_valid_shop_snapshot([
        'uni_user' => 'new-user',
        'uni_password' => 'new-pass',
    ]));
    assertScope(false, '15: expected write failure');
} catch (Throwable $e) {
    assertScope(true, '15: write failure thrown');
}
assertScope($memoryRepo->captureRawPair() === $prior, '15: previous pair preserved after rollback');

// Delete removes all exact duplicates for both keys only
$db = new ExactScopeFakeDb();
$db->seed($userKey, $userEnv, 1, 1);
$db->seed($userKey, $userEnvB, 1, 1);
$db->seed($passKey, $passEnv, 1, 1);
$db->seed($passKey, $passEnvB, 1, 1);
$db->seed($userKey, $userEnv, 2, 1);
$db->seed($passKey, $passEnv, 2, 1);
$db->seed($userKey, $userEnv, null, null);
$db->seed($passKey, $passEnv, null, null);
Shop::$shops = [
    1 => ['id_shop' => 1, 'id_shop_group' => 1],
    2 => ['id_shop' => 2, 'id_shop_group' => 1],
];
$store = newStore($db);
$store->delete(1, $userKey);
$store->delete(1, $passKey);
assertScope($db->countExact($userKey, 1, 1) === 0 && $db->countExact($passKey, 1, 1) === 0, '16: exact duplicates removed');
assertScope($db->countExact($userKey, 2, 1) === 1 && $db->countExact($passKey, 2, 1) === 1, '16: other shop untouched');
$globalLeft = 0;
foreach ($db->rows as $row) {
    if (($row['id_shop'] === null || (int) $row['id_shop'] === 0) && in_array($row['name'], [$userKey, $passKey], true)) {
        ++$globalLeft;
    }
}
assertScope($globalLeft === 2, '16: global rows untouched by exact delete');
Shop::$shops = [1 => ['id_shop' => 1, 'id_shop_group' => 1]];

// --- Runtime hydration ---
Configuration::updateValue(ConfigurationRepository::UNICID, $unicid);
$cache = new TransactionalMemoryShopConfigurationCache();
$provider = new class implements ShopConfigurationProviderInterface {
    public function getShop(): array
    {
        return ['data' => []];
    }
};
[$service, $wiredRepo] = ShopConfigurationCredentialWiring::service(
    new ConfigurationRepository(),
    $cache,
    $provider,
    new TokenRepository(),
    1
);
(new SmartUcfCredentialPersistence($wiredRepo, $cache, new ImmediateMutationBoundary()))
    ->persistValidatedSnapshot($unicid, unipayment_valid_shop_snapshot([
        'uni_user' => 'persist-user',
        'uni_password' => 'persist-pass',
    ]));
$fresh = $cache->getFresh($unicid);
assertScope($fresh !== null && !isset($fresh['uni_user']) && !isset($fresh['uni_password']), '17/18: cache credential-free after exact persist');
$cachedOnly = $service->getCachedOnly();
assertScope($cachedOnly !== null && !isset($cachedOnly['uni_user']) && !isset($cachedOnly['uni_password']), '19: getCachedOnly credential-free');
$runtime = $service->get();
assertScope(
    trim((string) ($runtime['uni_user'] ?? '')) !== '' && trim((string) ($runtime['uni_password'] ?? '')) !== '',
    '20: P1 guard inputs present only with exact hydrated pair'
);

// Ambiguous exact pair remains unavailable → pre-send retryable classification
$preSend = SmartUcfCoordinationResult::failed(
    SmartUcfSessionCoordinator::CUSTOMER_FAILED,
    true,
    SmartUcfSessionCoordinator::ERROR_CREDENTIALS_UNAVAILABLE
);
assertScope($preSend->isPreSendFailure(), '21: credentials unavailable is pre-send');

$order = new OrderOrchestrationResult(1, 'cp_created', 55, 'ABCD12345', 901);
$ctx = new PostControlPanelLifecycleContext(1, 'BGN');
$snap = new ExactScopeSnapStore();
$snap->rows[1] = ['id_attempt' => 1, 'id_order' => 55, 'order_reference' => 'ABCD12345', 'customer_json' => []];
$bank = new ExactScopeBankSpy();
$resultPre = (new PostControlPanelLifecycleService($snap, new ExactScopeMailNoop(), $bank))->handle(
    $order,
    ['uni_proces' => 0],
    $ctx,
    new ExactScopeSmartPort($preSend)
);
assertScope($resultPre->isPreSendFailure(), '22: pre-send outcome');
assertScope($bank->updates === [], '22: no bank_send_failed_smartucf');

$bank2 = new ExactScopeBankSpy();
$resultRemote = (new PostControlPanelLifecycleService($snap, new ExactScopeMailNoop(), $bank2))->handle(
    $order,
    ['uni_proces' => 0],
    $ctx,
    new ExactScopeSmartPort(SmartUcfCoordinationResult::failed(
        SmartUcfSessionCoordinator::CUSTOMER_FAILED,
        false,
        SmartUcfFailureClassification::CLASS_REMOTE_REJECT
    ))
);
assertScope($resultRemote->isFailed(), '23: definitive remote failure');
assertScope($bank2->updates === [BankStatus::SEND_FAILED_SMARTUCF], '23: persists bank_send_failed_smartucf');

$bank3 = new ExactScopeBankSpy();
$resultUnknown = (new PostControlPanelLifecycleService($snap, new ExactScopeMailNoop(), $bank3))->handle(
    $order,
    ['uni_proces' => 0],
    $ctx,
    new ExactScopeSmartPort(SmartUcfCoordinationResult::outcomeUnknown(
        SmartUcfSessionCoordinator::CUSTOMER_OUTCOME_UNKNOWN
    ))
);
assertScope($resultUnknown->isOutcomeUnknown(), '24: outcome unknown unchanged');
assertScope($bank3->updates === [], '24: outcome unknown not definitive bank failure');

// Source must not contain automatic relocation
$storeSrc = (string) file_get_contents($root . '/src/SmartUcf/ConfigurationSmartUcfCredentialSettingStore.php');
assertScope(strpos($storeSrc, 'relocateLegacyUnscopedPairIfNeeded') === false, 'source: no automatic relocation');
assertScope(!preg_match('/\\\\Configuration::updateValue\s*\(/', $storeSrc), 'source: no Configuration::updateValue() call');

fwrite(STDOUT, "OK (SmartUCF exact credential context V2)\n");
