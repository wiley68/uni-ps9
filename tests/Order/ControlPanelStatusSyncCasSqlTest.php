<?php

declare(strict_types=1);

/**
 * Regression: CP status-sync CAS must treat SQL NULL and '' as semantic null
 * for nullable sync target fields (production PrestaShop Db::insert behavior).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Order\BankStatus;
use PrestaShop\Module\Unipayment\Order\ControlPanelOrderClientInterface;
use PrestaShop\Module\Unipayment\Order\ControlPanelStatusSyncService;
use PrestaShop\Module\Unipayment\Order\ControlPanelStatusSyncStates;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotRepository;

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}

if (!function_exists('pSQL')) {
    function pSQL(string $string, bool $htmlOK = false): string
    {
        unset($htmlOK);

        return addslashes($string);
    }
}

if (!class_exists('PrestaShopLogger', false)) {
    class PrestaShopLogger
    {
        /** @var list<string> */
        public static $logs = [];

        public static function addLog(string $message, int $severity = 1): void
        {
            self::$logs[] = $message;
        }
    }
}

function assertCasSql(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * Minimal Db double that captures CAS SQL and serves a configurable snapshot row.
 */
final class CasSqlFakeDb
{
    /** @var array<string, mixed>|null */
    public $row;

    /** @var string */
    public $lastExecuteSql = '';

    /** @var int */
    public $affectedRows = 1;

    /** @var list<array<string, mixed>> */
    public $inserts = [];

    /** @return array<string, mixed>|false|null */
    public function getRow(string $sql)
    {
        unset($sql);

        return $this->row;
    }

    public function execute(string $sql): bool
    {
        $this->lastExecuteSql = $sql;

        return true;
    }

    public function Affected_Rows(): int
    {
        return $this->affectedRows;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(
        string $table,
        array $data,
        bool $null_values = false,
        bool $use_cache = true,
        int $type = 1,
        bool $add_prefix = true
    ): bool {
        unset($use_cache, $type, $add_prefix);
        $this->inserts[] = [
            'table' => $table,
            'data' => $data,
            'null_values' => $null_values,
        ];

        return true;
    }
}

final class CasSqlFakeCp implements ControlPanelOrderClientInterface
{
    /** @var list<array{order_id: string, status: string, status_id: string}> */
    public $patches = [];

    public function createOrder(array $payload): array
    {
        return ['success' => true, 'data' => ['id' => 1]];
    }

    public function updateOrderStatus(string $orderId, string $status, string $statusId): array
    {
        $this->patches[] = [
            'order_id' => $orderId,
            'status' => $status,
            'status_id' => $statusId,
        ];

        return [
            'success' => true,
            'error' => null,
            'message' => 'ok',
            'data' => [
                'id' => 1,
                'shop_id' => 1,
                'order_id' => $orderId,
                'status_id' => $statusId,
                'status' => $status,
                'updated_at' => '2026-01-01T00:00:00Z',
            ],
        ];
    }
}

$db = new CasSqlFakeDb();
$repo = new FinancingSnapshotRepository($db);

// --- A: empty-string row + semantic-null expected → CAS SQL matches NULL or '' ---
$db->row = [
    'id_attempt' => 91001,
    'order_reference' => 'CASEMPTY001',
    'cp_status_sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
    'cp_status_sync_status_id' => '',
    'cp_status_sync_status' => '',
    'cp_status_sync_error_class' => '',
];
$db->affectedRows = 1;
$okEmpty = $repo->compareAndSetPendingTarget(
    91001,
    ControlPanelStatusSyncStates::NOT_NEEDED,
    null,
    null,
    BankStatus::SENT_PROCESS1,
    BankStatus::LABEL_SENT_PROCESS1
);
assertCasSql($okEmpty, 'empty-string row admit CAS reports success when Affected_Rows>0');
assertCasSql(
    strpos($db->lastExecuteSql, "(`cp_status_sync_status_id` IS NULL OR `cp_status_sync_status_id` = '')") !== false,
    'CAS SQL uses NULL/empty equivalence for status_id'
);
assertCasSql(
    strpos($db->lastExecuteSql, "(`cp_status_sync_status` IS NULL OR `cp_status_sync_status` = '')") !== false,
    'CAS SQL uses NULL/empty equivalence for status'
);
assertCasSql(
    strpos($db->lastExecuteSql, "cp_status_sync_state` = '" . ControlPanelStatusSyncStates::PENDING . "'") !== false
    || strpos($db->lastExecuteSql, "cp_status_sync_state` = '" . ControlPanelStatusSyncStates::PENDING . "'") !== false,
    'CAS SQL sets pending state'
);
assertCasSql(strpos($db->lastExecuteSql, BankStatus::SENT_PROCESS1) !== false, 'CAS SQL writes process1 target');

// --- C: SQL NULL expected predicate is the same semantic-null form ---
$db->lastExecuteSql = '';
$repo->compareAndSetPendingTarget(
    91001,
    ControlPanelStatusSyncStates::NOT_NEEDED,
    null,
    null,
    BankStatus::SENT_PROCESS2,
    BankStatus::LABEL_SENT_PROCESS2
);
assertCasSql(
    strpos($db->lastExecuteSql, 'IS NULL OR') !== false,
    'SQL NULL expected still uses NULL/empty-compatible predicate'
);

// --- D: exact non-null CAS remains strict ---
$db->lastExecuteSql = '';
$repo->compareAndSetPendingTarget(
    91001,
    ControlPanelStatusSyncStates::PENDING,
    BankStatus::SENT_PROCESS1,
    BankStatus::LABEL_SENT_PROCESS1,
    BankStatus::SENT_PROCESS2,
    BankStatus::LABEL_SENT_PROCESS2
);
assertCasSql(
    strpos($db->lastExecuteSql, "`cp_status_sync_status_id` <=> '" . BankStatus::SENT_PROCESS1 . "'") !== false,
    'non-null expected status_id uses exact equality'
);
assertCasSql(
    strpos($db->lastExecuteSql, 'IS NULL OR') === false,
    'non-null expected must not use semantic-null OR empty predicate'
);

// --- E: stale CAS — 0 affected rows ---
$db->affectedRows = 0;
$stale = $repo->compareAndSetConfirmed(
    91001,
    BankStatus::SENT_PROCESS1,
    BankStatus::LABEL_SENT_PROCESS1
);
assertCasSql(!$stale, 'stale confirm returns false when Affected_Rows=0');

// --- Future insert persists SQL NULL for semantic-null sync fields ---
$db->inserts = [];
$repo->save(91002, [
    'id_order' => 91002,
    'order_reference' => 'CASINS002',
    'cart_fingerprint' => str_repeat('b', 64),
    'scheme_type' => 'standard',
    'scheme_key' => 'standard|X|12|0',
    'kop_code' => 'X',
    'months' => 12,
    'filter_id' => 0,
    'first_installment' => 0,
    'financed_amount' => 100,
    'monthly_installment' => 10,
    'total_payable' => 120,
    'glp' => 0,
    'gpr' => 0,
    'coefficient' => 1,
    'order_total' => 100,
    'currency_iso' => 'EUR',
    'id_currency' => 1,
    'module_version' => '2.0.2',
    'submission_source' => 'checkout',
    'customer_json' => [],
    'address_json' => [],
    'lines_json' => [],
    'consents_json' => [],
    'sensitive_payload' => null,
    'control_panel_order_id' => null,
    'lifecycle_status' => 'ps_order_created',
    'smartucf_state' => 'not_started',
    'smartucf_retryable' => 0,
    'cp_status_sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
    'cp_status_sync_status_id' => '',
    'cp_status_sync_status' => null,
    'cp_status_sync_error_class' => '',
    'cp_status_sync_updated_at' => null,
]);
assertCasSql(count($db->inserts) === 1, 'save() performed insert');
$inserted = $db->inserts[0]['data'];
assertCasSql(
    is_array($inserted['cp_status_sync_status_id'])
    && ($inserted['cp_status_sync_status_id']['type'] ?? '') === 'sql'
    && ($inserted['cp_status_sync_status_id']['value'] ?? '') === 'NULL',
    'save() persists empty status_id as SQL NULL'
);
assertCasSql(
    is_array($inserted['cp_status_sync_status'])
    && ($inserted['cp_status_sync_status']['value'] ?? '') === 'NULL',
    'save() persists null status as SQL NULL'
);
assertCasSql(
    is_array($inserted['cp_status_sync_error_class'])
    && ($inserted['cp_status_sync_error_class']['value'] ?? '') === 'NULL',
    'save() persists empty error_class as SQL NULL'
);
assertCasSql($db->inserts[0]['null_values'] === false, 'save() does not enable blanket null_values');

/**
 * Store decorator: real repository SQL CAS + mutable row for service-level flows.
 */
final class CasSqlTrackingStore implements \PrestaShop\Module\Unipayment\Order\ControlPanelStatusSyncStoreInterface
{
    /** @var FinancingSnapshotRepository */
    private $repo;

    /** @var CasSqlFakeDb */
    private $db;

    public function __construct(FinancingSnapshotRepository $repo, CasSqlFakeDb $db)
    {
        $this->repo = $repo;
        $this->db = $db;
    }

    public function findByAttempt(int $attemptId): ?array
    {
        return $this->db->row;
    }

    public function compareAndSetPendingTarget(
        int $attemptId,
        string $expectedState,
        ?string $expectedStatusId,
        ?string $expectedStatus,
        string $newStatusId,
        string $newStatus
    ): bool {
        $ok = $this->repo->compareAndSetPendingTarget(
            $attemptId,
            $expectedState,
            $expectedStatusId,
            $expectedStatus,
            $newStatusId,
            $newStatus
        );
        if ($ok && is_array($this->db->row)) {
            $this->db->row['cp_status_sync_state'] = ControlPanelStatusSyncStates::PENDING;
            $this->db->row['cp_status_sync_status_id'] = $newStatusId;
            $this->db->row['cp_status_sync_status'] = $newStatus;
            $this->db->row['cp_status_sync_error_class'] = null;
        }

        return $ok;
    }

    public function compareAndSetConfirmed(
        int $attemptId,
        string $expectedStatusId,
        string $expectedStatus
    ): bool {
        $ok = $this->repo->compareAndSetConfirmed($attemptId, $expectedStatusId, $expectedStatus);
        if ($ok && is_array($this->db->row)) {
            $this->db->row['cp_status_sync_state'] = ControlPanelStatusSyncStates::CONFIRMED;
            $this->db->row['cp_status_sync_status_id'] = $expectedStatusId;
            $this->db->row['cp_status_sync_status'] = $expectedStatus;
            $this->db->row['cp_status_sync_error_class'] = null;
        }

        return $ok;
    }

    public function compareAndSetFailure(
        int $attemptId,
        string $expectedStatusId,
        string $expectedStatus,
        string $newState,
        string $errorClass
    ): bool {
        $ok = $this->repo->compareAndSetFailure(
            $attemptId,
            $expectedStatusId,
            $expectedStatus,
            $newState,
            $errorClass
        );
        if ($ok && is_array($this->db->row)) {
            $this->db->row['cp_status_sync_state'] = $newState;
            $this->db->row['cp_status_sync_error_class'] = $errorClass;
        }

        return $ok;
    }
}

// --- Service-level: empty-string persistence → P1 admit + PATCH + confirm ---
$serviceDb = new CasSqlFakeDb();
$serviceRepo = new FinancingSnapshotRepository($serviceDb);
$trackingStore = new CasSqlTrackingStore($serviceRepo, $serviceDb);
$cp = new CasSqlFakeCp();

$serviceDb->row = [
    'id_attempt' => 92001,
    'order_reference' => 'CASSVC001',
    'cp_status_sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
    'cp_status_sync_status_id' => '',
    'cp_status_sync_status' => '',
    'cp_status_sync_error_class' => '',
];
$serviceDb->affectedRows = 1;

$sync = new ControlPanelStatusSyncService($trackingStore, $cp);
$p1State = $sync->synchronizeAfterHandoff(
    92001,
    'CASSVC001',
    BankStatus::successfulSend(false)
);
assertCasSql($p1State === ControlPanelStatusSyncStates::CONFIRMED, 'A: empty-string P1 sync confirms');
assertCasSql(count($cp->patches) === 1, 'A: PATCH attempted after successful admit');
assertCasSql($cp->patches[0]['status_id'] === BankStatus::SENT_PROCESS1, 'A: PATCH target is process1');
assertCasSql(
    $serviceDb->row['cp_status_sync_state'] === ControlPanelStatusSyncStates::CONFIRMED,
    'A: confirmed state persisted on tracking row'
);
assertCasSql(
    strpos($serviceDb->lastExecuteSql, "(`cp_status_sync_status_id` IS NULL OR `cp_status_sync_status_id` = '')") !== false
    || strpos($serviceDb->lastExecuteSql, ControlPanelStatusSyncStates::CONFIRMED) !== false,
    'A: production repository SQL CAS path was used'
);

// --- B: empty-string P2 ---
$serviceDb->row = [
    'id_attempt' => 92002,
    'order_reference' => 'CASSVC002',
    'cp_status_sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
    'cp_status_sync_status_id' => '',
    'cp_status_sync_status' => '',
    'cp_status_sync_error_class' => '',
];
$cp2 = new CasSqlFakeCp();
$sync2 = new ControlPanelStatusSyncService($trackingStore, $cp2);
$p2State = $sync2->synchronizeAfterHandoff(
    92002,
    'CASSVC002',
    BankStatus::successfulSend(true)
);
assertCasSql($p2State === ControlPanelStatusSyncStates::CONFIRMED, 'B: empty-string P2 sync confirms');
assertCasSql(count($cp2->patches) === 1 && $cp2->patches[0]['status_id'] === BankStatus::SENT_PROCESS2, 'B: PATCH process2');

// --- C service: SQL NULL row also works ---
$serviceDb->row = [
    'id_attempt' => 92003,
    'order_reference' => 'CASSVC003',
    'cp_status_sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
    'cp_status_sync_status_id' => null,
    'cp_status_sync_status' => null,
    'cp_status_sync_error_class' => null,
];
$cp3 = new CasSqlFakeCp();
$sync3 = new ControlPanelStatusSyncService($trackingStore, $cp3);
assertCasSql(
    $sync3->synchronizeAfterHandoff(92003, 'CASSVC003', BankStatus::successfulSend(false))
        === ControlPanelStatusSyncStates::CONFIRMED,
    'C: SQL NULL initial admit still works'
);

// --- F: P1/P2 conflict unchanged ---
$serviceDb->row = [
    'id_attempt' => 92004,
    'order_reference' => 'CASSVC004',
    'cp_status_sync_state' => ControlPanelStatusSyncStates::CONFIRMED,
    'cp_status_sync_status_id' => BankStatus::SENT_PROCESS1,
    'cp_status_sync_status' => BankStatus::LABEL_SENT_PROCESS1,
];
$cpConflict = new CasSqlFakeCp();
$syncConflict = new ControlPanelStatusSyncService($trackingStore, $cpConflict);
assertCasSql(
    $syncConflict->synchronizeAfterHandoff(92004, 'CASSVC004', BankStatus::successfulSend(true))
        === ControlPanelStatusSyncStates::CONFIRMED,
    'F: process1→process2 conflict preserves confirmed'
);
assertCasSql(count($cpConflict->patches) === 0, 'F: conflict does not PATCH');
assertCasSql(
    $serviceDb->row['cp_status_sync_status_id'] === BankStatus::SENT_PROCESS1,
    'F: target unchanged on conflict'
);

$serviceDb->row = [
    'id_attempt' => 92005,
    'order_reference' => 'CASSVC005',
    'cp_status_sync_state' => ControlPanelStatusSyncStates::CONFIRMED,
    'cp_status_sync_status_id' => BankStatus::SENT_PROCESS2,
    'cp_status_sync_status' => BankStatus::LABEL_SENT_PROCESS2,
];
$cpConflict2 = new CasSqlFakeCp();
$syncConflict2 = new ControlPanelStatusSyncService($trackingStore, $cpConflict2);
assertCasSql(
    $syncConflict2->synchronizeAfterHandoff(92005, 'CASSVC005', BankStatus::successfulSend(false))
        === ControlPanelStatusSyncStates::CONFIRMED,
    'F: process2→process1 conflict preserves confirmed'
);
assertCasSql(count($cpConflict2->patches) === 0, 'F: reverse conflict does not PATCH');

fwrite(STDOUT, "OK (CP status-sync CAS NULL/empty-string remediation)\n");
