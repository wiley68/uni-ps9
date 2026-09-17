<?php

declare(strict_types=1);

/**
 * Checkout definitive CP create failure must persist bank_send_failed_cp for
 * Process 1 and Process 2 (no generic Неуспешно изпратен Банка).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

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
use PrestaShop\Module\Unipayment\Order\LeasingOrderEmailPresenter;
use PrestaShop\Module\Unipayment\Order\OrderAttemptStoreInterface;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrationException;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrator;
use PrestaShop\Module\Unipayment\Order\PrestaShopOrderGatewayInterface;
use PrestaShop\Module\Unipayment\Order\SensitiveDataCipher;

if (!defined('_NEW_COOKIE_KEY_')) {
    define('_NEW_COOKIE_KEY_', 'checkout-cp-fail-status-key');
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

function assertCheckoutCpStatus(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class CheckoutCpStatusAttempts implements OrderAttemptStoreInterface
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

final class CheckoutCpStatusSnapshots implements FinancingSnapshotStoreInterface
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

final class CheckoutCpStatusOrders implements PrestaShopOrderGatewayInterface
{
    /** @var CreatedOrder */
    private $order;

    public function __construct(CreatedOrder $order)
    {
        $this->order = $order;
    }

    public function create(ValidatedPaymentRequest $request, array $shop = []): CreatedOrder
    {
        unset($request, $shop);

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

final class CheckoutCpStatusCp implements ControlPanelOrderClientInterface
{
    public function createOrder(array $payload): array
    {
        unset($payload);
        throw new HttpException(422, ['error' => 'invalid_payload']);
    }

    public function updateOrderStatus(string $orderId, string $status, string $statusId): array
    {
        unset($orderId, $status, $statusId);

        return [];
    }
}

final class CheckoutCpStatusBank implements BankStatusPersistencePort
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

final class CheckoutCpStatusMail implements LeasingMailDispatchPort
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
$orchestratorSrc = (string) file_get_contents($root . '/src/Order/OrderOrchestrator.php');
$bankStatusSrc = (string) file_get_contents($root . '/src/Order/BankStatus.php');
$checkout = (string) file_get_contents($root . '/controllers/front/validatecheckout.php');

$created = new CreatedOrder(
    201,
    'CHKCPFAIL01',
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
    'fp-checkout-cp'
);

// Checkout uses shared OrderOrchestrator (no checkout-only status mapper).
assertCheckoutCpStatus(
    strpos($checkout, 'OrderOrchestrator') !== false,
    'Checkout uses shared OrderOrchestrator'
);
assertCheckoutCpStatus(
    strpos($orchestratorSrc, 'BankStatus::controlPanelFailure') !== false,
    'Orchestrator persists CP failure via BankStatus::controlPanelFailure'
);
assertCheckoutCpStatus(
    !preg_match(
        '/controlPanelFailure\([\s\S]*?SEND_FAILED[^_]/s',
        $bankStatusSrc
    ),
    'controlPanelFailure must not map Process 2 to generic SEND_FAILED'
);

foreach (
    [
        'Process 1 checkout' => ['uni_proces' => 0],
        'Process 2 checkout' => ['uni_proces' => 1],
    ] as $label => $shop
) {
    $bank = new CheckoutCpStatusBank();
    $mail = new CheckoutCpStatusMail();
    $flow = new OrderOrchestrator(
        new CheckoutCpStatusAttempts(),
        new CheckoutCpStatusSnapshots(),
        new CheckoutCpStatusOrders($created),
        new CheckoutCpStatusCp(),
        new FinancingSnapshotFactory(new SensitiveDataCipher()),
        new ControlPanelOrderPayloadBuilder(),
        $bank,
        $mail
    );
    try {
        $flow->orchestrate(1, 50, $request, $shop, 'checkout');
        assertCheckoutCpStatus(false, "{$label}: definitive CP failure must throw");
    } catch (OrderOrchestrationException $exception) {
        assertCheckoutCpStatus($exception->isPostOrder(), "{$label}: post-order");
        assertCheckoutCpStatus(
            $exception->state() === OrderOrchestrator::TERMINAL_FAILED,
            "{$label}: terminal_failed"
        );
    }

    assertCheckoutCpStatus($bank->updates !== [], "{$label}: bank status persisted");
    assertCheckoutCpStatus(
        $bank->updates[0]['statusId'] === BankStatus::SEND_FAILED_CP,
        "{$label}: status_id = bank_send_failed_cp"
    );
    assertCheckoutCpStatus(
        $bank->updates[0]['statusLabel'] === BankStatus::LABEL_SEND_FAILED_CP,
        "{$label}: public label = Неуспешно изпратен Банка - КП"
    );
    assertCheckoutCpStatus(
        $bank->updates[0]['statusLabel'] !== BankStatus::LABEL_SEND_FAILED,
        "{$label}: generic Неуспешно изпратен Банка must not be public status"
    );
    assertCheckoutCpStatus(count($mail->sent) === 1, "{$label}: standard emails once");
    assertCheckoutCpStatus(
        ($mail->sent[0]['status_label'] ?? '') === BankStatus::LABEL_SEND_FAILED_CP,
        "{$label}: email bank status Неуспешно изпратен Банка - КП"
    );
    assertCheckoutCpStatus(
        ($mail->sent[0]['status_label'] ?? '') !== BankStatus::LABEL_SEND_FAILED,
        "{$label}: email must not use generic Неуспешно изпратен Банка"
    );
}

$canonical = [
    BankStatus::LABEL_SEND_FAILED_CP,
    BankStatus::LABEL_SEND_FAILED_SMARTUCF,
    BankStatus::LABEL_SENT_PROCESS1,
    BankStatus::LABEL_SENT_PROCESS2,
];
foreach ([false, true] as $process2) {
    $status = BankStatus::controlPanelFailure($process2);
    assertCheckoutCpStatus(
        in_array($status['status_label'], $canonical, true),
        'CP failure label is in frozen public vocabulary'
    );
}

// Panel: Process 2 shop + persisted CP failure must not show Process 2 success.
$panelPresenter = new LeasingOrderEmailPresenter(new SensitiveDataCipher());
$panelRows = $panelPresenter->rowsFromSnapshot(
    [
        'months' => 12,
        'kop_code' => 'KOP1',
        'first_installment' => 0,
        'financed_amount' => 1000,
        'monthly_installment' => 90,
        'total_payable' => 1080,
        'glp' => 5,
        'gpr' => 6,
        'status_label' => '',
    ],
    ['uni_proces' => 1]
);
$panelRows = $panelPresenter->applyBankStatusLabel($panelRows, BankStatus::LABEL_SEND_FAILED_CP);
assertCheckoutCpStatus(
    ($panelRows['Статус към банката'] ?? '') === BankStatus::LABEL_SEND_FAILED_CP,
    'Panel shows Неуспешно изпратен Банка - КП after bank status apply'
);
assertCheckoutCpStatus(
    strpos((string) ($panelRows['Статус към банката'] ?? ''), 'Процес 2') === false,
    'Panel must not keep Изпратен Банка - Процес 2 after CP failure status apply'
);

fwrite(STDOUT, "OK (Checkout definitive CP failure bank status)\n");
