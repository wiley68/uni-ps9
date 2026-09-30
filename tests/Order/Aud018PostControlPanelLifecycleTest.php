<?php

declare(strict_types=1);

/**
 * AUD-018 — shared post-Control-Panel lifecycle service.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/Support/EurOrderCurrencyGuardFixture.php';

use PrestaShop\Module\Unipayment\Order\BankStatus;
use PrestaShop\Module\Unipayment\Order\DeferredOrderMailQueue;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotStoreInterface;
use PrestaShop\Module\Unipayment\Order\LeasingMailDispatchPort;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrationResult;
use PrestaShop\Module\Unipayment\Order\PostControlPanelLifecycleContext;
use PrestaShop\Module\Unipayment\Order\PostControlPanelLifecycleResult;
use PrestaShop\Module\Unipayment\Order\PostControlPanelLifecycleService;
use PrestaShop\Module\Unipayment\Order\PostControlPanelSmartUcfPort;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCoordinationResult;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfEndpointPolicy;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfFailureClassification;
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

if (!defined('_PS_MAIL_DIR_')) {
    define('_PS_MAIL_DIR_', '/tmp/');
}

if (!class_exists('Mail', false)) {
    class Mail
    {
        /** @var int */
        public static $sendCalls = 0;

        public static function Send(
            int $idLang,
            string $template,
            string $subject,
            array $templateVars,
            mixed $to,
            mixed $toName = null,
            mixed $from = null,
            mixed $fromName = null,
            mixed $fileAttachment = null,
            mixed $mode_smtp = null,
            mixed $templatePath = null,
            bool $die = false,
            ?int $idShop = null,
            mixed $bcc = null,
            mixed $replyTo = null
        ): bool {
            unset(
                $idLang,
                $template,
                $subject,
                $templateVars,
                $to,
                $toName,
                $from,
                $fromName,
                $fileAttachment,
                $mode_smtp,
                $templatePath,
                $die,
                $idShop,
                $bcc,
                $replyTo
            );
            ++self::$sendCalls;

            return true;
        }
    }
}

function assertAud018(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class Aud018MemorySnapshotStore implements FinancingSnapshotStoreInterface
{
    /** @var array<int, array<string, mixed>> */
    private $rows = [];

    /** @param array<string, mixed> $snapshot */
    public function seed(int $attemptId, array $snapshot): void
    {
        $this->rows[$attemptId] = $snapshot;
    }

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
}

final class Aud018FakeSmartUcfPort implements PostControlPanelSmartUcfPort
{
    /** @var int */
    public $runCalls = 0;

    /** @var int */
    public $resumeCalls = 0;

    /** @var SmartUcfCoordinationResult */
    public $nextResult;

    public function __construct(?SmartUcfCoordinationResult $nextResult = null)
    {
        $this->nextResult = $nextResult ?? SmartUcfCoordinationResult::processing('wait');
    }

    public function run(int $attemptId, array $shop, bool $process2, ?array $snapshot = null): SmartUcfCoordinationResult
    {
        ++$this->runCalls;
        unset($attemptId, $shop, $process2, $snapshot);

        return $this->nextResult;
    }

    public function resume(int $attemptId, array $shop, bool $process2): SmartUcfCoordinationResult
    {
        ++$this->resumeCalls;
        unset($attemptId, $shop, $process2);

        return $this->nextResult;
    }
}

final class Aud018NoopMailDispatcher implements LeasingMailDispatchPort
{
    public function send(array $snapshot, int $attemptId, array $shop, array $status): void
    {
        unset($snapshot, $attemptId, $shop, $status);
    }
}

final class Aud018ThrowingMailDispatcher implements LeasingMailDispatchPort
{
    public function send(array $snapshot, int $attemptId, array $shop, array $status): void
    {
        unset($snapshot, $attemptId, $shop, $status);
        throw new RuntimeException('mail failed');
    }
}

final class Aud018NoopBankStatusPersistence implements \PrestaShop\Module\Unipayment\Order\BankStatusPersistencePort
{
    public function updateByOrderIdentifier(int $idShop, string $orderReference, string $statusId, string $statusLabel): ?array
    {
        unset($idShop, $orderReference, $statusId, $statusLabel);

        return null;
    }
}

final class Aud018BankStatusSpy implements \PrestaShop\Module\Unipayment\Order\BankStatusPersistencePort
{
    /** @var list<array{shop:int, reference:string, id:string}> */
    public $updates = [];

    /** @var bool */
    public $throwOnUpdate = false;

    public function __construct() {}

    public function updateByOrderIdentifier(int $idShop, string $orderReference, string $statusId, string $statusLabel): ?array
    {
        if ($this->throwOnUpdate) {
            throw new RuntimeException('bank status failed');
        }
        $this->updates[] = [
            'shop' => $idShop,
            'reference' => $orderReference,
            'id' => $statusId,
        ];

        return ['ps_order_id' => 1];
    }
}

$root = dirname(__DIR__, 2);
$order = new OrderOrchestrationResult(10, 'cp_created', 100, 'REF100', 555);
$shopProcess2 = ['uni_proces' => 1];
$shopProcess1 = ['uni_proces' => 0];
$snapshot = ['id_order' => 100, 'id_currency' => 1, 'currency_iso' => 'EUR', 'customer_json' => [], 'address_json' => []];
$context = new PostControlPanelLifecycleContext(1, 'EUR');
$replayContext = new PostControlPanelLifecycleContext(1, 'EUR', true, false);

// EUR-PS9-004 — direct post-CP entry must prove persisted CP success before P1 or P2.
$replayPayload = [
    'order_id' => 'REF100', 'name' => 'Buyer', 'phone' => '', 'email' => '',
    'address' => '', 'address2' => '', 'price' => 100.0, 'vnoska' => 10.0,
    'gpr' => 1.0, 'vnoski' => 10, 'parva' => 0.0, 'products_id' => '1',
    'products_name' => 'Item', 'products_q' => '1', 'type_client' => 1,
    'currency' => 'EUR', 'version' => '2.0.3',
];
$replaySnapshot = array_replace($snapshot, [
    'id_attempt' => 10, 'order_reference' => 'REF100', 'order_total' => 100,
    'control_panel_order_id' => 555, 'lifecycle_status' => 'cp_created',
]);
$replayAttempt = [
    'id_attempt' => 10, 'id_order' => 100, 'state' => 'cp_created',
    'control_panel_order_id' => 555,
    'cp_payload' => json_encode($replayPayload, JSON_THROW_ON_ERROR),
];
$realCpGuard = new \PrestaShop\Module\Unipayment\Order\ControlPanelSuccessReplayGuard(
    eurTestOrderCurrencyGuard(),
    static function (int $attemptId) use (&$replayAttempt): ?array {
        return $attemptId === 10 ? $replayAttempt : null;
    }
);
$realCpGuard->assertAttempt(10, $replaySnapshot, 555);
$replayStore = new Aud018MemorySnapshotStore();
$replayStore->seed(10, $replaySnapshot);
$replayBank = new Aud018BankStatusSpy();
$replaySmart = new Aud018FakeSmartUcfPort();
$replayService = new PostControlPanelLifecycleService(
    $replayStore, new Aud018NoopMailDispatcher(), $replayBank, null, null, null,
    eurTestOrderCurrencyGuard(),
    static function (int $attemptId, array $row, ?int $cpId) use ($realCpGuard): void {
        $realCpGuard->assertAttempt($attemptId, $row, $cpId);
    }
);
$replayNoMail = new PostControlPanelLifecycleContext(1, 'EUR', false, false);
$replayGood = $replayService->handle($order, $shopProcess2, $replayNoMail, $replaySmart);
assertAud018($replayGood->isProcess2(), 'EUR: valid durable CP success reaches Process 2');
$baselineBankCount = count($replayBank->updates);
$baselineSnapshot = $replayStore->findByAttempt(10);
foreach ([
    ['state' => 'cp_outcome_unknown'],
    ['control_panel_order_id' => 0],
    ['control_panel_order_id' => 556],
] as $invalidAttempt) {
    $replayAttempt = array_replace($replayAttempt, $invalidAttempt);
    foreach ([$shopProcess2, $shopProcess1] as $mode) {
        $rejected = $replayService->handle($order, $mode, $replayNoMail, $replaySmart);
        assertAud018($rejected->outcome() === PostControlPanelLifecycleResult::OUTCOME_POST_ORDER_FAILURE && !$rejected->isCreated() && !$rejected->isProcess2(),
            'EUR: invalid CP result must not continue post-CP lifecycle');
    }
    assertAud018(count($replayBank->updates) === $baselineBankCount && $replaySmart->runCalls === 0,
        'EUR: rejected CP result has no bank status or SmartUCF side effect');
    assertAud018($replayStore->findByAttempt(10) === $baselineSnapshot,
        'EUR: rejected CP result does not repair snapshot');
    $replayAttempt['state'] = 'cp_created';
    $replayAttempt['control_panel_order_id'] = 555;
}

// Test A — Process 2
$storeA = new Aud018MemorySnapshotStore();
$storeA->seed(10, $snapshot);
$bankSpy = new Aud018BankStatusSpy();
$smartFake = new Aud018FakeSmartUcfPort();
$serviceA = eurTestPostService($storeA, new Aud018NoopMailDispatcher(), $bankSpy);
$resultA = $serviceA->handle($order, $shopProcess2, $context, $smartFake);
assertAud018($resultA->outcome() === PostControlPanelLifecycleResult::OUTCOME_PROCESS2, 'A: process2 outcome');
assertAud018($smartFake->runCalls === 0 && $smartFake->resumeCalls === 0, 'A: SmartUCF not invoked for process2');
assertAud018($bankSpy->updates !== [] && $bankSpy->updates[0]['id'] === BankStatus::SENT_PROCESS2, 'A: process2 bank status persisted');
assertAud018($resultA->emailSent() === true, 'A: leasing email sent');

// Test A2 — Process 2 bank status persists even when mail is skipped (Phase 12 decoupling)
$storeA2 = new Aud018MemorySnapshotStore();
$storeA2->seed(10, $snapshot);
$bankSpyA2 = new Aud018BankStatusSpy();
$mailSpyA2 = new Aud018NoopMailDispatcher();
$noMailContext = new PostControlPanelLifecycleContext(1, 'EUR', false, false);
$resultA2 = (eurTestPostService($storeA2, $mailSpyA2, $bankSpyA2))->handle(
    $order,
    $shopProcess2,
    $noMailContext,
    new Aud018FakeSmartUcfPort()
);
assertAud018($resultA2->isProcess2(), 'A2: process2 outcome without mail');
assertAud018(
    $bankSpyA2->updates !== [] && $bankSpyA2->updates[0]['id'] === BankStatus::SENT_PROCESS2,
    'A2: bank_sent_process2 persists when sendLeasingEmail=false'
);
assertAud018($resultA2->emailSent() === false, 'A2: leasing email not sent when flag false');

// Test L — explicit process2 no SmartUCF
assertAud018($smartFake->runCalls === 0, 'L: process2 must not run SmartUCF');

// Test B — created trusted redirect
$trustedRedirect = (new SmartUcfEndpointPolicy())->buildApplicationRedirect(
    'https://online.ucfin.bg/sucf-online/Request/Start',
    'session123'
);
$storeB = new Aud018MemorySnapshotStore();
$storeB->seed(10, $snapshot);
$smartCreated = new Aud018FakeSmartUcfPort(SmartUcfCoordinationResult::created($trustedRedirect, 'session123'));
$resultB = (eurTestPostService($storeB, new Aud018NoopMailDispatcher(), new Aud018NoopBankStatusPersistence()))->handle(
    $order,
    $shopProcess1,
    $context,
    $smartCreated
);
assertAud018($resultB->isCreated() && $resultB->redirectUrl() === $trustedRedirect, 'B: trusted redirect returned');
assertAud018($resultB->finalBankStatus()['status_id'] === BankStatus::SENT_PROCESS1, 'B: successful-send status');
assertAud018($resultB->emailSent() === true, 'B: email sent on created');

// Test C — untrusted redirect
$storeC = new Aud018MemorySnapshotStore();
$storeC->seed(10, $snapshot);
$smartUntrusted = new Aud018FakeSmartUcfPort(
    SmartUcfCoordinationResult::created('https://evil.example/sucf-online/Request/Start/session123', 'session123')
);
PrestaShopLogger::$logs = [];
$resultC = (eurTestPostService($storeC, new Aud018NoopMailDispatcher(), new Aud018NoopBankStatusPersistence()))->handle(
    $order,
    $shopProcess1,
    $context,
    $smartUntrusted
);
assertAud018($resultC->isOutcomeUnknown(), 'C: untrusted redirect becomes outcome_unknown');
assertAud018($resultC->redirectUrl() === '', 'C: no usable redirect');
assertAud018($smartUntrusted->runCalls === 1, 'C: no duplicate SmartUCF create in service layer');
assertAud018(
    strpos(implode("\n", PrestaShopLogger::$logs), 'blocked untrusted SmartUCF redirect') !== false,
    'C: untrusted redirect logged'
);

// Test D — processing
$storeD = new Aud018MemorySnapshotStore();
$storeD->seed(10, $snapshot);
$smartProcessing = new Aud018FakeSmartUcfPort(SmartUcfCoordinationResult::processing('still working'));
$resultD = (eurTestPostService($storeD, new Aud018NoopMailDispatcher(), new Aud018NoopBankStatusPersistence()))->handle(
    $order,
    $shopProcess1,
    $context,
    $smartProcessing
);
assertAud018($resultD->isProcessing(), 'D: processing outcome');
assertAud018($resultD->emailSent() === false, 'D/M: no email while processing');

// Test E — outcome unknown
$storeE = new Aud018MemorySnapshotStore();
$storeE->seed(10, $snapshot);
$smartUnknown = new Aud018FakeSmartUcfPort(
    SmartUcfCoordinationResult::outcomeUnknown(SmartUcfSessionCoordinator::CUSTOMER_OUTCOME_UNKNOWN)
);
$resultE = (eurTestPostService($storeE, new Aud018NoopMailDispatcher(), new Aud018NoopBankStatusPersistence()))->handle(
    $order,
    $shopProcess1,
    $context,
    $smartUnknown
);
assertAud018($resultE->isOutcomeUnknown(), 'E: outcome_unknown normalized');
assertAud018(
    $resultE->finalBankStatus()['status_id'] === BankStatus::SENT_PROCESS1,
    'N: outcome_unknown keeps successful-send status'
);
assertAud018($resultE->emailSent() === true, 'E: email sent for outcome_unknown');

// Test F — failed
$storeF = new Aud018MemorySnapshotStore();
$storeF->seed(10, $snapshot);
$smartFailed = new Aud018FakeSmartUcfPort(SmartUcfCoordinationResult::failed(
    'failed msg',
    false,
    SmartUcfFailureClassification::CLASS_REMOTE_REJECT
));
$failedBankSpy = new Aud018BankStatusSpy();
$resultF = (eurTestPostService($storeF, new Aud018NoopMailDispatcher(), $failedBankSpy))->handle(
    $order,
    $shopProcess1,
    $context,
    $smartFailed
);
assertAud018($resultF->isFailed(), 'F: failed outcome');
assertAud018($resultF->finalBankStatus()['status_id'] === BankStatus::SEND_FAILED_SMARTUCF, 'F: smartUcfFailure status');
assertAud018($failedBankSpy->updates !== [] && $failedBankSpy->updates[0]['id'] === BankStatus::SEND_FAILED_SMARTUCF, 'F: SmartUCF failure status persisted');
assertAud018($resultF->emailSent() === true, 'F: email sent on failed');

// Test G — snapshot missing
DeferredOrderMailQueue::start();
Mail::$sendCalls = 0;
DeferredOrderMailQueue::intercept([
    'template' => 'order_conf',
    'idLang' => 1,
    'subject' => 'test',
    'templateVars' => [],
    'to' => 'a@example.com',
]);
$storeG = new Aud018MemorySnapshotStore();
$smartG = new Aud018FakeSmartUcfPort();
$resultG = (eurTestPostService($storeG, new Aud018NoopMailDispatcher(), new Aud018NoopBankStatusPersistence()))->handle(
    $order,
    $shopProcess1,
    $context,
    $smartG
);
assertAud018($resultG->outcome() === PostControlPanelLifecycleResult::OUTCOME_SNAPSHOT_MISSING, 'G: snapshot missing');
assertAud018($smartG->runCalls === 0, 'G: no SmartUCF without snapshot');
assertAud018(Mail::$sendCalls === 1, 'G: deferred order mail queue flushed');
DeferredOrderMailQueue::discard();

// Test H — email failure
$storeH = new Aud018MemorySnapshotStore();
$storeH->seed(10, $snapshot);
$resultH = (eurTestPostService(
    $storeH,
    new Aud018ThrowingMailDispatcher(),
    new Aud018NoopBankStatusPersistence()
))->handle($order, $shopProcess2, $context, new Aud018FakeSmartUcfPort());
assertAud018($resultH->emailError() !== null, 'H: email failure captured');
assertAud018($resultH->outcome() === PostControlPanelLifecycleResult::OUTCOME_PROCESS2, 'H: order outcome preserved');

// Test I — bank status persistence failure
$storeI = new Aud018MemorySnapshotStore();
$storeI->seed(10, $snapshot);
$bankFail = new Aud018BankStatusSpy();
$bankFail->throwOnUpdate = true;
$resultI = (eurTestPostService($storeI, new Aud018NoopMailDispatcher(), $bankFail))->handle(
    $order,
    $shopProcess2,
    $context,
    new Aud018FakeSmartUcfPort()
);
assertAud018($resultI->isProcess2(), 'I: process2 result still returned');

// Test J — replay uses resume, no email
$storeJ = new Aud018MemorySnapshotStore();
$storeJ->seed(10, $snapshot);
$smartReplay = new Aud018FakeSmartUcfPort(SmartUcfCoordinationResult::processing('replay'));
$resultJ = (eurTestPostService($storeJ, new Aud018NoopMailDispatcher(), new Aud018NoopBankStatusPersistence()))->handle(
    $order,
    $shopProcess1,
    $replayContext,
    $smartReplay
);
assertAud018($smartReplay->resumeCalls === 1 && $smartReplay->runCalls === 0, 'J: replay uses resume only');
assertAud018($resultJ->emailSent() === false, 'J: replay skips leasing email');

// Test K — checkout + product/cart popup share post-CP lifecycle
$productPopup = (string) file_get_contents($root . '/controllers/front/productpopup.php');
$cartPopup = (string) file_get_contents($root . '/controllers/front/cartpopup.php');
$checkout = (string) file_get_contents($root . '/controllers/front/validatecheckout.php');
assertAud018(strpos($checkout, 'PostControlPanelLifecycleService') !== false, 'K: validatecheckout uses lifecycle service');
assertAud018(strpos($checkout, 'SmartUcfSessionCoordinator') !== false, 'K: validatecheckout wires SmartUCF coordinator');
assertAud018(strpos($checkout, 'applySmartUcfResultToResponse') === false, 'K: duplicated SmartUCF mapper removed');
assertAud018(strpos($productPopup, 'PostControlPanelLifecycleService') !== false, 'K: product popup uses shared lifecycle');
assertAud018(strpos($cartPopup, 'PostControlPanelLifecycleService') !== false, 'K: cart popup uses shared lifecycle');

// Test O — trusted vs untrusted policy
$policy = new SmartUcfEndpointPolicy();
assertAud018($policy->isTrustedApplicationRedirect($trustedRedirect), 'O: trusted URL accepted');
assertAud018(!$policy->isTrustedApplicationRedirect('https://evil.example/sucf-online/Request/Start/x'), 'O: untrusted URL rejected');

fwrite(STDOUT, "OK (AUD-018 post-CP lifecycle service)\n");
