<?php

declare(strict_types=1);

/**
 * Regression: local/pre-send SmartUCF failures must not persist bank_send_failed_smartucf.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require $root . '/tests/fixtures/shop_snapshot.php';

use PrestaShop\Module\Unipayment\Order\BankStatus;
use PrestaShop\Module\Unipayment\Order\BankStatusPersistencePort;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotStoreInterface;
use PrestaShop\Module\Unipayment\Order\LeasingMailDispatchPort;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrationResult;
use PrestaShop\Module\Unipayment\Order\PostControlPanelLifecycleContext;
use PrestaShop\Module\Unipayment\Order\PostControlPanelLifecycleResult;
use PrestaShop\Module\Unipayment\Order\PostControlPanelLifecycleService;
use PrestaShop\Module\Unipayment\Order\PostControlPanelSmartUcfPort;
use PrestaShop\Module\Unipayment\SmartUcf\Certificate\CertificateSyncException;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCoordinationResult;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfEndpointPolicy;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfFailureClassification;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfLifecycleStates;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfPayloadBuilder;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfSessionCoordinator;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfSessionGatewayInterface;

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

function assertPreSend(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class PreSendSnapStore implements FinancingSnapshotStoreInterface
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

final class PreSendBankSpy implements BankStatusPersistencePort
{
    /** @var list<array{statusId: string, statusLabel: string}> */
    public $updates = [];

    public function updateByOrderIdentifier(int $idShop, string $orderReference, string $statusId, string $statusLabel): ?array
    {
        unset($idShop, $orderReference);
        $this->updates[] = ['statusId' => $statusId, 'statusLabel' => $statusLabel];

        return ['order_id' => 'ref', 'status_id' => $statusId];
    }
}

final class PreSendMailNoop implements LeasingMailDispatchPort
{
    public function send(array $snapshot, int $attemptId, array $shop, array $status): void
    {
        unset($snapshot, $attemptId, $shop, $status);
    }
}

final class PreSendSmartPort implements PostControlPanelSmartUcfPort
{
    /** @var list<SmartUcfCoordinationResult> */
    public $queue = [];

    public int $runCalls = 0;

    public function run(int $attemptId, array $shop, bool $process2, ?array $snapshot = null): SmartUcfCoordinationResult
    {
        ++$this->runCalls;
        unset($attemptId, $shop, $process2, $snapshot);
        $next = array_shift($this->queue);

        return $next instanceof SmartUcfCoordinationResult
            ? $next
            : SmartUcfCoordinationResult::processing('wait');
    }

    public function resume(int $attemptId, array $shop, bool $process2): SmartUcfCoordinationResult
    {
        return $this->run($attemptId, $shop, $process2, null);
    }
}

final class PreSendFakeSessionGateway implements SmartUcfSessionGatewayInterface
{
    public int $createCalls = 0;

    /** @var array<string, mixed> */
    public array $session = [
        'session_id' => 'sess-retry',
        'redirect_url' => 'https://online.ucfin.bg/sucf-online/Request/Start/sess-retry',
        'http_code' => 200,
        'raw_request' => '{}',
        'raw_response' => '{"ok":1}',
    ];

    public function createSession(array $shop, array $snapshot, $certificateLease = null): array
    {
        ++$this->createCalls;
        unset($shop, $snapshot, $certificateLease);

        return $this->session;
    }
}

final class PreSendMemoryLifecycle
{
    /** @var array<string, mixed> */
    public array $row;

    public function __construct(array $row)
    {
        $this->row = $row;
    }

    public function readAndNormalize(int $attemptId): ?array
    {
        unset($attemptId);

        return $this->row;
    }

    public function claimForSubmitting(int $attemptId): ?array
    {
        unset($attemptId);
        $state = (string) ($this->row['smartucf_state'] ?? '');
        $retryable = !empty($this->row['smartucf_retryable']);
        if (
            $state === SmartUcfLifecycleStates::NOT_STARTED
            || ($state === SmartUcfLifecycleStates::FAILED && $retryable)
        ) {
            $this->row['smartucf_state'] = SmartUcfLifecycleStates::SUBMITTING;
            $this->row['smartucf_retryable'] = 0;

            return $this->row;
        }

        return null;
    }

    public function markCreated(int $attemptId, string $sessionId, string $redirectUrl, int $httpCode): void
    {
        unset($attemptId);
        $this->row['smartucf_state'] = SmartUcfLifecycleStates::CREATED;
        $this->row['smartucf_session_id'] = $sessionId;
        $this->row['smartucf_redirect_url'] = $redirectUrl;
        $this->row['smartucf_http_code'] = $httpCode;
    }
}

final class PreSendThrowingCertificateSynchronizer
{
    public function ensureCurrent(): void
    {
        throw new CertificateSyncException('certificate sync failed', CertificateSyncException::REASON_CP_UNAVAILABLE);
    }
}

/**
 * @param PreSendMemoryLifecycle $lifecycle
 * @param object|null $certificateSynchronizer
 */
function preSendCoordinatorWith(
    PreSendMemoryLifecycle $lifecycle,
    PreSendFakeSessionGateway $gateway,
    ?object $certificateSynchronizer = null
): SmartUcfSessionCoordinator {
    $ref = new ReflectionClass(SmartUcfSessionCoordinator::class);
    /** @var SmartUcfSessionCoordinator $coordinator */
    $coordinator = $ref->newInstanceWithoutConstructor();
    foreach ([
        'lifecycle' => $lifecycle,
        'client' => $gateway,
        'payloadBuilder' => new SmartUcfPayloadBuilder(),
        'classifier' => new \PrestaShop\Module\Unipayment\SmartUcf\SmartUcfFailureClassifier(),
        'snapshots' => null,
        'cpClient' => null,
        'controlPanelApi' => null,
        'certificateSynchronizer' => $certificateSynchronizer,
        'module' => null,
        'context' => null,
        'statusSync' => null,
    ] as $name => $value) {
        $prop = $ref->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($coordinator, $value);
    }

    return $coordinator;
}

function preSendLifecycleHandle(
    SmartUcfCoordinationResult $smartResult,
    PreSendBankSpy $bankSpy
): PostControlPanelLifecycleResult {
    $order = new OrderOrchestrationResult(1, 'cp_created', 55, 'ABCD12345', 901);
    $ctx = new PostControlPanelLifecycleContext(1, 'BGN');
    $snapshot = [
        'id_attempt' => 1,
        'id_order' => 55,
        'order_reference' => 'ABCD12345',
        'customer_json' => ['email' => 'a@b.c'],
    ];
    $store = new PreSendSnapStore();
    $store->rows[1] = $snapshot;
    $smart = new PreSendSmartPort();
    $smart->queue[] = $smartResult;

    return (new PostControlPanelLifecycleService($store, new PreSendMailNoop(), $bankSpy))->handle(
        $order,
        ['uni_proces' => 0],
        $ctx,
        $smart
    );
}

$order = new OrderOrchestrationResult(1, 'cp_created', 55, 'ABCD12345', 901);
$ctx = new PostControlPanelLifecycleContext(1, 'BGN');
$snapshot = [
    'id_attempt' => 1,
    'id_order' => 55,
    'order_reference' => 'ABCD12345',
    'customer_json' => ['email' => 'a@b.c'],
    'lines_json' => [['name' => 'Item', 'id_product' => 1, 'quantity' => 1, 'total' => 100]],
    'address_json' => ['address1' => 'Addr', 'city' => 'Sofia', 'postcode' => '1000'],
    'kop_code' => 'KOP',
    'order_total' => 100,
    'first_installment' => 0,
    'months' => 12,
    'monthly_installment' => 10,
    'currency_iso' => 'BGN',
];
$trusted = 'https://online.ucfin.bg/sucf-online/Request/Start/sess-retry';

// 1) smartucf_credentials_unavailable — coordinator + lifecycle, no client call, not_started preserved
$life1 = new PreSendMemoryLifecycle([
    'id_attempt' => 1,
    'order_reference' => 'ABCD12345',
    'smartucf_state' => SmartUcfLifecycleStates::NOT_STARTED,
]);
$gateway1 = new PreSendFakeSessionGateway();
$coord1 = preSendCoordinatorWith($life1, $gateway1);
$shopMissing = unipayment_valid_shop_snapshot(['uni_sertificat' => 0]);
unset($shopMissing['uni_user'], $shopMissing['uni_password']);
$smart1 = $coord1->run(1, $shopMissing, false, $snapshot);
assertPreSend($smart1->isFailed() && $smart1->isPreSendFailure(), '1: credentials unavailable is pre-send failed');
assertPreSend($smart1->errorClass() === SmartUcfSessionCoordinator::ERROR_CREDENTIALS_UNAVAILABLE, '1: error class');
assertPreSend($gateway1->createCalls === 0, '1: no SmartUCF client call');
assertPreSend(
    (string) ($life1->row['smartucf_state'] ?? '') === SmartUcfLifecycleStates::NOT_STARTED,
    '1: lifecycle remains not_started'
);

$bank1 = new PreSendBankSpy();
$result1 = preSendLifecycleHandle($smart1, $bank1);
assertPreSend($result1->isPreSendFailure(), '1: lifecycle pre-send outcome');
assertPreSend(!$result1->isFailed(), '1: not definitive failed outcome');
assertPreSend($bank1->updates === [], '1: no bank_send_failed_smartucf persisted');
assertPreSend(
    $result1->smartUcfErrorClass() === SmartUcfSessionCoordinator::ERROR_CREDENTIALS_UNAVAILABLE,
    '1: error class preserved on lifecycle result'
);

// 2) generic CLASS_PRE_SEND through lifecycle port
$bank2 = new PreSendBankSpy();
$result2 = preSendLifecycleHandle(
    SmartUcfCoordinationResult::failed(
        SmartUcfSessionCoordinator::CUSTOMER_FAILED,
        true,
        SmartUcfFailureClassification::CLASS_PRE_SEND
    ),
    $bank2
);
assertPreSend($result2->isPreSendFailure(), '2: CLASS_PRE_SEND maps to pre-send outcome');
assertPreSend(!$result2->isFailed(), '2: not definitive failed');
assertPreSend($bank2->updates === [], '2: no bank failure persisted');

// 3) certificate pre-send failure through coordinator + lifecycle
$life3 = new PreSendMemoryLifecycle([
    'id_attempt' => 1,
    'order_reference' => 'ABCD12345',
    'smartucf_state' => SmartUcfLifecycleStates::NOT_STARTED,
]);
$gateway3 = new PreSendFakeSessionGateway();
$coord3 = preSendCoordinatorWith($life3, $gateway3, new PreSendThrowingCertificateSynchronizer());
$shopCert = unipayment_valid_shop_snapshot([
    'uni_sertificat' => 1,
    'uni_user' => 'demo-user',
    'uni_password' => 'demo-secret-password',
]);
$smart3 = $coord3->run(1, $shopCert, false, $snapshot);
assertPreSend($smart3->isPreSendFailure(), '3: certificate sync pre-send');
assertPreSend($gateway3->createCalls === 0, '3: no client call after certificate failure');
$bank3 = new PreSendBankSpy();
$result3 = preSendLifecycleHandle($smart3, $bank3);
assertPreSend($result3->isPreSendFailure(), '3: lifecycle pre-send for certificate');
assertPreSend($bank3->updates === [], '3: no bank_send_failed_smartucf');

// 4) definitive SmartUCF remote reject still persists bank_send_failed_smartucf
$bank4 = new PreSendBankSpy();
$result4 = preSendLifecycleHandle(
    SmartUcfCoordinationResult::failed(
        SmartUcfSessionCoordinator::CUSTOMER_FAILED,
        false,
        SmartUcfFailureClassification::CLASS_REMOTE_REJECT
    ),
    $bank4
);
assertPreSend($result4->isFailed(), '4: definitive remote failure');
assertPreSend(
    ($result4->finalBankStatus()['status_id'] ?? '') === BankStatus::SEND_FAILED_SMARTUCF,
    '4: carries smartUcf failure status'
);
assertPreSend(
    $bank4->updates !== [] && $bank4->updates[0]['statusId'] === BankStatus::SEND_FAILED_SMARTUCF,
    '4: persists bank_send_failed_smartucf'
);

// 5) outcome unknown remains non-definitive
$bank5 = new PreSendBankSpy();
$result5 = preSendLifecycleHandle(
    SmartUcfCoordinationResult::outcomeUnknown(SmartUcfSessionCoordinator::CUSTOMER_OUTCOME_UNKNOWN),
    $bank5
);
assertPreSend($result5->isOutcomeUnknown(), '5: outcome unknown unchanged');
assertPreSend(!$result5->isFailed(), '5: not failed');
assertPreSend($bank5->updates === [], '5: no definitive failure bank status');

// 6) processing unchanged
$bank6 = new PreSendBankSpy();
$result6 = preSendLifecycleHandle(
    SmartUcfCoordinationResult::processing(SmartUcfSessionCoordinator::CUSTOMER_PROCESSING),
    $bank6
);
assertPreSend($result6->isProcessing(), '6: processing unchanged');
assertPreSend($bank6->updates === [], '6: processing does not persist bank status');

// 7) successful created/session path unchanged
$store7 = new PreSendSnapStore();
$store7->rows[1] = $snapshot;
$bank7 = new PreSendBankSpy();
$smart7 = new PreSendSmartPort();
$smart7->queue[] = SmartUcfCoordinationResult::created($trusted, 'sess-retry');
$result7 = (new PostControlPanelLifecycleService(
    $store7,
    new PreSendMailNoop(),
    $bank7,
    new SmartUcfEndpointPolicy()
))->handle($order, ['uni_proces' => 0], $ctx, $smart7);
assertPreSend($result7->isCreated(), '7: created unchanged');
assertPreSend(($result7->finalBankStatus()['status_id'] ?? '') === BankStatus::SENT_PROCESS1, '7: success bank status in result');
assertPreSend($bank7->updates === [], '7: created path does not persist via failed branch');

// 8) repaired credentials can retry from preserved local state
$life8 = new PreSendMemoryLifecycle([
    'id_attempt' => 1,
    'order_reference' => 'ABCD12345',
    'smartucf_state' => SmartUcfLifecycleStates::NOT_STARTED,
]);
$gateway8 = new PreSendFakeSessionGateway();
$coord8 = preSendCoordinatorWith($life8, $gateway8);
$shopBroken = unipayment_valid_shop_snapshot(['uni_sertificat' => 0]);
unset($shopBroken['uni_user'], $shopBroken['uni_password']);
$firstSmart = $coord8->run(1, $shopBroken, false, $snapshot);
$bank8a = new PreSendBankSpy();
$firstLifecycle = preSendLifecycleHandle($firstSmart, $bank8a);
assertPreSend($firstLifecycle->isPreSendFailure(), '8a: first attempt pre-send');
assertPreSend($bank8a->updates === [], '8a: no bank failure on first attempt');

$shopFixed = unipayment_valid_shop_snapshot([
    'uni_user' => 'demo-user',
    'uni_password' => 'demo-secret-password',
    'uni_sertificat' => 0,
]);
$secondSmart = $coord8->run(1, $shopFixed, false, $snapshot);
assertPreSend($secondSmart->isCreated(), '8b: repaired credentials proceed');
assertPreSend($gateway8->createCalls === 1, '8b: client called once after repair');
$bank8b = new PreSendBankSpy();
$secondLifecycle = preSendLifecycleHandle($secondSmart, $bank8b);
assertPreSend($secondLifecycle->isCreated(), '8b: lifecycle created after repair');
assertPreSend($bank8b->updates === [], '8b: no bank failure on success path');

fwrite(STDOUT, "OK (SmartUCF pre-send failure classification)\n");
