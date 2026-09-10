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

        if (preg_match('/FROM `ps_unipayment_order_bank_status` WHERE `id_order` = (\d+)/', $sql, $match)) {
            $idOrder = (int) $match[1];
            foreach (array_reverse($this->bankRows) as $row) {
                if ((int) ($row['id_order'] ?? 0) === $idOrder) {
                    return [
                        'order_id' => (string) ($row['order_id'] ?? ''),
                        'status_id' => (string) ($row['status_id'] ?? ''),
                        'status_label' => (string) ($row['status_label'] ?? ''),
                        'updated_at' => (string) ($row['updated_at'] ?? ''),
                    ];
                }
            }

            return false;
        }

        $rows = $this->executeS($sql);
        if (!is_array($rows) || $rows === []) {
            return false;
        }

        /** @var array<string, string> $first */
        $first = $rows[0];

        return $first;
    }

    /** @return list<array<string, mixed>>|false */
    public function executeS(string $sql)
    {
        $this->queries[] = $sql;

        if (strpos($sql, 'SHOW TABLES LIKE') !== false) {
            if (!$this->financingSnapshotTableExists) {
                return [];
            }

            return [['Tables_in_db' => _DB_PREFIX_ . 'unipayment_financing_snapshot']];
        }

        if (!preg_match("/o\.`reference` = '([^']+)'/s", $sql, $referenceMatch)) {
            return [];
        }
        if (!preg_match('/o\.`id_shop` = (\d+)/', $sql, $shopMatch)) {
            return [];
        }
        if (strpos($sql, 'unipayment_financing_snapshot') === false) {
            return [];
        }

        $reference = str_replace("\\'", "'", $referenceMatch[1]);
        $idShop = (int) $shopMatch[1];
        $matches = [];

        foreach ($this->orders as $order) {
            if ($order['reference'] !== $reference || $order['id_shop'] !== $idShop) {
                continue;
            }
            if (!in_array($order['id_order'], $this->snapshotOrderIds, true)) {
                continue;
            }

            $matches[] = [
                'id_order' => (string) $order['id_order'],
                'id_shop' => (string) $order['id_shop'],
                'reference' => $order['reference'],
                'smartucf_state' => 'not_started',
            ];
        }

        return $matches;
    }

    public function execute(string $sql): bool
    {
        $this->queries[] = $sql;
        if (!preg_match(
            "/INSERT INTO `ps_unipayment_order_bank_status`\s*\(([^)]+)\)\s*VALUES\s*\(([^)]+)\)/s",
            $sql,
            $matches
        )) {
            return true;
        }

        $columns = array_map(static function (string $column): string {
            return trim($column, " `");
        }, explode(',', $matches[1]));
        $values = array_map(static function (string $value): string {
            return trim($value, " '");
        }, explode(',', $matches[2]));
        $incoming = [];
        foreach ($columns as $index => $column) {
            $incoming[$column] = $values[$index] ?? '';
        }

        $idOrder = (string) ($incoming['id_order'] ?? '');
        $existingIndex = null;
        foreach ($this->bankRows as $index => $row) {
            if ((string) ($row['id_order'] ?? '') === $idOrder) {
                $existingIndex = $index;
                break;
            }
        }

        if ($existingIndex === null) {
            $this->bankRows[] = $incoming;

            return true;
        }

        $current = $this->bankRows[$existingIndex];
        $currentStatus = (string) ($current['status_id'] ?? '');
        $nextStatus = (string) ($incoming['status_id'] ?? '');
        $conflict = ($currentStatus === 'bank_sent_process1' && $nextStatus === 'bank_sent_process2')
            || ($currentStatus === 'bank_sent_process2' && $nextStatus === 'bank_sent_process1');
        if ($conflict) {
            // Atomic guard: keep existing terminal status.
            return true;
        }

        $this->bankRows[$existingIndex] = array_replace($current, $incoming);

        return true;
    }
}

if (!class_exists('Db', false)) {
    class_alias(Aud011DbStub::class, 'Db');
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Order\BankStatus;
use PrestaShop\Module\Unipayment\Order\OrderBankStatusAmbiguousException;
use PrestaShop\Module\Unipayment\Order\OrderBankStatusRepository;
use PrestaShop\Module\Unipayment\Order\OrderBankStatusSemanticConflictException;

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
assertAud011(strpos($repoSrc, 'resolveAuthorizedFinancingOrder') !== false, 'authorized lookup present');
assertAud011(strpos($repoSrc, 'executeS') !== false, 'ambiguous-safe resolve uses executeS');
assertAud011(strpos($repoSrc, 'OrderBankStatusAmbiguousException') !== false, 'ambiguous 2+ raise');
assertAud011(
    strpos($repoSrc, 'FinancingSnapshotRepository::TABLE') !== false
        && strpos($repoSrc, 'INNER JOIN') !== false,
    'financing snapshot join required'
);
assertAud011(strpos($repoSrc, 'financingSnapshotTableExists') !== false, 'Phase 4 table-existence gate present');
assertAud011(strpos($repoSrc, 'o.`reference`') !== false, 'lookup always uses reference');
assertAud011(strpos($ctrlSrc, '$this->context->shop->id') !== false, 'controller passes authorized id_shop');
assertAud011(strpos($ctrlSrc, 'updateByOrderIdentifier(') !== false, 'controller uses repository');
assertAud011(strpos($ctrlSrc, 'ORDER_AMBIGUOUS') !== false, 'controller maps ambiguous matches');
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

// Test I — ambiguous 2+ same-shop financing rows must raise
$dbI = new Aud011FakeDb();
$dbI->orders = [
    ['id_order' => 901, 'id_shop' => 1, 'reference' => 'DUPREF'],
    ['id_order' => 902, 'id_shop' => 1, 'reference' => 'DUPREF'],
];
$dbI->snapshotOrderIds = [901, 902];
$repoI = makeAud011Repo($dbI);
try {
    $repoI->updateByOrderIdentifier(1, 'DUPREF', 'bank_ok', 'OK');
    assertAud011(false, 'I: ambiguous match accepted');
} catch (OrderBankStatusAmbiguousException $exception) {
    assertAud011(true, 'I: ambiguous match raises');
}
assertAud011($dbI->bankRows === [], 'I: ambiguous match must not persist bank status');

assertAud011(strpos($ctrlSrc, 'SEMANTIC_CONFLICT') !== false, 'controller maps terminal conflict to semantic_conflict');
assertAud011(strpos($ctrlSrc, 'OrderBankStatusSemanticConflictException') !== false, 'controller catches semantic conflict');
assertAud011(strpos($repoSrc, 'BankStatusProgression') !== false, 'repository uses progression helper');
assertAud011(
    strpos($repoSrc, 'BankStatus::SENT_PROCESS1') !== false
        && strpos($repoSrc, 'BankStatus::SENT_PROCESS2') !== false,
    'P1/P2 conflict guard present'
);

// Test J — P1 → P2 inbound conflict leaves local status unchanged
$dbJ = new Aud011FakeDb();
$dbJ->orders = [
    ['id_order' => 1001, 'id_shop' => 1, 'reference' => 'P1TERM'],
];
$dbJ->snapshotOrderIds = [1001];
$repoJ = makeAud011Repo($dbJ);
$firstJ = $repoJ->updateByOrderIdentifier(1, 'P1TERM', BankStatus::SENT_PROCESS1, BankStatus::LABEL_SENT_PROCESS1);
assertAud011($firstJ !== null && $firstJ['status_id'] === BankStatus::SENT_PROCESS1, 'J: initial P1 stored');
try {
    $repoJ->updateByOrderIdentifier(1, 'P1TERM', BankStatus::SENT_PROCESS2, BankStatus::LABEL_SENT_PROCESS2);
    assertAud011(false, 'J: P1→P2 conflict accepted');
} catch (OrderBankStatusSemanticConflictException $exception) {
    assertAud011(true, 'J: P1→P2 raises semantic conflict');
}
assertAud011(count($dbJ->bankRows) === 1, 'J: no second bank row');
assertAud011($dbJ->bankRows[0]['status_id'] === BankStatus::SENT_PROCESS1, 'J: local P1 unchanged');

// Test K — P2 → P1 inbound conflict
$dbK = new Aud011FakeDb();
$dbK->orders = [
    ['id_order' => 1002, 'id_shop' => 1, 'reference' => 'P2TERM'],
];
$dbK->snapshotOrderIds = [1002];
$repoK = makeAud011Repo($dbK);
$repoK->updateByOrderIdentifier(1, 'P2TERM', BankStatus::SENT_PROCESS2, BankStatus::LABEL_SENT_PROCESS2);
try {
    $repoK->updateByOrderIdentifier(1, 'P2TERM', BankStatus::SENT_PROCESS1, BankStatus::LABEL_SENT_PROCESS1);
    assertAud011(false, 'K: P2→P1 conflict accepted');
} catch (OrderBankStatusSemanticConflictException $exception) {
    assertAud011(true, 'K: P2→P1 raises semantic conflict');
}
assertAud011($dbK->bankRows[0]['status_id'] === BankStatus::SENT_PROCESS2, 'K: local P2 unchanged');

// Test L — same terminal replay remains idempotent
$sameL = $repoK->updateByOrderIdentifier(1, 'P2TERM', BankStatus::SENT_PROCESS2, BankStatus::LABEL_SENT_PROCESS2);
assertAud011($sameL !== null && $sameL['status_id'] === BankStatus::SENT_PROCESS2, 'L: same P2 replay ok');
assertAud011($dbK->bankRows[0]['status_id'] === BankStatus::SENT_PROCESS2, 'L: same P2 status preserved');

$dbL = new Aud011FakeDb();
$dbL->orders = [
    ['id_order' => 1003, 'id_shop' => 1, 'reference' => 'P1SAME'],
];
$dbL->snapshotOrderIds = [1003];
$repoL = makeAud011Repo($dbL);
$repoL->updateByOrderIdentifier(1, 'P1SAME', BankStatus::SENT_PROCESS1, BankStatus::LABEL_SENT_PROCESS1);
$sameP1 = $repoL->updateByOrderIdentifier(1, 'P1SAME', BankStatus::SENT_PROCESS1, BankStatus::LABEL_SENT_PROCESS1);
assertAud011($sameP1 !== null && $sameP1['status_id'] === BankStatus::SENT_PROCESS1, 'L: same P1 replay ok');

// Test M — compatible non-terminal update still applies
$dbM = new Aud011FakeDb();
$dbM->orders = [
    ['id_order' => 1004, 'id_shop' => 1, 'reference' => 'COMPAT'],
];
$dbM->snapshotOrderIds = [1004];
$repoM = makeAud011Repo($dbM);
$repoM->updateByOrderIdentifier(1, 'COMPAT', 'cp_sent', 'CP sent');
$compat = $repoM->updateByOrderIdentifier(1, 'COMPAT', BankStatus::SENT_PROCESS1, BankStatus::LABEL_SENT_PROCESS1);
assertAud011($compat !== null && $compat['status_id'] === BankStatus::SENT_PROCESS1, 'M: compatible update applies');
assertAud011($dbM->bankRows[0]['status_id'] === BankStatus::SENT_PROCESS1, 'M: local status advanced');

fwrite(STDOUT, "OK (AUD-011 order-bank-status multishop authorization)\n");
