<?php

declare(strict_types=1);

/**
 * AUD-013 — atomic checkout submit lock.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}

if (!function_exists('pSQL')) {
    function pSQL(string $string, bool $htmlOK = false): string
    {
        unset($htmlOK);

        return str_replace("'", "\\'", $string);
    }
}

if (!class_exists('PrestaShopDatabaseException', false)) {
    class PrestaShopDatabaseException extends Exception {}
}

class Aud013FakeDb
{
    /** @var array<string, array<string, string>> */
    public array $rows = [];

    public int $affectedRows = 0;

    private function key(int $idShop, int $idCart): string
    {
        return $idShop . ':' . $idCart;
    }

    public function execute(string $sql): bool
    {
        $this->affectedRows = 0;

        if (preg_match(
            '/INSERT IGNORE INTO `[^`]+`\s*\(`id_shop`,\s*`id_cart`,\s*`owner_token`,\s*`expires_at`,\s*`created_at`\)\s*VALUES\s*\(\s*(\d+)\s*,\s*(\d+)\s*,\s*\'([^\']+)\'\s*,\s*\'([^\']+)\'\s*,\s*\'([^\']+)\'\s*\)/s',
            $sql,
            $matches
        )) {
            $key = $this->key((int) $matches[1], (int) $matches[2]);
            if (isset($this->rows[$key])) {
                $this->affectedRows = 0;

                return true;
            }

            $this->rows[$key] = [
                'id_shop' => (string) $matches[1],
                'id_cart' => (string) $matches[2],
                'owner_token' => $matches[3],
                'expires_at' => $matches[4],
                'created_at' => $matches[5],
            ];
            $this->affectedRows = 1;

            return true;
        }

        if (preg_match(
            '/UPDATE `[^`]+`\s+SET `owner_token` = \'([^\']+)\',\s+`expires_at` = \'([^\']+)\',\s+`created_at` = \'([^\']+)\'\s+WHERE `id_shop` = (\d+)\s+AND `id_cart` = (\d+)\s+AND `expires_at` <= \'([^\']+)\'/s',
            $sql,
            $matches
        )) {
            $key = $this->key((int) $matches[4], (int) $matches[5]);
            if (!isset($this->rows[$key])) {
                return true;
            }

            if ($this->rows[$key]['expires_at'] <= $matches[6]) {
                $this->rows[$key] = [
                    'id_shop' => (string) $matches[4],
                    'id_cart' => (string) $matches[5],
                    'owner_token' => $matches[1],
                    'expires_at' => $matches[2],
                    'created_at' => $matches[3],
                ];
                $this->affectedRows = 1;
            }

            return true;
        }

        if (preg_match(
            '/DELETE FROM `[^`]+`\s+WHERE `id_shop` = (\d+)\s+AND `id_cart` = (\d+)\s+AND `owner_token` = \'([^\']+)\'/s',
            $sql,
            $matches
        )) {
            $key = $this->key((int) $matches[1], (int) $matches[2]);
            if (isset($this->rows[$key]) && $this->rows[$key]['owner_token'] === $matches[3]) {
                unset($this->rows[$key]);
                $this->affectedRows = 1;
            }

            return true;
        }

        return true;
    }

    /**
     * Must not be used by acquire() — PS9 Db::insert throws on UNIQUE duplicate.
     *
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data, $nullValues = false, $useCache = true, $type = 1): bool
    {
        unset($table, $data, $nullValues, $useCache, $type);
        throw new PrestaShopDatabaseException(
            "Duplicate entry '1-18' for key 'unipayment_checkout_lock.uniq_unipayment_checkout_lock'"
        );
    }

    /** @return array<string, string>|false */
    public function getRow(string $sql)
    {
        if (!preg_match('/WHERE `id_shop` = (\d+)\s+AND `id_cart` = (\d+)/', $sql, $matches)) {
            return false;
        }

        $key = $this->key((int) $matches[1], (int) $matches[2]);

        return $this->rows[$key] ?? false;
    }

    public function Affected_Rows(): int
    {
        return $this->affectedRows;
    }
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

require_once dirname(__DIR__, 2) . '/src/Security/ClockInterface.php';
require_once dirname(__DIR__, 2) . '/src/Security/FixedClock.php';
require_once dirname(__DIR__, 2) . '/src/Checkout/CheckoutSubmitLockRepository.php';
require_once dirname(__DIR__, 2) . '/src/Checkout/CheckoutSubmitLock.php';

use PrestaShop\Module\Unipayment\Checkout\CheckoutSubmitLock;
use PrestaShop\Module\Unipayment\Checkout\CheckoutSubmitLockRepository;
use PrestaShop\Module\Unipayment\Security\FixedClock;

function assertAud013(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function makeLock(Aud013FakeDb $db, int $now): CheckoutSubmitLock
{
    return new CheckoutSubmitLock(new CheckoutSubmitLockRepository($db), new FixedClock($now));
}

function row(Aud013FakeDb $db, int $idShop, int $idCart): ?array
{
    return (new CheckoutSubmitLockRepository($db))->find($idShop, $idCart);
}

// Test A — first acquire succeeds
$dbA = new Aud013FakeDb();
$now = 1_700_000_000;
$lockA = makeLock($dbA, $now);
$tokenA = $lockA->acquire(1, 100);
assertAud013(is_string($tokenA) && strlen($tokenA) === 32, 'A: acquire must return 32-char owner token');
$storedA = row($dbA, 1, 100);
assertAud013(is_array($storedA), 'A: lock row must exist');
assertAud013($storedA['id_shop'] === '1' && $storedA['id_cart'] === '100', 'A: shop/cart mismatch');
assertAud013($storedA['owner_token'] === $tokenA, 'A: owner token mismatch');
assertAud013($storedA['expires_at'] === date('Y-m-d H:i:s', $now + CheckoutSubmitLockRepository::TTL_SECONDS), 'A: expiry mismatch');

// Test B — fresh second acquire fails
$tokenB = $lockA->acquire(1, 100);
assertAud013($tokenB === null, 'B: second fresh acquire must fail');

// Test C — independent carts/shops
$dbC = new Aud013FakeDb();
$lockC = makeLock($dbC, $now);
assertAud013($lockC->acquire(1, 101) !== null, 'C: same shop different cart must succeed');
assertAud013($lockC->acquire(2, 100) !== null, 'C: different shop same cart must succeed');

// Test D — stale lock recovery
$dbD = new Aud013FakeDb();
$dbD->rows['1:200'] = [
    'id_shop' => '1',
    'id_cart' => '200',
    'owner_token' => 'oldtokenoldtokenoldtokenoldtoken',
    'expires_at' => date('Y-m-d H:i:s', $now - 1),
    'created_at' => date('Y-m-d H:i:s', $now - 60),
];
$lockD = makeLock($dbD, $now);
$tokenD = $lockD->acquire(1, 200);
assertAud013(is_string($tokenD), 'D: stale acquire must succeed');
$storedD = row($dbD, 1, 200);
assertAud013(is_array($storedD) && $storedD['owner_token'] === $tokenD, 'D: stale owner token must change');
assertAud013($storedD['expires_at'] === date('Y-m-d H:i:s', $now + CheckoutSubmitLockRepository::TTL_SECONDS), 'D: stale expiry must refresh');

// Test E — concurrent fresh acquire (repository contract: unique insert + conditional stale update)
$dbE = new Aud013FakeDb();
$repoE = new CheckoutSubmitLockRepository($dbE);
$winnerE = null;
$loserE = null;
for ($attempt = 1; $attempt <= 2; ++$attempt) {
    $candidate = bin2hex(random_bytes(16));
    if ($repoE->acquire(1, 300, $now, $candidate)) {
        $winnerE = $candidate;
    } else {
        $loserE = $candidate;
    }
}
assertAud013($winnerE !== null && $loserE !== null, 'E: concurrent fresh acquire must produce one winner and one loser');
$storedE = row($dbE, 1, 300);
assertAud013(is_array($storedE) && $storedE['owner_token'] === $winnerE, 'E: stored owner must match winner');

// Test F — concurrent stale takeover
$dbF = new Aud013FakeDb();
$dbF->rows['1:400'] = [
    'id_shop' => '1',
    'id_cart' => '400',
    'owner_token' => str_repeat('a', 32),
    'expires_at' => date('Y-m-d H:i:s', $now - 5),
    'created_at' => date('Y-m-d H:i:s', $now - 50),
];
$repoF = new CheckoutSubmitLockRepository($dbF);
$winnerF = null;
$loserCountF = 0;
for ($attempt = 1; $attempt <= 2; ++$attempt) {
    $candidate = bin2hex(random_bytes(16));
    if ($repoF->acquire(1, 400, $now, $candidate)) {
        $winnerF = $candidate;
    } else {
        ++$loserCountF;
    }
}
assertAud013(is_string($winnerF) && $loserCountF === 1, 'F: concurrent stale takeover must allow exactly one winner');

// Test G — old owner cannot release new owner
$dbG = new Aud013FakeDb();
$lockG1 = makeLock($dbG, $now);
$tokenG1 = $lockG1->acquire(1, 500);
assertAud013(is_string($tokenG1), 'G: first acquire failed');
$lockG2 = makeLock($dbG, $now + CheckoutSubmitLockRepository::TTL_SECONDS + 1);
$tokenG2 = $lockG2->acquire(1, 500);
assertAud013(is_string($tokenG2), 'G: stale takeover failed');
$lockG1->release(1, 500, (string) $tokenG1);
assertAud013(row($dbG, 1, 500) !== null, 'G: old owner release must not remove new lock');
$lockG2->release(1, 500, (string) $tokenG2);
assertAud013(row($dbG, 1, 500) === null, 'G: current owner release must remove lock');

// Test H — valid owner release
$dbH = new Aud013FakeDb();
$lockH = makeLock($dbH, $now);
$tokenH = $lockH->acquire(1, 600);
assertAud013(is_string($tokenH), 'H: acquire failed');
$lockH->release(1, 600, $tokenH);
assertAud013(row($dbH, 1, 600) === null, 'H: release must remove lock');
assertAud013($lockH->acquire(1, 600) !== null, 'H: acquire after release must succeed immediately');

// Test I — wrong token cannot release
$dbI = new Aud013FakeDb();
$lockI = makeLock($dbI, $now);
$tokenI = $lockI->acquire(1, 700);
assertAud013(is_string($tokenI), 'I: acquire failed');
$lockI->release(1, 700, str_repeat('0', 32));
assertAud013(row($dbI, 1, 700) !== null, 'I: wrong token release must keep lock active');

// Test J — invalid identifiers
$dbJ = new Aud013FakeDb();
$lockJ = makeLock($dbJ, $now);
assertAud013($lockJ->acquire(0, 100) === null, 'J: invalid shop acquire must fail');
assertAud013($lockJ->acquire(1, 0) === null, 'J: invalid cart acquire must fail');
$lockJ->release(0, 100, str_repeat('1', 32));
$lockJ->release(1, 0, str_repeat('1', 32));
assertAud013($dbJ->rows === [], 'J: invalid release must not create rows');

// Test K — TTL boundary (expires_at <= now is stale)
$dbK = new Aud013FakeDb();
$boundaryNow = 1_700_000_100;
$dbK->rows['1:800'] = [
    'id_shop' => '1',
    'id_cart' => '800',
    'owner_token' => str_repeat('c', 32),
    'expires_at' => date('Y-m-d H:i:s', $boundaryNow),
    'created_at' => date('Y-m-d H:i:s', $boundaryNow - 45),
];
$lockK = makeLock($dbK, $boundaryNow);
assertAud013($lockK->acquire(1, 800) !== null, 'K: lock at exact expiry boundary must be recoverable');

$root = dirname(__DIR__, 2);
$validate = (string) file_get_contents($root . '/controllers/front/validatecheckout.php');
assertAud013(strpos($validate, '$lockToken = $lock->acquire($idShop, $idCart);') !== false, 'controller must store lock token');
assertAud013(strpos($validate, '$lock->release($idShop, $idCart, $lockToken);') !== false, 'controller must release with owner token');
assertAud013(strpos($validate, 'finally') === false, 'controller must not use finally release');

$module = (string) file_get_contents($root . '/unipayment.php');
assertAud013(strpos($module, 'CheckoutSubmitLockRepository') !== false, 'install must create checkout lock table');

$lockSrc = (string) file_get_contents($root . '/src/Checkout/CheckoutSubmitLock.php');
assertAud013(strpos($lockSrc, 'Configuration::') === false, 'CheckoutSubmitLock must not use Configuration');

$repoSrc = (string) file_get_contents($root . '/src/Checkout/CheckoutSubmitLockRepository.php');
assertAud013(strpos($repoSrc, 'INSERT IGNORE') !== false, 'acquire must use INSERT IGNORE');
assertAud013(!preg_match('/function acquire\s*\([^)]*\)[^{]*\{[^}]*\$this->database->insert\s*\(/s', $repoSrc), 'acquire must not use Db::insert()');
assertAud013(strpos($repoSrc, 'uniq_unipayment_checkout_lock') !== false, 'UNIQUE(id_shop,id_cart) must remain');
assertAud013(strpos($repoSrc, 'REPLACE INTO') === false, 'must not use REPLACE INTO');
assertAud013(strpos($repoSrc, 'ON DUPLICATE KEY UPDATE') === false, 'must not overwrite live lock via ON DUPLICATE KEY');

// Regression: duplicate UNIQUE must not escape as PrestaShopDatabaseException
$dbDup = new Aud013FakeDb();
$repoDup = new CheckoutSubmitLockRepository($dbDup);
assertAud013($repoDup->acquire(1, 18, $now, str_repeat('d', 32)) === true, 'L: first acquire on 1-18');
$dupEscaped = false;
$dupResult = null;
try {
    $dupResult = $repoDup->acquire(1, 18, $now, str_repeat('e', 32));
} catch (PrestaShopDatabaseException $exception) {
    $dupEscaped = true;
}
assertAud013(!$dupEscaped, 'L: duplicate UNIQUE must not throw PrestaShopDatabaseException');
assertAud013($dupResult === false, 'L: active duplicate must return false');
$storedDup = row($dbDup, 1, 18);
assertAud013(is_array($storedDup) && $storedDup['owner_token'] === str_repeat('d', 32), 'L: active lock owner must be unchanged');

// Generic DB failure must surface (not silent contention)
final class Aud013FailingInsertDb extends Aud013FakeDb
{
    public function execute(string $sql): bool
    {
        if (stripos($sql, 'INSERT IGNORE') !== false) {
            return false;
        }

        return parent::execute($sql);
    }
}
$dbFail = new Aud013FailingInsertDb();
$repoFail = new CheckoutSubmitLockRepository($dbFail);
$failThrown = false;
try {
    $repoFail->acquire(1, 999, $now, str_repeat('f', 32));
} catch (RuntimeException $exception) {
    $failThrown = true;
    assertAud013(strpos($exception->getMessage(), 'checkout submit lock') !== false, 'M: failure message');
}
assertAud013($failThrown, 'M: generic INSERT failure must throw, not return false');
assertAud013($dbFail->rows === [], 'M: failed acquire must not leave a lock row');

fwrite(STDOUT, "OK (AUD-013 atomic checkout submit lock)\n");
