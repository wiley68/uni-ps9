<?php

declare(strict_types=1);

/**
 * AUD-011 — multishop authorization for order-bank-status lookup.
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

/** Minimal DB double for CLI tests without PrestaShop bootstrap. */
class Aud011DbStub
{
    /** @return array<string, string>|false */
    public function getRow(string $sql)
    {
        unset($sql);

        return false;
    }

    public function execute(string $sql): bool
    {
        unset($sql);

        return true;
    }

    /** @return list<array<string, mixed>>|false */
    public function executeS(string $sql)
    {
        unset($sql);

        return [];
    }
}

final class Aud011FakeDb extends Aud011DbStub
{
    /** @var list<array{id_order:int,id_shop:int,reference:string}> */
    public array $orders = [];

    /** @var list<int> */
    public array $snapshotOrderIds = [];

    /** @var list<array<string, string>> */
    public array $bankRows = [];

    /** @var list<string> */
    public array $queries = [];

    /** @var bool Phase 4 default for AUD-011 path when table is present later */
    public bool $financingSnapshotTableExists = true;

    /** @return array<string, string>|false */
    public function getRow(string $sql)
    {
        $this->queries[] = $sql;

        if (!preg_match("/o\.`reference` = '([^']+)'/s", $sql, $referenceMatch)) {
            return false;
        }
        if (!preg_match('/o\.`id_shop` = (\d+)/', $sql, $shopMatch)) {
            return false;
        }
        if (strpos($sql, 'unipayment_financing_snapshot') === false) {
            return false;
        }

        $reference = str_replace("\\'", "'", $referenceMatch[1]);
        $idShop = (int) $shopMatch[1];

        foreach ($this->orders as $order) {
            if ($order['reference'] !== $reference || $order['id_shop'] !== $idShop) {
                continue;
            }
            if (!in_array($order['id_order'], $this->snapshotOrderIds, true)) {
                return false;
            }

            return [
                'id_order' => (string) $order['id_order'],
                'id_shop' => (string) $order['id_shop'],
                'reference' => $order['reference'],
            ];
        }

        return false;
    }

    /** @return list<array<string, mixed>>|false */
    public function executeS(string $sql)
    {
        $this->queries[] = $sql;
        if (strpos($sql, 'SHOW TABLES LIKE') === false) {
            return [];
        }

        if (!$this->financingSnapshotTableExists) {
            return [];
        }

        return [['Tables_in_db' => _DB_PREFIX_ . 'unipayment_financing_snapshot']];
    }

    public function execute(string $sql): bool
    {
        $this->queries[] = $sql;
        if (preg_match(
            "/INSERT INTO `ps_unipayment_order_bank_status` \(([^)]+)\) VALUES \(([^)]+)\)/",
            $sql,
            $matches
        )) {
            $columns = array_map(static function (string $column): string {
                return trim($column, ' `');
            }, explode(',', $matches[1]));
            $values = array_map(static function (string $value): string {
                return trim($value, " '");
            }, explode(',', $matches[2]));
            $row = [];
            foreach ($columns as $index => $column) {
                $row[$column] = $values[$index] ?? '';
            }
            $this->bankRows[] = $row;
        }

        return true;
    }
}

if (!class_exists('Db', false)) {
    class_alias(Aud011DbStub::class, 'Db');
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Order\OrderBankStatusRepository;

function assertAud011(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$repoSrc = (string) file_get_contents($root . '/src/Order/OrderBankStatusRepository.php');
$ctrlSrc = (string) file_get_contents($root . '/controllers/front/orderbankstatus.php');

assertAud011(strpos($repoSrc, 'ctype_digit') === false, 'numeric id_order fallback removed');
assertAud011(strpos($repoSrc, 'findAuthorizedFinancingOrder') !== false, 'authorized lookup present');
assertAud011(
    strpos($repoSrc, 'FinancingSnapshotRepository::TABLE') !== false
        && strpos($repoSrc, 'INNER JOIN') !== false,
    'financing snapshot join required'
);
assertAud011(strpos($repoSrc, 'financingSnapshotTableExists') !== false, 'Phase 4 table-existence gate present');
assertAud011(strpos($repoSrc, 'o.`reference`') !== false, 'lookup always uses reference');
assertAud011(strpos($ctrlSrc, '$this->context->shop->id') !== false, 'controller passes authorized id_shop');
assertAud011(strpos($ctrlSrc, 'updateByOrderIdentifier(') !== false, 'controller uses repository');
assertAud011(
    preg_match('/if \(\$result === null\)[\s\S]*404/', $ctrlSrc) === 1,
    '404 path preserved for not found'
);

function makeAud011Repo(Aud011FakeDb $db): OrderBankStatusRepository
{
    $reflection = new ReflectionClass(OrderBankStatusRepository::class);
    /** @var OrderBankStatusRepository $repo */
    $repo = $reflection->newInstanceWithoutConstructor();
    $property = $reflection->getProperty('database');
    $property->setAccessible(true);
    $property->setValue($repo, $db);

    return $repo;
}

function aud011JoinQuery(Aud011FakeDb $db): ?string
{
    foreach ($db->queries as $sql) {
        if (strpos($sql, 'INNER JOIN') !== false && strpos($sql, 'unipayment_financing_snapshot') !== false) {
            return $sql;
        }
    }

    return null;
}

// Test A — valid current-shop reference
$dbA = new Aud011FakeDb();
$dbA->orders = [
    ['id_order' => 101, 'id_shop' => 1, 'reference' => 'ABC123'],
];
$dbA->snapshotOrderIds = [101];
$repoA = makeAud011Repo($dbA);
$resultA = $repoA->updateByOrderIdentifier(1, 'ABC123', 'bank_ok', 'OK');
assertAud011($resultA !== null && $resultA['ps_order_id'] === 101, 'A: current shop reference resolves');
assertAud011($dbA->bankRows !== [] && $dbA->bankRows[0]['id_order'] === '101', 'A: bank row stored');
$joinA = aud011JoinQuery($dbA);
assertAud011($joinA !== null, 'A: snapshot join used when table exists');

// Test B — same reference in two shops, only current shop updated
$dbB = new Aud011FakeDb();
$dbB->orders = [
    ['id_order' => 201, 'id_shop' => 1, 'reference' => 'ABC123'],
    ['id_order' => 301, 'id_shop' => 2, 'reference' => 'ABC123'],
];
$dbB->snapshotOrderIds = [201, 301];
$repoB = makeAud011Repo($dbB);
$resultB = $repoB->updateByOrderIdentifier(1, 'ABC123', 'bank_ok', 'OK');
assertAud011($resultB !== null && $resultB['ps_order_id'] === 201, 'B: shop A order resolved');
assertAud011($dbB->bankRows[0]['id_shop'] === '1', 'B: shop A id_shop stored');
assertAud011(count($dbB->bankRows) === 1, 'B: only one update performed');

// Test C — foreign-shop-only reference
$dbC = new Aud011FakeDb();
$dbC->orders = [
    ['id_order' => 401, 'id_shop' => 2, 'reference' => 'FOREIGN'],
];
$dbC->snapshotOrderIds = [401];
$repoC = makeAud011Repo($dbC);
assertAud011($repoC->updateByOrderIdentifier(1, 'FOREIGN', 'bank_ok', 'OK') === null, 'C: foreign shop not found');
assertAud011($dbC->bankRows === [], 'C: no bank status stored');

// Test D — numeric-looking reference must not match id_order
$dbD = new Aud011FakeDb();
$dbD->orders = [
    ['id_order' => 500, 'id_shop' => 1, 'reference' => '12345'],
    ['id_order' => 12345, 'id_shop' => 1, 'reference' => 'XYZ'],
];
$dbD->snapshotOrderIds = [500, 12345];
$repoD = makeAud011Repo($dbD);
$resultD = $repoD->updateByOrderIdentifier(1, '12345', 'bank_ok', 'OK');
assertAud011($resultD !== null && $resultD['ps_order_id'] === 500, 'D: resolves reference=12345 not id_order=12345');
$joinD = aud011JoinQuery($dbD);
assertAud011($joinD !== null && strpos($joinD, 'o.`reference` = \'12345\'') !== false, 'D: SQL uses reference equality');
assertAud011($joinD !== null && strpos($joinD, '`id_order` = 12345') === false, 'D: SQL never uses id_order lookup');

// Test E — no financing snapshot row (table present)
$dbE = new Aud011FakeDb();
$dbE->orders = [
    ['id_order' => 601, 'id_shop' => 1, 'reference' => 'NOSNAP'],
];
$dbE->snapshotOrderIds = [];
$repoE = makeAud011Repo($dbE);
assertAud011($repoE->updateByOrderIdentifier(1, 'NOSNAP', 'bank_ok', 'OK') === null, 'E: no snapshot → not found');
assertAud011($dbE->bankRows === [], 'E: no bank status stored');
assertAud011(aud011JoinQuery($dbE) !== null, 'E: JOIN still attempted when table exists');

// Test F — stored shop identity on success
$dbF = new Aud011FakeDb();
$dbF->orders = [
    ['id_order' => 701, 'id_shop' => 3, 'reference' => 'SHOP3REF'],
];
$dbF->snapshotOrderIds = [701];
$repoF = makeAud011Repo($dbF);
$repoF->updateByOrderIdentifier(3, 'SHOP3REF', 'fixture-reject-01', 'Rejected');
assertAud011($dbF->bankRows[0]['id_order'] === '701', 'F: id_order persisted');
assertAud011($dbF->bankRows[0]['id_shop'] === '3', 'F: id_shop persisted');
assertAud011($dbF->bankRows[0]['order_id'] === 'SHOP3REF', 'F: shop reference persisted');

assertAud011(
    strpos($ctrlSrc, 'BankStatusOrderStateMapper') === false
        && strpos($ctrlSrc, 'if ($result === null)') !== false,
    'G: callback preserves authorization without native order-state mapping'
);

// Test H — financing snapshot table absent (Phase 4): null without JOIN / no SQL against missing table
$dbH = new Aud011FakeDb();
$dbH->financingSnapshotTableExists = false;
$dbH->orders = [
    ['id_order' => 801, 'id_shop' => 1, 'reference' => 'PHASE4'],
];
$dbH->snapshotOrderIds = [801];
$repoH = makeAud011Repo($dbH);
assertAud011($repoH->updateByOrderIdentifier(1, 'PHASE4', 'bank_ok', 'OK') === null, 'H: missing table → null');
assertAud011($dbH->bankRows === [], 'H: no bank status stored');
assertAud011(aud011JoinQuery($dbH) === null, 'H: no JOIN against missing financing_snapshot table');
foreach ($dbH->queries as $sql) {
    assertAud011(
        strpos($sql, 'FROM `ps_orders`') === false && strpos($sql, 'INNER JOIN') === false,
        'H: must not query orders/JOIN when table absent'
    );
}
assertAud011(
    count(array_filter($dbH->queries, static function (string $sql): bool {
        return strpos($sql, 'SHOW TABLES LIKE') !== false;
    })) === 1,
    'H: existence checked via SHOW TABLES only'
);

fwrite(STDOUT, "OK (AUD-011 order-bank-status multishop authorization)\n");
