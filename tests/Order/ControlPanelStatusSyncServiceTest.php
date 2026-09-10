<?php

declare(strict_types=1);

/**
 * F01 — durable CP status synchronization + F01-A/B remediation.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Api\Exception\AuthenticationException;
use PrestaShop\Module\Unipayment\Api\Exception\ConnectionException;
use PrestaShop\Module\Unipayment\Api\Exception\HttpException;
use PrestaShop\Module\Unipayment\Api\Exception\InvalidPayloadException;
use PrestaShop\Module\Unipayment\Api\Exception\MalformedJsonException;
use PrestaShop\Module\Unipayment\Order\BankStatus;
use PrestaShop\Module\Unipayment\Order\ControlPanelOrderClientInterface;
use PrestaShop\Module\Unipayment\Order\ControlPanelStatusSyncService;
use PrestaShop\Module\Unipayment\Order\ControlPanelStatusSyncStates;
use PrestaShop\Module\Unipayment\Order\ControlPanelStatusSyncStoreInterface;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotStoreInterface;

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

function assertF01(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class F01MemorySnapshots implements FinancingSnapshotStoreInterface, ControlPanelStatusSyncStoreInterface
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
        if (!isset($this->rows[$attemptId])) {
            return;
        }
        $this->rows[$attemptId] = array_merge($this->rows[$attemptId], $changes);
    }

    public function compareAndSetPendingTarget(
        int $attemptId,
        string $expectedState,
        ?string $expectedStatusId,
        ?string $expectedStatus,
        string $newStatusId,
        string $newStatus
    ): bool {
        if (!$this->matches($attemptId, $expectedState, $expectedStatusId, $expectedStatus)) {
            return false;
        }
        $this->rows[$attemptId]['cp_status_sync_state'] = ControlPanelStatusSyncStates::PENDING;
        $this->rows[$attemptId]['cp_status_sync_status_id'] = $newStatusId;
        $this->rows[$attemptId]['cp_status_sync_status'] = $newStatus;
        $this->rows[$attemptId]['cp_status_sync_error_class'] = null;
        $this->rows[$attemptId]['cp_status_sync_updated_at'] = gmdate('Y-m-d H:i:s');

        return true;
    }

    public function compareAndSetConfirmed(
        int $attemptId,
        string $expectedStatusId,
        string $expectedStatus
    ): bool {
        if (!$this->matches(
            $attemptId,
            ControlPanelStatusSyncStates::PENDING,
            $expectedStatusId,
            $expectedStatus
        )) {
            return false;
        }
        $this->rows[$attemptId]['cp_status_sync_state'] = ControlPanelStatusSyncStates::CONFIRMED;
        $this->rows[$attemptId]['cp_status_sync_status_id'] = $expectedStatusId;
        $this->rows[$attemptId]['cp_status_sync_status'] = $expectedStatus;
        $this->rows[$attemptId]['cp_status_sync_error_class'] = null;
        $this->rows[$attemptId]['cp_status_sync_updated_at'] = gmdate('Y-m-d H:i:s');

        return true;
    }

    public function compareAndSetFailure(
        int $attemptId,
        string $expectedStatusId,
        string $expectedStatus,
        string $newState,
        string $errorClass
    ): bool {
        if (!$this->matches(
            $attemptId,
            ControlPanelStatusSyncStates::PENDING,
            $expectedStatusId,
            $expectedStatus
        )) {
            return false;
        }
        $this->rows[$attemptId]['cp_status_sync_state'] = $newState;
        $this->rows[$attemptId]['cp_status_sync_error_class'] = $errorClass;
        $this->rows[$attemptId]['cp_status_sync_updated_at'] = gmdate('Y-m-d H:i:s');

        return true;
    }

    private function matches(
        int $attemptId,
        string $expectedState,
        ?string $expectedStatusId,
        ?string $expectedStatus
    ): bool {
        if (!isset($this->rows[$attemptId])) {
            return false;
        }
        $row = $this->rows[$attemptId];
        if ((string) ($row['cp_status_sync_state'] ?? '') !== $expectedState) {
            return false;
        }
        $actualId = $row['cp_status_sync_status_id'] ?? null;
        $actualStatus = $row['cp_status_sync_status'] ?? null;
        $actualId = ($actualId === null || $actualId === '') ? null : (string) $actualId;
        $actualStatus = ($actualStatus === null || $actualStatus === '') ? null : (string) $actualStatus;

        return $actualId === $expectedStatusId && $actualStatus === $expectedStatus;
    }
}

final class F01FakeCp implements ControlPanelOrderClientInterface
{
    /** @var list<array{order_id: string, status: string, status_id: string}> */
    public $patches = [];

    /** @var int */
    public $createCalls = 0;

    /** @var \Throwable|null */
    public $nextError = null;

    /** @var array<string, mixed>|null */
    public $nextResponse = null;

    /** @var callable|null */
    public $beforePatch = null;

    public function createOrder(array $payload): array
    {
        ++$this->createCalls;

        return ['success' => true, 'data' => ['id' => 1]];
    }

    public function updateOrderStatus(string $orderId, string $status, string $statusId): array
    {
        if ($this->beforePatch !== null) {
            ($this->beforePatch)($orderId, $status, $statusId);
        }
        $this->patches[] = [
            'order_id' => $orderId,
            'status' => $status,
            'status_id' => $statusId,
        ];
        if ($this->nextError !== null) {
            $error = $this->nextError;
            $this->nextError = null;
            throw $error;
        }
        if ($this->nextResponse !== null) {
            $response = $this->nextResponse;
            $this->nextResponse = null;

            return $response;
        }

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

function f01Http(int $status, string $error): HttpException
{
    return new HttpException($status, [
        'success' => false,
        'error' => $error,
        'message' => 'x',
        'data' => [],
    ]);
}

$store = new F01MemorySnapshots();
$store->save(11, [
    'id_attempt' => 11,
    'order_reference' => 'REFPROCESS001',
    'cp_status_sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
]);
$cp = new F01FakeCp();
$sync = new ControlPanelStatusSyncService($store, $cp);
$p1 = BankStatus::successfulSend(false);

// P1 success → confirmed
$state = $sync->synchronizeAfterHandoff(11, 'REFPROCESS001', $p1);
assertF01($state === ControlPanelStatusSyncStates::CONFIRMED, 'P1 PATCH success confirms sync');
assertF01(count($cp->patches) === 1, 'P1 PATCH sent once');
assertF01($cp->patches[0]['status_id'] === BankStatus::SENT_PROCESS1, 'P1 target status_id');
assertF01($store->rows[11]['cp_status_sync_state'] === ControlPanelStatusSyncStates::CONFIRMED, 'P1 durable confirmed');

// P1 transport failure → pending, business evidence preserved (no createOrder)
$store->save(12, [
    'id_attempt' => 12,
    'order_reference' => 'REFPROCESS002',
    'cp_status_sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
]);
$cpFail = new F01FakeCp();
$cpFail->nextError = new ConnectionException('down');
$syncFail = new ControlPanelStatusSyncService($store, $cpFail);
$stateFail = $syncFail->synchronizeAfterHandoff(12, 'REFPROCESS002', $p1);
assertF01($stateFail === ControlPanelStatusSyncStates::PENDING, 'P1 transport failure leaves pending');
assertF01($store->rows[12]['cp_status_sync_state'] === ControlPanelStatusSyncStates::PENDING, 'P1 pending persisted');
assertF01($store->rows[12]['cp_status_sync_status_id'] === BankStatus::SENT_PROCESS1, 'P1 pending target preserved');
assertF01($cpFail->createCalls === 0, 'PATCH failure must not create another CP order');

// pending P1 retry succeeds → confirmed
$stateRetry = $syncFail->retryPending(12, 'REFPROCESS002');
assertF01($stateRetry === ControlPanelStatusSyncStates::CONFIRMED, 'P1 pending retry confirms');
assertF01(count($cpFail->patches) === 2, 'P1 retry issued second PATCH only');
assertF01($cpFail->patches[1]['status_id'] === BankStatus::SENT_PROCESS1, 'P1 retry uses same status_id');

// P2 success → confirmed
$p2 = BankStatus::successfulSend(true);
$store->save(21, [
    'id_attempt' => 21,
    'order_reference' => 'REFPROCESS021',
    'cp_status_sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
]);
$cp2 = new F01FakeCp();
$sync2 = new ControlPanelStatusSyncService($store, $cp2);
assertF01(
    $sync2->synchronizeAfterHandoff(21, 'REFPROCESS021', $p2) === ControlPanelStatusSyncStates::CONFIRMED,
    'P2 PATCH success confirms'
);
assertF01($cp2->patches[0]['status_id'] === BankStatus::SENT_PROCESS2, 'P2 target status_id');

// P2 transport failure → pending, no second create
$store->save(22, [
    'id_attempt' => 22,
    'order_reference' => 'REFPROCESS022',
    'cp_status_sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
]);
$cp2Fail = new F01FakeCp();
$cp2Fail->nextError = new ConnectionException('down');
$sync2Fail = new ControlPanelStatusSyncService($store, $cp2Fail);
assertF01(
    $sync2Fail->synchronizeAfterHandoff(22, 'REFPROCESS022', $p2) === ControlPanelStatusSyncStates::PENDING,
    'P2 transport failure leaves pending'
);
assertF01($store->rows[22]['cp_status_sync_status_id'] === BankStatus::SENT_PROCESS2, 'P2 pending target preserved');
assertF01($cp2Fail->createCalls === 0, 'P2 PATCH failure must not create another CP order');
assertF01(
    $sync2Fail->retryPending(22, 'REFPROCESS022') === ControlPanelStatusSyncStates::CONFIRMED,
    'P2 pending retry confirms'
);

// malformed / echo mismatch remain pending
foreach (
    [
        'malformed' => new MalformedJsonException('bad json'),
        'wrong order_id' => new InvalidPayloadException('order_id mismatch'),
        'wrong status_id' => new InvalidPayloadException('status_id mismatch'),
        'wrong status' => new InvalidPayloadException('status mismatch'),
    ] as $label => $error
) {
    $id = 30 + strlen($label);
    $store->save($id, [
        'id_attempt' => $id,
        'order_reference' => 'REFECHO' . $id,
        'cp_status_sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
    ]);
    $cpEcho = new F01FakeCp();
    $cpEcho->nextError = $error;
    $syncEcho = new ControlPanelStatusSyncService($store, $cpEcho);
    assertF01(
        $syncEcho->synchronizeAfterHandoff($id, 'REFECHO' . $id, $p1) === ControlPanelStatusSyncStates::PENDING,
        $label . ' must remain pending'
    );
}

// --- F01-A positive terminal allowlist ---
foreach (
    [
        'invalid_payload' => 422,
        'semantic_conflict' => 409,
        'unsupported_status' => 422,
        'order_not_found' => 404,
    ] as $code => $http
) {
    $id = 40 + strlen($code);
    $store->save($id, [
        'id_attempt' => $id,
        'order_reference' => 'REFTERM' . $id,
        'cp_status_sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
    ]);
    $cpTerm = new F01FakeCp();
    $cpTerm->nextError = f01Http($http, $code);
    $syncTerm = new ControlPanelStatusSyncService($store, $cpTerm);
    assertF01(
        $syncTerm->synchronizeAfterHandoff($id, 'REFTERM' . $id, $p1) === ControlPanelStatusSyncStates::TERMINAL_FAILED,
        $code . ' → terminal_failed'
    );
    assertF01(
        $store->rows[$id]['cp_status_sync_error_class'] === 'cp_status_' . $code,
        $code . ' error_class is machine-code based'
    );
}

// F01-A non-terminal (must stay pending even as 4xx/5xx)
foreach (
    [
        ['rate_limited', 429],
        ['authentication_failed', 401],
        ['token_expired', 401],
        ['internal_error', 500],
        ['weird_unknown_code', 400],
        ['', 429],
        ['', 500],
    ] as $idx => $pair
) {
    [$code, $http] = $pair;
    $id = 60 + $idx;
    $store->save($id, [
        'id_attempt' => $id,
        'order_reference' => 'REFPEND' . $id,
        'cp_status_sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
    ]);
    $cpPend = new F01FakeCp();
    $cpPend->nextError = f01Http($http, $code);
    $syncPend = new ControlPanelStatusSyncService($store, $cpPend);
    assertF01(
        $syncPend->synchronizeAfterHandoff($id, 'REFPEND' . $id, $p1) === ControlPanelStatusSyncStates::PENDING,
        ($code !== '' ? $code : 'HTTP ' . $http) . ' → pending'
    );
}

$authExId = 70;
$store->save($authExId, [
    'id_attempt' => $authExId,
    'order_reference' => 'REFAUTH070',
    'cp_status_sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
]);
$cpAuth = new F01FakeCp();
$cpAuth->nextError = new AuthenticationException('auth');
$syncAuth = new ControlPanelStatusSyncService($store, $cpAuth);
assertF01(
    $syncAuth->synchronizeAfterHandoff($authExId, 'REFAUTH070', $p1) === ControlPanelStatusSyncStates::PENDING,
    'AuthenticationException → pending'
);

// confirmed process2 + process1 → conflict (no replacement, no PATCH)
$store->save(50, [
    'id_attempt' => 50,
    'order_reference' => 'REFNEWER050',
    'cp_status_sync_state' => ControlPanelStatusSyncStates::CONFIRMED,
    'cp_status_sync_status_id' => BankStatus::SENT_PROCESS2,
    'cp_status_sync_status' => BankStatus::LABEL_SENT_PROCESS2,
]);
$cpStale = new F01FakeCp();
$syncStale = new ControlPanelStatusSyncService($store, $cpStale);
$stateStale = $syncStale->synchronizeAfterHandoff(50, 'REFNEWER050', $p1);
assertF01($stateStale === ControlPanelStatusSyncStates::CONFIRMED, 'process2→process1 conflict returns confirmed');
assertF01(
    $store->rows[50]['cp_status_sync_status_id'] === BankStatus::SENT_PROCESS2,
    'confirmed process2 unchanged after process1 conflict'
);
assertF01(count($cpStale->patches) === 0, 'process2→process1 conflict must not PATCH');

// pending process2 + process1 → conflict
$store->save(51, [
    'id_attempt' => 51,
    'order_reference' => 'REFNEWER051',
    'cp_status_sync_state' => ControlPanelStatusSyncStates::PENDING,
    'cp_status_sync_status_id' => BankStatus::SENT_PROCESS2,
    'cp_status_sync_status' => BankStatus::LABEL_SENT_PROCESS2,
]);
$cpOlder = new F01FakeCp();
$syncOlder = new ControlPanelStatusSyncService($store, $cpOlder);
$stateOlder = $syncOlder->synchronizeAfterHandoff(51, 'REFNEWER051', $p1);
assertF01($stateOlder === ControlPanelStatusSyncStates::PENDING, 'pending process2→process1 conflict stays pending');
assertF01(
    $store->rows[51]['cp_status_sync_status_id'] === BankStatus::SENT_PROCESS2,
    'pending process2 unchanged after process1 conflict'
);
assertF01(count($cpOlder->patches) === 0, 'pending process2→process1 conflict must not PATCH');

// pending process1 + process2 → conflict
$store->save(52, [
    'id_attempt' => 52,
    'order_reference' => 'REFNEWER052',
    'cp_status_sync_state' => ControlPanelStatusSyncStates::PENDING,
    'cp_status_sync_status_id' => BankStatus::SENT_PROCESS1,
    'cp_status_sync_status' => BankStatus::LABEL_SENT_PROCESS1,
]);
$cpPendConflict = new F01FakeCp();
$syncPendConflict = new ControlPanelStatusSyncService($store, $cpPendConflict);
$statePendConflict = $syncPendConflict->synchronizeAfterHandoff(52, 'REFNEWER052', $p2);
assertF01($statePendConflict === ControlPanelStatusSyncStates::PENDING, 'pending process1→process2 conflict stays pending');
assertF01(
    $store->rows[52]['cp_status_sync_status_id'] === BankStatus::SENT_PROCESS1,
    'pending process1 unchanged after process2 conflict'
);
assertF01(count($cpPendConflict->patches) === 0, 'pending process1→process2 conflict must not PATCH process2');

// confirmed process1 + process2 → conflict
$store->save(53, [
    'id_attempt' => 53,
    'order_reference' => 'REFNEWER053',
    'cp_status_sync_state' => ControlPanelStatusSyncStates::CONFIRMED,
    'cp_status_sync_status_id' => BankStatus::SENT_PROCESS1,
    'cp_status_sync_status' => BankStatus::LABEL_SENT_PROCESS1,
]);
$cpConfConflict = new F01FakeCp();
$syncConfConflict = new ControlPanelStatusSyncService($store, $cpConfConflict);
$stateConfConflict = $syncConfConflict->synchronizeAfterHandoff(53, 'REFNEWER053', $p2);
assertF01($stateConfConflict === ControlPanelStatusSyncStates::CONFIRMED, 'confirmed process1→process2 conflict returns confirmed');
assertF01(
    $store->rows[53]['cp_status_sync_status_id'] === BankStatus::SENT_PROCESS1,
    'confirmed process1 unchanged after process2 conflict'
);
assertF01(count($cpConfConflict->patches) === 0, 'confirmed process1→process2 conflict must not PATCH');

// terminal_failed process1 + process2 → same incompatibility (target still authority)
$store->save(54, [
    'id_attempt' => 54,
    'order_reference' => 'REFNEWER054',
    'cp_status_sync_state' => ControlPanelStatusSyncStates::TERMINAL_FAILED,
    'cp_status_sync_status_id' => BankStatus::SENT_PROCESS1,
    'cp_status_sync_status' => BankStatus::LABEL_SENT_PROCESS1,
    'cp_status_sync_error_class' => 'cp_status_invalid_payload',
]);
$cpTermConflict = new F01FakeCp();
$syncTermConflict = new ControlPanelStatusSyncService($store, $cpTermConflict);
$stateTermConflict = $syncTermConflict->synchronizeAfterHandoff(54, 'REFNEWER054', $p2);
assertF01(
    $stateTermConflict === ControlPanelStatusSyncStates::TERMINAL_FAILED,
    'terminal_failed process1→process2 conflict preserves terminal_failed'
);
assertF01(
    $store->rows[54]['cp_status_sync_status_id'] === BankStatus::SENT_PROCESS1,
    'terminal_failed process1 target unchanged'
);
assertF01(count($cpTermConflict->patches) === 0, 'terminal_failed process conflict must not PATCH');

// same-target pending retry via synchronizeAfterHandoff remains idempotent
$store->save(55, [
    'id_attempt' => 55,
    'order_reference' => 'REFSAME055',
    'cp_status_sync_state' => ControlPanelStatusSyncStates::PENDING,
    'cp_status_sync_status_id' => BankStatus::SENT_PROCESS1,
    'cp_status_sync_status' => BankStatus::LABEL_SENT_PROCESS1,
]);
$cpSameTarget = new F01FakeCp();
$syncSameTarget = new ControlPanelStatusSyncService($store, $cpSameTarget);
assertF01(
    $syncSameTarget->synchronizeAfterHandoff(55, 'REFSAME055', $p1) === ControlPanelStatusSyncStates::CONFIRMED,
    'same-target pending process1 is retry-safe'
);
assertF01(count($cpSameTarget->patches) === 1, 'same-target pending issues one PATCH');
assertF01(
    $syncSameTarget->synchronizeAfterHandoff(55, 'REFSAME055', $p1) === ControlPanelStatusSyncStates::CONFIRMED,
    'same-target confirmed process1 is no-op'
);
assertF01(count($cpSameTarget->patches) === 1, 'confirmed same-target must not re-PATCH');

$store->save(56, [
    'id_attempt' => 56,
    'order_reference' => 'REFSAME056',
    'cp_status_sync_state' => ControlPanelStatusSyncStates::PENDING,
    'cp_status_sync_status_id' => BankStatus::SENT_PROCESS2,
    'cp_status_sync_status' => BankStatus::LABEL_SENT_PROCESS2,
]);
$cpSameP2 = new F01FakeCp();
$syncSameP2 = new ControlPanelStatusSyncService($store, $cpSameP2);
assertF01(
    $syncSameP2->synchronizeAfterHandoff(56, 'REFSAME056', $p2) === ControlPanelStatusSyncStates::CONFIRMED,
    'same-target pending process2 is retry-safe'
);
assertF01(
    $syncSameP2->synchronizeAfterHandoff(56, 'REFSAME056', $p2) === ControlPanelStatusSyncStates::CONFIRMED,
    'same-target confirmed process2 is no-op'
);
assertF01(count($cpSameP2->patches) === 1, 'confirmed same-target process2 must not re-PATCH');

// F01-B stale confirmation rejected (store CAS: concurrent target mutation)
$store->save(80, [
    'id_attempt' => 80,
    'order_reference' => 'REFCAS080',
    'cp_status_sync_state' => ControlPanelStatusSyncStates::PENDING,
    'cp_status_sync_status_id' => BankStatus::SENT_PROCESS1,
    'cp_status_sync_status' => BankStatus::LABEL_SENT_PROCESS1,
]);
assertF01(
    $store->compareAndSetPendingTarget(
        80,
        ControlPanelStatusSyncStates::PENDING,
        BankStatus::SENT_PROCESS1,
        BankStatus::LABEL_SENT_PROCESS1,
        BankStatus::SENT_PROCESS2,
        BankStatus::LABEL_SENT_PROCESS2
    ),
    'worker B mutates pending target via CAS'
);
assertF01(
    !$store->compareAndSetConfirmed(80, BankStatus::SENT_PROCESS1, BankStatus::LABEL_SENT_PROCESS1),
    'worker A confirm of old target rejected'
);
assertF01(
    $store->rows[80]['cp_status_sync_status_id'] === BankStatus::SENT_PROCESS2,
    'mutated target remains authoritative after stale confirm'
);
assertF01(
    $store->rows[80]['cp_status_sync_state'] === ControlPanelStatusSyncStates::PENDING,
    'mutated target stays pending after stale confirm'
);

// F01-B stale failure rejected after alternate target confirmed
$store->save(81, [
    'id_attempt' => 81,
    'order_reference' => 'REFCAS081',
    'cp_status_sync_state' => ControlPanelStatusSyncStates::PENDING,
    'cp_status_sync_status_id' => BankStatus::SENT_PROCESS1,
    'cp_status_sync_status' => BankStatus::LABEL_SENT_PROCESS1,
]);
assertF01(
    $store->compareAndSetPendingTarget(
        81,
        ControlPanelStatusSyncStates::PENDING,
        BankStatus::SENT_PROCESS1,
        BankStatus::LABEL_SENT_PROCESS1,
        BankStatus::SENT_PROCESS2,
        BankStatus::LABEL_SENT_PROCESS2
    ),
    'worker B mutates pending target on 81'
);
assertF01(
    $store->compareAndSetConfirmed(81, BankStatus::SENT_PROCESS2, BankStatus::LABEL_SENT_PROCESS2),
    'worker B confirms mutated target'
);
assertF01(
    !$store->compareAndSetFailure(
        81,
        BankStatus::SENT_PROCESS1,
        BankStatus::LABEL_SENT_PROCESS1,
        ControlPanelStatusSyncStates::TERMINAL_FAILED,
        'cp_status_stale'
    ),
    'worker A failure for old target rejected'
);
assertF01(
    $store->rows[81]['cp_status_sync_state'] === ControlPanelStatusSyncStates::CONFIRMED
    && $store->rows[81]['cp_status_sync_status_id'] === BankStatus::SENT_PROCESS2,
    'confirmed mutated target unchanged by stale failure'
);

// F01-B stale retry must not send old status — persistence is authority
$store->save(82, [
    'id_attempt' => 82,
    'order_reference' => 'REFCAS082',
    'cp_status_sync_state' => ControlPanelStatusSyncStates::PENDING,
    'cp_status_sync_status_id' => BankStatus::SENT_PROCESS1,
    'cp_status_sync_status' => BankStatus::LABEL_SENT_PROCESS1,
]);
$store->update(82, [
    'cp_status_sync_status_id' => BankStatus::SENT_PROCESS2,
    'cp_status_sync_status' => BankStatus::LABEL_SENT_PROCESS2,
]);
$cpRetry = new F01FakeCp();
$syncRetry = new ControlPanelStatusSyncService($store, $cpRetry);
assertF01(
    $syncRetry->retryPending(82, 'REFCAS082') === ControlPanelStatusSyncStates::CONFIRMED,
    'retry against current persisted pending target confirms'
);
assertF01(count($cpRetry->patches) === 1, 'retry sends exactly one PATCH');
assertF01(
    $cpRetry->patches[0]['status_id'] === BankStatus::SENT_PROCESS2,
    'stale in-memory process1 is NOT sent; persisted target is sent'
);

// concurrent same-target retry ends confirmed without corruption
$store->save(83, [
    'id_attempt' => 83,
    'order_reference' => 'REFCAS083',
    'cp_status_sync_state' => ControlPanelStatusSyncStates::PENDING,
    'cp_status_sync_status_id' => BankStatus::SENT_PROCESS1,
    'cp_status_sync_status' => BankStatus::LABEL_SENT_PROCESS1,
]);
$cpSameA = new F01FakeCp();
$cpSameB = new F01FakeCp();
$syncSameA = new ControlPanelStatusSyncService($store, $cpSameA);
$syncSameB = new ControlPanelStatusSyncService($store, $cpSameB);
assertF01($syncSameA->retryPending(83, 'REFCAS083') === ControlPanelStatusSyncStates::CONFIRMED, 'same-target A confirms');
assertF01($syncSameB->retryPending(83, 'REFCAS083') === ControlPanelStatusSyncStates::CONFIRMED, 'same-target B no-op confirmed');
assertF01(
    $store->rows[83]['cp_status_sync_state'] === ControlPanelStatusSyncStates::CONFIRMED
    && $store->rows[83]['cp_status_sync_status_id'] === BankStatus::SENT_PROCESS1,
    'same-target concurrent retries leave confirmed process1'
);

// service-level: confirm in-flight for process1 after concurrent store mutation → preserve other target
$store->save(84, [
    'id_attempt' => 84,
    'order_reference' => 'REFCAS084',
    'cp_status_sync_state' => ControlPanelStatusSyncStates::PENDING,
    'cp_status_sync_status_id' => BankStatus::SENT_PROCESS1,
    'cp_status_sync_status' => BankStatus::LABEL_SENT_PROCESS1,
]);
$cpRace = new F01FakeCp();
$cpRace->beforePatch = static function () use ($store): void {
    $store->compareAndSetPendingTarget(
        84,
        ControlPanelStatusSyncStates::PENDING,
        BankStatus::SENT_PROCESS1,
        BankStatus::LABEL_SENT_PROCESS1,
        BankStatus::SENT_PROCESS2,
        BankStatus::LABEL_SENT_PROCESS2
    );
};
$syncRace = new ControlPanelStatusSyncService($store, $cpRace);
$raceState = $syncRace->retryPending(84, 'REFCAS084');
assertF01(
    $store->rows[84]['cp_status_sync_status_id'] === BankStatus::SENT_PROCESS2,
    'in-flight confirm of old target must not overwrite concurrent mutation'
);
assertF01(
    $store->rows[84]['cp_status_sync_state'] === ControlPanelStatusSyncStates::PENDING,
    'concurrent mutation remains pending after stale in-flight confirm'
);
assertF01($raceState === ControlPanelStatusSyncStates::PENDING, 'stale confirm returns current pending');

// former "process1 may progress to process2" is now canonical CONFLICT (replaced above as id 53)
assertF01(
    strpos(
        (string) file_get_contents(dirname(__DIR__, 2) . '/src/Order/ControlPanelStatusSyncService.php'),
        'function rank('
    ) === false,
    'synthetic process rank helper removed'
);
// idempotent retry of confirmed target
$before = count($cp->patches);
assertF01(
    $sync->retryPending(11, 'REFPROCESS001') === ControlPanelStatusSyncStates::CONFIRMED,
    'confirmed retry is no-op success'
);
assertF01(count($cp->patches) === $before, 'confirmed retry must not re-PATCH');

// --- empty-string persistence (production PrestaShop insert shape) ---
$store->save(1001, [
    'id_attempt' => 1001,
    'order_reference' => 'REFEMPTY1001',
    'cp_status_sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
    'cp_status_sync_status_id' => '',
    'cp_status_sync_status' => '',
    'cp_status_sync_error_class' => '',
]);
$cpEmpty1 = new F01FakeCp();
$syncEmpty1 = new ControlPanelStatusSyncService($store, $cpEmpty1);
assertF01(
    $syncEmpty1->synchronizeAfterHandoff(1001, 'REFEMPTY1001', $p1) === ControlPanelStatusSyncStates::CONFIRMED,
    'empty-string not_needed admits and confirms process1'
);
assertF01(count($cpEmpty1->patches) === 1, 'empty-string P1 admit triggers PATCH');
assertF01($store->rows[1001]['cp_status_sync_status_id'] === BankStatus::SENT_PROCESS1, 'empty-string P1 target persisted');

$store->save(1002, [
    'id_attempt' => 1002,
    'order_reference' => 'REFEMPTY1002',
    'cp_status_sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
    'cp_status_sync_status_id' => '',
    'cp_status_sync_status' => '',
    'cp_status_sync_error_class' => '',
]);
$cpEmpty2 = new F01FakeCp();
$syncEmpty2 = new ControlPanelStatusSyncService($store, $cpEmpty2);
assertF01(
    $syncEmpty2->synchronizeAfterHandoff(1002, 'REFEMPTY1002', $p2) === ControlPanelStatusSyncStates::CONFIRMED,
    'empty-string not_needed admits and confirms process2'
);
assertF01(count($cpEmpty2->patches) === 1, 'empty-string P2 admit triggers PATCH');

$lifecycle = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Order/PostControlPanelLifecycleService.php');
$coordinator = (string) file_get_contents(dirname(__DIR__, 2) . '/src/SmartUcf/SmartUcfSessionCoordinator.php');
$serviceSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Order/ControlPanelStatusSyncService.php');
assertF01(strpos($lifecycle, 'synchronizeAfterHandoff') !== false, 'P2 lifecycle uses sync service');
assertF01(strpos($lifecycle, 'retryPending') !== false, 'P1 lifecycle path retries pending sync');
assertF01(strpos($coordinator, 'retryPending') !== false, 'P1 created replay retries pending sync');
assertF01(strpos($coordinator, 'synchronizeAfterHandoff') !== false, 'P1 proven success uses sync service');
assertF01(strpos($coordinator, 'createSession') !== false, 'SmartUCF create remains available');
assertF01(
    preg_match('/function resultFromState[\s\S]*retryPending[\s\S]*return SmartUcfCoordinationResult::created/', $coordinator) === 1,
    'created replay retries PATCH without second SmartUCF claim path'
);
assertF01(strpos($serviceSrc, 'TERMINAL_ERROR_CODES') !== false, 'positive terminal allowlist present');
assertF01(strpos($serviceSrc, 'TERMINAL_SENT') !== false, 'incompatible terminal sent pair encoded');
assertF01(
    !preg_match('/\$status\s*>=\s*400\s*&&\s*\$status\s*<\s*500/', $serviceSrc),
    'generic 4xx terminal fallback removed'
);
assertF01(strpos($serviceSrc, 'bank_send_failed_smartucf') === false, 'SmartUCF failure path not expanded in sync service');
assertF01(
    !preg_match('/SENT_PROCESS2[^\n]*return 20|process2\s*>\s*process1/i', $serviceSrc),
    'no local process2 > process1 ranking remains'
);

fwrite(STDOUT, "OK (F01 durable CP status synchronization + lifecycle alignment)\n");
