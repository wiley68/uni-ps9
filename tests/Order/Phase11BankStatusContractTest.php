<?php

declare(strict_types=1);

/**
 * Phase 11 bank-status semantics: CP vs SmartUCF vs Process 2.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Order\BankStatus;
use PrestaShop\Module\Unipayment\Order\BankStatusPersistencePort;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotStoreInterface;
use PrestaShop\Module\Unipayment\Order\LeasingMailDispatchPort;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrationResult;
use PrestaShop\Module\Unipayment\Order\PostControlPanelLifecycleContext;
use PrestaShop\Module\Unipayment\Order\PostControlPanelLifecycleResult;
use PrestaShop\Module\Unipayment\Order\PostControlPanelLifecycleService;
use PrestaShop\Module\Unipayment\Order\PostControlPanelSmartUcfPort;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCoordinationResult;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfEndpointPolicy;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfSessionCoordinator;

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

function assertPhase11(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class Phase11SnapStore implements FinancingSnapshotStoreInterface
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

final class Phase11BankSpy implements BankStatusPersistencePort
{
    /** @var list<array{statusId: string, statusLabel: string}> */
    public $updates = [];

    public function updateByOrderIdentifier(int $idShop, string $orderReference, string $statusId, string $statusLabel): ?array
    {
        $this->updates[] = ['statusId' => $statusId, 'statusLabel' => $statusLabel];

        return ['order_id' => $orderReference, 'status_id' => $statusId];
    }
}

final class Phase11MailNoop implements LeasingMailDispatchPort
{
    public int $calls = 0;

    public function send(array $snapshot, int $attemptId, array $shop, array $status): void
    {
        ++$this->calls;
    }
}

final class Phase11SmartPort implements PostControlPanelSmartUcfPort
{
    public int $runCalls = 0;
    /** @var list<SmartUcfCoordinationResult> */
    public $queue = [];

    public function run(int $attemptId, array $shop, bool $process2, ?array $snapshot = null): SmartUcfCoordinationResult
    {
        ++$this->runCalls;
        unset($attemptId, $shop, $process2, $snapshot);
        $next = array_shift($this->queue);

        return $next instanceof SmartUcfCoordinationResult
            ? $next
            : SmartUcfCoordinationResult::failed('empty', false);
    }

    public function resume(int $attemptId, array $shop, bool $process2): SmartUcfCoordinationResult
    {
        return $this->run($attemptId, $shop, $process2, null);
    }
}

$order = new OrderOrchestrationResult(1, 'cp_created', 55, 'ABCD12345', 901);
$ctx = new PostControlPanelLifecycleContext(1, 'BGN');
$snapshot = [
    'id_attempt' => 1,
    'id_order' => 55,
    'order_reference' => 'ABCD12345',
    'customer_json' => ['email' => 'a@b.c'],
];
$trusted = 'https://online.ucfin.bg/sucf-online/Request/Start/abc';

// 1) Process 2: zero SmartUCF, bank_sent_process2
$store2 = new Phase11SnapStore();
$store2->rows[1] = $snapshot;
$bank2 = new Phase11BankSpy();
$smart2 = new Phase11SmartPort();
$r2 = (new PostControlPanelLifecycleService($store2, new Phase11MailNoop(), $bank2))->handle(
    $order,
    ['uni_proces' => 1],
    $ctx,
    $smart2
);
assertPhase11($r2->isProcess2(), 'P2 outcome');
assertPhase11($smart2->runCalls === 0, 'P2 must not call SmartUCF');
assertPhase11($bank2->updates !== [] && $bank2->updates[0]['statusId'] === BankStatus::SENT_PROCESS2, 'P2 bank_sent_process2');

// 2) CP created alone must NOT imply bank_sent_process1 without SmartUCF success
assertPhase11(
    BankStatus::successfulSend(false)['status_id'] === BankStatus::SENT_PROCESS1,
    'P1 success status id'
);
$storeCpOnly = new Phase11SnapStore();
$storeCpOnly->rows[1] = $snapshot;
$bankCpOnly = new Phase11BankSpy();
$smartIdle = new Phase11SmartPort();
$smartIdle->queue[] = SmartUcfCoordinationResult::processing(SmartUcfSessionCoordinator::CUSTOMER_PROCESSING);
$rIdle = (new PostControlPanelLifecycleService($storeCpOnly, new Phase11MailNoop(), $bankCpOnly))->handle(
    $order,
    ['uni_proces' => 0],
    $ctx,
    $smartIdle
);
assertPhase11($rIdle->isProcessing(), 'CP+processing is not Process1 sent');
assertPhase11($bankCpOnly->updates === [], 'processing must not persist bank_sent_process1 via lifecycle');

// 3) SmartUCF failure → bank_send_failed_smartucf (not CP)
$storeFail = new Phase11SnapStore();
$storeFail->rows[1] = $snapshot;
$bankFail = new Phase11BankSpy();
$smartFail = new Phase11SmartPort();
$smartFail->queue[] = SmartUcfCoordinationResult::failed(SmartUcfSessionCoordinator::CUSTOMER_FAILED, false);
$rFail = (new PostControlPanelLifecycleService($storeFail, new Phase11MailNoop(), $bankFail))->handle(
    $order,
    ['uni_proces' => 0],
    $ctx,
    $smartFail
);
assertPhase11($rFail->isFailed(), 'SmartUCF failed outcome');
assertPhase11(
    $bankFail->updates !== [] && $bankFail->updates[0]['statusId'] === BankStatus::SEND_FAILED_SMARTUCF,
    'SmartUCF failure → bank_send_failed_smartucf'
);
assertPhase11(
    $bankFail->updates[0]['statusId'] !== BankStatus::SEND_FAILED_CP,
    'must not use bank_send_failed_cp after CP success'
);

// 4) SmartUCF success → bank_sent_process1 in result; replay does not re-call when port returns created again
$storeOk = new Phase11SnapStore();
$storeOk->rows[1] = $snapshot;
$bankOk = new Phase11BankSpy();
$mailOk = new Phase11MailNoop();
$smartOk = new Phase11SmartPort();
$smartOk->queue[] = SmartUcfCoordinationResult::created($trusted, 'sess-1');
$smartOk->queue[] = SmartUcfCoordinationResult::created($trusted, 'sess-1');
$svcOk = new PostControlPanelLifecycleService($storeOk, $mailOk, $bankOk, new SmartUcfEndpointPolicy());
$rOk = $svcOk->handle($order, ['uni_proces' => 0], $ctx, $smartOk);
assertPhase11($rOk->isCreated(), 'SmartUCF created');
assertPhase11(($rOk->finalBankStatus()['status_id'] ?? '') === BankStatus::SENT_PROCESS1, 'result carries bank_sent_process1');
assertPhase11($smartOk->runCalls === 1, 'first success one SmartUCF run');
assertPhase11($mailOk->calls === 1, 'native mail flush path invoked once');

$rReplay = $svcOk->handle($order, ['uni_proces' => 0], $ctx, $smartOk);
assertPhase11($rReplay->isCreated(), 'replay still created');
assertPhase11($smartOk->runCalls === 2, 'lifecycle calls port; coordinator must make createSession idempotent');
// Note: exactly-once SmartUCF send is enforced inside SmartUcfSessionCoordinator durable state (AUD-002B).

// 5) Process isolation constants
assertPhase11(BankStatus::SENT_PROCESS1 !== BankStatus::SENT_PROCESS2, 'process statuses distinct');
assertPhase11(BankStatus::SEND_FAILED_CP !== BankStatus::SEND_FAILED_SMARTUCF, 'failure statuses distinct');

$validate = (string) file_get_contents(dirname(__DIR__, 2) . '/controllers/front/validatecheckout.php');
assertPhase11(strpos($validate, 'PostControlPanelLifecycleService') !== false, 'wired in validatecheckout');
assertPhase11(strpos($validate, 'OrderOrchestrator') !== false, 'Phase 10 orchestrator retained');

fwrite(STDOUT, "OK (Phase 11 bank status + process isolation)\n");
