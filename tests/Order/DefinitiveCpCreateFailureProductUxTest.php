<?php

declare(strict_types=1);

/**
 * Definitive CP create failure: Product Thank You redirect + standard emails once.
 * Ambiguous CP outcome remains non-terminal (no auto Thank You / no definitive email flush).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Api\Exception\ConnectionException;
use PrestaShop\Module\Unipayment\Api\Exception\HttpException;
use PrestaShop\Module\Unipayment\Calculator\AvailableScheme;
use PrestaShop\Module\Unipayment\Calculator\CalculationResult;
use PrestaShop\Module\Unipayment\Calculator\FirstInstallmentState;
use PrestaShop\Module\Unipayment\Checkout\ValidatedPaymentRequest;
use PrestaShop\Module\Unipayment\Order\BankStatus;
use PrestaShop\Module\Unipayment\Order\BankStatusPersistencePort;
use PrestaShop\Module\Unipayment\Order\ControlPanelOrderClientInterface;
use PrestaShop\Module\Unipayment\Order\ControlPanelOrderPayloadBuilder;
use PrestaShop\Module\Unipayment\Order\CreatedOrder;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotFactory;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotStoreInterface;
use PrestaShop\Module\Unipayment\Order\LeasingMailDispatchPort;
use PrestaShop\Module\Unipayment\Order\OrderAttemptStoreInterface;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrationException;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrator;
use PrestaShop\Module\Unipayment\Order\PostOrderPopupFailureResponse;
use PrestaShop\Module\Unipayment\Order\PrestaShopOrderGatewayInterface;
use PrestaShop\Module\Unipayment\Order\SensitiveDataCipher;

if (!defined('_NEW_COOKIE_KEY_')) {
    define('_NEW_COOKIE_KEY_', 'cp-fail-email-test-key');
}

if (!class_exists('PhpEncryption', false)) {
    final class PhpEncryption
    {
        public function __construct(string $key)
        {
            unset($key);
        }

        public function encrypt(string $value): string
        {
            return base64_encode($value);
        }

        public function decrypt(string $value): string
        {
            return (string) base64_decode($value, true);
        }
    }
}

if (!class_exists('PrestaShopLogger', false)) {
    class PrestaShopLogger
    {
        public static function addLog(string $message, int $severity = 1): void
        {
            unset($message, $severity);
        }
    }
}

function assertCpFailUx(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class CpFailUxAttempts implements OrderAttemptStoreInterface
{
    /** @var array<int, array<string, mixed>> */
    public $rows = [];

    public function reserve(int $idShop, int $idCart, string $fingerprint): array
    {
        $id = 1;
        if (!isset($this->rows[$id])) {
            $this->rows[$id] = [
                'id_attempt' => $id,
                'id_shop' => $idShop,
                'id_cart' => $idCart,
                'cart_fingerprint' => $fingerprint,
                'state' => OrderOrchestrator::RESERVED,
                'id_order' => 0,
                'order_reference' => '',
                'control_panel_order_id' => 0,
                '_reservation_created' => true,
            ];
        }

        return $this->rows[$id];
    }

    public function update(int $attemptId, array $changes): array
    {
        $this->rows[$attemptId] = array_merge($this->rows[$attemptId] ?? ['id_attempt' => $attemptId], $changes);

        return $this->rows[$attemptId];
    }

    public function attachOrderIfReserved(int $attemptId, int $idOrder, string $orderReference): array
    {
        $row = $this->rows[$attemptId] ?? ['id_attempt' => $attemptId];
        if ((string) ($row['state'] ?? '') === OrderOrchestrator::RESERVED && (int) ($row['id_order'] ?? 0) <= 0) {
            $this->rows[$attemptId] = array_merge($row, [
                'state' => OrderOrchestrator::PS_ORDER_CREATED,
                'id_order' => $idOrder,
                'order_reference' => substr($orderReference, 0, 13),
            ]);

            return $this->rows[$attemptId];
        }
        if ((int) ($row['id_order'] ?? 0) === $idOrder) {
            return $row;
        }

        throw new RuntimeException('Attempt cannot attach order.');
    }
}

final class CpFailUxSnapshots implements FinancingSnapshotStoreInterface
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
}

final class CpFailUxOrders implements PrestaShopOrderGatewayInterface
{
    /** @var CreatedOrder */
    private $order;

    /** @var int */
    public $created = 0;

    public function __construct(CreatedOrder $order)
    {
        $this->order = $order;
    }

    public function create(ValidatedPaymentRequest $request, array $shop = []): CreatedOrder
    {
        unset($request, $shop);
        ++$this->created;

        return $this->order;
    }

    public function load(int $idOrder): CreatedOrder
    {
        unset($idOrder);

        return $this->order;
    }

    public function markFailed(int $idOrder): void
    {
        unset($idOrder);
    }

    public function markAwaiting(int $idOrder): void
    {
        unset($idOrder);
    }
}

final class CpFailUxCp implements ControlPanelOrderClientInterface
{
    /** @var list<mixed> */
    public $queue = [];

    /** @var list<array<string, mixed>> */
    public $calls = [];

    /** @var list<array<string, mixed>> */
    public $statusPatches = [];

    public function createOrder(array $payload): array
    {
        $this->calls[] = $payload;
        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        if (!is_array($next)) {
            throw new RuntimeException('CP queue empty');
        }

        return $next;
    }

    public function updateOrderStatus(string $orderId, string $status, string $statusId): array
    {
        $this->statusPatches[] = compact('orderId', 'status', 'statusId');

        return [];
    }
}

final class CpFailUxBank implements BankStatusPersistencePort
{
    /** @var list<array{statusId: string, statusLabel: string}> */
    public $updates = [];

    public function updateByOrderIdentifier(int $idShop, string $orderReference, string $statusId, string $statusLabel): ?array
    {
        unset($idShop, $orderReference);
        $this->updates[] = ['statusId' => $statusId, 'statusLabel' => $statusLabel];

        return ['ps_order_id' => 1];
    }
}

final class CpFailUxMailSpy implements LeasingMailDispatchPort
{
    /** @var list<array{status_id: string, status_label: string}> */
    public $sent = [];

    public function send(array $snapshot, int $attemptId, array $shop, array $status): void
    {
        unset($snapshot, $attemptId, $shop);
        $this->sent[] = $status;
    }
}

$root = dirname(__DIR__, 2);
$product = (string) file_get_contents($root . '/controllers/front/productpopup.php');
$cart = (string) file_get_contents($root . '/controllers/front/cartpopup.php');
$orchestratorSrc = (string) file_get_contents($root . '/src/Order/OrderOrchestrator.php');
$cpTpl = (string) file_get_contents($root . '/views/templates/hook/order_confirmation_cp_failure.tpl');
$responseSrc = (string) file_get_contents($root . '/src/Order/PostOrderPopupFailureResponse.php');

$created = new CreatedOrder(
    88,
    'CPFAILREF01',
    1000.0,
    'BGN',
    1,
    ['email' => 'c@example.com'],
    [],
    [['id_product' => 1, 'name' => 'P', 'quantity' => 1, 'total' => 1000]]
);
$request = new ValidatedPaymentRequest(
    new CalculationResult(
        new AvailableScheme('standard', 'KOP1', 12, 0, null, []),
        1000.0,
        new FirstInstallmentState(0.0, false, true),
        1000.0,
        90.0,
        1080.0,
        5.0,
        6.0
    ),
    ['email' => 'c@example.com'],
    [],
    'fp-cp-fail'
);
$shop = ['uni_proces' => 0];

// --- A. Product presentation: definitive CP failure → Thank You redirect ---
$thankYou = 'https://shop.example/index.php?controller=order-confirmation&id_order=88&key=sec';
$failedEx = new OrderOrchestrationException(
    'The Control Panel rejected the financing order.',
    false,
    null,
    88,
    1,
    OrderOrchestrator::TERMINAL_FAILED,
    false,
    'CPFAILREF01'
);
$payload = PostOrderPopupFailureResponse::fromException($failedEx, $thankYou);
assertCpFailUx(($payload['redirect_url'] ?? '') === $thankYou, 'A: definitive CP failure includes Thank You redirect_url');
assertCpFailUx(!isset($payload['cp_error']) && !isset($payload['smartucf_error']), 'A: no popup-only terminal cp_error');
assertCpFailUx(($payload['success'] ?? false) === true && ($payload['step'] ?? '') === 'order_created', 'A: success order_created');
assertCpFailUx((int) ($payload['order']['control_panel_order_id'] ?? -1) === 0, 'A: CP id remains 0');
assertCpFailUx(
    strpos($product, 'buildThankYouUrl') !== false
        && strpos($product, 'PostOrderPopupFailureResponse::fromException') !== false,
    'A: Product wires Thank You URL into shared CP failure response'
);
assertCpFailUx(
    strpos($cart, 'OrderConfirmationUrlBuilder') !== false
        && strpos($cart, 'PostOrderPopupFailureResponse::fromException') !== false,
    'A: Cart uses shared Thank You URL for post-order CP failure'
);

// --- B. Ambiguous CP outcome stays popup (no Thank You remap) ---
$unknownEx = new OrderOrchestrationException(
    'The Control Panel result is unknown and can be retried safely.',
    true,
    null,
    89,
    2,
    OrderOrchestrator::CP_OUTCOME_UNKNOWN,
    true,
    'CPUNKNREF01'
);
$unknownPayload = PostOrderPopupFailureResponse::fromException($unknownEx, $thankYou);
assertCpFailUx(($unknownPayload['step'] ?? '') === 'outcome_unknown', 'B: ambiguous stays outcome_unknown');
assertCpFailUx(isset($unknownPayload['cp_error']), 'B: ambiguous keeps customer cp_error');
assertCpFailUx(!isset($unknownPayload['redirect_url']), 'B: ambiguous must not become Thank You redirect');
assertCpFailUx(
    strpos($unknownPayload['cp_error'], 'потвърждението за регистрацията на финансирането не беше получено') !== false,
    'B: ambiguous wording'
);

// --- C. Orchestrator: definitive failure emails once + bank status + no SmartUCF/CP PATCH ---
$attempts = new CpFailUxAttempts();
$snapshots = new CpFailUxSnapshots();
$orders = new CpFailUxOrders($created);
$cp = new CpFailUxCp();
$cp->queue[] = new HttpException(422, ['error' => 'invalid_payload']);
$bank = new CpFailUxBank();
$mail = new CpFailUxMailSpy();
$flow = new OrderOrchestrator(
    $attempts,
    $snapshots,
    $orders,
    $cp,
    new FinancingSnapshotFactory(new SensitiveDataCipher()),
    new ControlPanelOrderPayloadBuilder(),
    $bank,
    $mail
);
try {
    $flow->orchestrate(1, 10, $request, $shop, 'product_popup');
    assertCpFailUx(false, 'C: definitive CP rejection must throw');
} catch (OrderOrchestrationException $exception) {
    assertCpFailUx($exception->state() === OrderOrchestrator::TERMINAL_FAILED, 'C: terminal_failed');
    assertCpFailUx($exception->isPostOrder(), 'C: post-order');
}
assertCpFailUx($orders->created === 1, 'C: Shop order created once');
assertCpFailUx(count($cp->calls) === 1, 'C: one CP create attempt');
assertCpFailUx($cp->statusPatches === [], 'C: no CP status PATCH without CP order');
assertCpFailUx(
    $bank->updates !== [] && $bank->updates[0]['statusId'] === BankStatus::SEND_FAILED_CP,
    'C: bank_send_failed_cp persisted'
);
assertCpFailUx(
    $bank->updates[0]['statusLabel'] === BankStatus::LABEL_SEND_FAILED_CP,
    'C: label Неуспешно изпратен Банка - КП'
);
assertCpFailUx(count($mail->sent) === 1, 'C: standard email finalization exactly once');
assertCpFailUx(
    ($mail->sent[0]['status_label'] ?? '') === BankStatus::LABEL_SEND_FAILED_CP,
    'C: email bank status Неуспешно изпратен Банка - КП'
);
assertCpFailUx(
    strpos($orchestratorSrc, 'finalizeDefinitiveControlPanelFailureEmails') !== false,
    'C: shared orchestrator email finalization path'
);
assertCpFailUx(
    strpos($orchestratorSrc, 'SmartUcfSessionCoordinator') === false
        && strpos($orchestratorSrc, 'createSession') === false,
    'C: orchestrator never starts SmartUCF'
);

// --- D. Ambiguous connection failure: discard emails, not definitive flush ---
$attemptsU = new CpFailUxAttempts();
$snapshotsU = new CpFailUxSnapshots();
$ordersU = new CpFailUxOrders($created);
$cpU = new CpFailUxCp();
$cpU->queue[] = new ConnectionException('timeout');
$bankU = new CpFailUxBank();
$mailU = new CpFailUxMailSpy();
$flowU = new OrderOrchestrator(
    $attemptsU,
    $snapshotsU,
    $ordersU,
    $cpU,
    new FinancingSnapshotFactory(new SensitiveDataCipher()),
    new ControlPanelOrderPayloadBuilder(),
    $bankU,
    $mailU
);
try {
    $flowU->orchestrate(1, 11, $request, $shop, 'product_popup');
    assertCpFailUx(false, 'D: ambiguous timeout must throw');
} catch (OrderOrchestrationException $exception) {
    assertCpFailUx($exception->isOutcomeUnknown(), 'D: outcome unknown');
}
assertCpFailUx($mailU->sent === [], 'D: ambiguous must not finalize definitive failure emails');
assertCpFailUx(
    ($snapshotsU->rows[1]['lifecycle_status'] ?? '') === OrderOrchestrator::CP_OUTCOME_UNKNOWN,
    'D: lifecycle stays cp_outcome_unknown'
);
assertCpFailUx(
    $bankU->updates === [],
    'D: ambiguous must not claim definitive bank_send_failed_cp'
);

// --- E. Thank You customer message (confirmation template) ---
assertCpFailUx(
    strpos($cpTpl, 'не беше регистрирана успешно в системата на УниКредит') !== false,
    'E: Thank You CP failure message present'
);
assertCpFailUx(
    strpos($cpTpl, 'Не изпращайте поръчката повторно') !== false,
    'E: no-resubmit wording'
);
assertCpFailUx(
    strpos($cpTpl, 'При необходимост търговецът ще се свърже с Вас') !== false,
    'E: merchant contact wording'
);
assertCpFailUx(
    stripos($cpTpl, 'HTTP') === false
        && stripos($cpTpl, 'Exception') === false
        && stripos($cpTpl, 'transport') === false
        && stripos($cpTpl, 'correlation') === false
        && stripos($cpTpl, 'subsystem') === false
        && stripos($cpTpl, 'lifecycle') === false,
    'E: no internal diagnostics on Thank You'
);
assertCpFailUx(
    strpos($responseSrc, 'redirect_url') !== false,
    'E: shared response supports Thank You redirect_url'
);

fwrite(STDOUT, "OK (definitive CP create failure Product UX + emails)\n");
