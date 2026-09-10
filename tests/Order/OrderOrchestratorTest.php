<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
define('_NEW_COOKIE_KEY_', 'test-key');
final class PhpEncryption
{
    public function __construct(string $key) {}
    public function encrypt(string $value): string
    {
        return base64_encode(strrev($value));
    }
    public function decrypt(string $value)
    {
        $decoded = base64_decode($value, true);
        return is_string($decoded) ? strrev($decoded) : false;
    }
}
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/Calculator/fixtures.php';

use PrestaShop\Module\Unipayment\Api\Exception\ConnectionException;
use PrestaShop\Module\Unipayment\Api\Exception\HttpException;
use PrestaShop\Module\Unipayment\Api\Exception\InvalidPayloadException;
use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\ProductContext;
use PrestaShop\Module\Unipayment\Cart\CartContext;
use PrestaShop\Module\Unipayment\Cart\CartLine;
use PrestaShop\Module\Unipayment\Cart\CartSchemeResolver;
use PrestaShop\Module\Unipayment\Checkout\ValidatedPaymentRequest;
use PrestaShop\Module\Unipayment\Order\ControlPanelOrderClientInterface;
use PrestaShop\Module\Unipayment\Order\ControlPanelOrderPayloadBuilder;
use PrestaShop\Module\Unipayment\Order\CreatedOrder;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotFactory;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotStoreInterface;
use PrestaShop\Module\Unipayment\Order\OrderAttemptStoreInterface;
use PrestaShop\Module\Unipayment\Order\BankStatus;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrationException;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrator;
use PrestaShop\Module\Unipayment\Order\PrestaShopOrderGatewayInterface;
use PrestaShop\Module\Unipayment\Order\SensitiveDataCipher;

function assertOrder(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}
final class MemoryAttempts implements OrderAttemptStoreInterface
{
    /** @var array<string, array<string, mixed>> */
    public $rows = [];

    public function reserve(int $shop, int $cart, string $fingerprint): array
    {
        $key = "$shop:$cart:$fingerprint";
        $created = !isset($this->rows[$key]);
        if ($created) {
            $this->rows[$key] = [
                'id_attempt' => count($this->rows) + 1,
                'id_shop' => $shop,
                'id_cart' => $cart,
                'cart_fingerprint' => $fingerprint,
                'state' => 'reserved',
                'id_order' => null,
                'order_reference' => null,
                'control_panel_order_id' => null,
                'cp_payload' => null,
            ];
        }

        return $this->rows[$key] + ['_reservation_created' => $created];
    }

    public function update(int $id, array $changes): array
    {
        foreach ($this->rows as $key => $row) {
            if ($row['id_attempt'] === $id) {
                $this->rows[$key] = array_replace($row, $changes);

                return $this->rows[$key];
            }
        }
        throw new RuntimeException('attempt not found');
    }

    public function attachOrderIfReserved(int $attemptId, int $idOrder, string $orderReference): array
    {
        foreach ($this->rows as $key => $row) {
            if ((int) $row['id_attempt'] !== $attemptId) {
                continue;
            }
            if ((string) $row['state'] === OrderOrchestrator::RESERVED && (int) ($row['id_order'] ?? 0) <= 0) {
                $this->rows[$key] = array_replace($row, [
                    'state' => OrderOrchestrator::PS_ORDER_CREATED,
                    'id_order' => $idOrder,
                    'order_reference' => substr($orderReference, 0, 13),
                ]);

                return $this->rows[$key];
            }
            if ((int) ($row['id_order'] ?? 0) === $idOrder) {
                return $this->rows[$key];
            }
            throw new RuntimeException('The financing attempt order could not be attached.');
        }
        throw new RuntimeException('attempt not found');
    }
}
final class MemorySnapshots implements FinancingSnapshotStoreInterface
{
    public $rows = [];
    public function save(int $id, array $snapshot): void
    {
        $this->rows[$id] = $snapshot;
    }
    public function findByAttempt(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }
    public function update(int $id, array $changes): void
    {
        $this->rows[$id] = array_replace($this->rows[$id], $changes);
    }
}
final class FakeOrders implements PrestaShopOrderGatewayInterface
{
    public int $created = 0;
    public int $validateOrderCalls = 0;
    public int $createCalls = 0;
    public int $loadCalls = 0;
    /** @var list<int> */
    public $failed = [];
    /** @var list<int> */
    public $awaiting = [];
    /** @var list<float> */
    public $amounts = [];
    /** When true, create() recovers existing cart order without validateOrder (getIdByCartId path). */
    public bool $orderAlreadyOnCart = false;
    /** @var CreatedOrder */
    public $order;

    public function __construct(CreatedOrder $order)
    {
        $this->order = $order;
    }

    public function create(ValidatedPaymentRequest $request, array $shop = []): CreatedOrder
    {
        ++$this->createCalls;
        if ($this->orderAlreadyOnCart) {
            return $this->load($this->order->idOrder);
        }
        ++$this->validateOrderCalls;
        ++$this->created;
        $this->amounts[] = $request->calculation->price;
        $this->orderAlreadyOnCart = true;

        return $this->order;
    }

    public function load(int $id): CreatedOrder
    {
        ++$this->loadCalls;

        return $this->order;
    }

    public function markFailed(int $id): void
    {
        $this->failed[] = $id;
    }

    public function markAwaiting(int $id): void
    {
        $this->awaiting[] = $id;
    }
}
final class FakeCp implements ControlPanelOrderClientInterface
{
    public $calls = [];
    public $queue = [];
    public function createOrder(array $payload): array
    {
        $this->calls[] = $payload;
        $next = array_shift($this->queue);
        if ($next instanceof Throwable) throw $next;
        return $next;
    }

    public function updateOrderStatus(string $orderId, string $status, string $statusId): array
    {
        $this->calls[] = ['orderId' => $orderId, 'status' => $status, 'statusId' => $statusId];

        return ['ok' => true];
    }
}
final class MemoryBankStatus implements \PrestaShop\Module\Unipayment\Order\BankStatusPersistencePort
{
    /** @var list<array{idShop: int, orderReference: string, statusId: string, statusLabel: string}> */
    public $updates = [];

    public function updateByOrderIdentifier(int $idShop, string $orderReference, string $statusId, string $statusLabel): ?array
    {
        $this->updates[] = [
            'idShop' => $idShop,
            'orderReference' => $orderReference,
            'statusId' => $statusId,
            'statusLabel' => $statusLabel,
        ];

        return ['order_id' => $orderReference, 'status_id' => $statusId];
    }
}

$calculator = new Calculator('2026-08-17');
$shop = calculatorFixture(['uni_eur' => 0]);
$cart = new CartContext([new CartLine(new ProductContext(42, [7], 1050), 3, 2, 1000)], 1050, ['carrier_id' => 2, 'shipping_total' => '50.00']);
$scheme = (new CartSchemeResolver($calculator))->resolve($shop, $cart)->standardSchemes[0];
$calculation = $calculator->calculateScheme($shop, 1050, $scheme, 100);
$request = new ValidatedPaymentRequest($calculation, ['first_name' => 'Ivan', 'last_name' => 'Ivanov', 'phone' => '+359888123', 'email' => 'ivan@example.com', 'egn' => '1990010199', 'phone2' => '+3592123'], [7], hash('sha256', 'cart'), [['id' => 7, 'name' => 'Terms', 'url' => 'https://example.com/terms', 'mandatory' => true]]);
$created = new CreatedOrder(55, 'ABCD12345', 1050, 'BGN', 1, ['first_name' => 'Ivan', 'last_name' => 'Ivanov', 'phone' => '+359888123', 'email' => 'ivan@example.com'], ['invoice' => ['address1' => 'Sofia 1'], 'delivery' => ['address1' => 'Sofia 2']], [['id_product' => 42, 'id_product_attribute' => 3, 'name' => 'Product_Name', 'quantity' => 2, 'total' => 1000]]);
$attempts = new MemoryAttempts();
$snapshots = new MemorySnapshots();
$orders = new FakeOrders($created);
$cp = new FakeCp();
$cp->queue[] = ['data' => ['id' => 901]];
$orchestrator = new OrderOrchestrator($attempts, $snapshots, $orders, $cp, new FinancingSnapshotFactory(new SensitiveDataCipher()), new ControlPanelOrderPayloadBuilder());
$result = $orchestrator->orchestrate(1, 9, $request, $shop);
assertOrder($result->state === OrderOrchestrator::CP_CREATED && $result->controlPanelOrderId === 901, 'first submit failed');
assertOrder($orders->created === 1 && count($cp->calls) === 1, 'first submit counts invalid');
assertOrder($orders->amounts === [1050.0], 'validateOrder amount is not the final tax-inclusive checkout total');
assertOrder($snapshots->rows[1]['control_panel_order_id'] === 901, 'CP ID not snapshotted');
assertOrder(strpos((string)$snapshots->rows[1]['sensitive_payload'], '1990010199') === false, 'EGN stored plaintext');
assertOrder(!isset($snapshots->rows[1]['customer_json']['egn']) && !isset($snapshots->rows[1]['customer_json']['phone2']), 'sensitive fields leaked into generic JSON');
assertOrder((new SensitiveDataCipher())->decrypt($snapshots->rows[1]['sensitive_payload'])['egn'] === '1990010199', 'encrypted EGN not recoverable');
$again = $orchestrator->orchestrate(1, 9, $request, array_replace($shop, ['uni_shema_current' => 24]));
assertOrder($again->idOrder === 55 && $orders->created === 1 && count($cp->calls) === 1, 'double submit created duplicates');
assertOrder($snapshots->rows[1]['months'] === $calculation->scheme->months && $snapshots->rows[1]['kop_code'] === $calculation->scheme->kopCode, 'configuration change altered immutable snapshot');
assertOrder(array_keys($cp->calls[0]) === ['order_id', 'name', 'phone', 'email', 'address', 'address2', 'price', 'vnoska', 'gpr', 'vnoski', 'parva', 'products_id', 'products_name', 'products_q', 'type_client', 'currency', 'version'], 'Process 1 CP create must not claim SmartUCF success');
assertOrder($cp->calls[0]['products_id'] === '3' && $cp->calls[0]['products_name'] === 'Product-Name' && $cp->calls[0]['products_q'] === '2', 'Woo product formatting differs');

assertOrder($orders->validateOrderCalls === 1, 'fresh flow must call validateOrder once');

// Crash window: reserved attempt, id_order NULL, PS order already exists on cart.
$crashAttempts = new MemoryAttempts();
$crashKey = '1:88:' . $request->cartFingerprint;
$crashAttempts->rows[$crashKey] = [
    'id_attempt' => 77,
    'id_shop' => 1,
    'id_cart' => 88,
    'cart_fingerprint' => $request->cartFingerprint,
    'state' => OrderOrchestrator::RESERVED,
    'id_order' => null,
    'order_reference' => null,
    'control_panel_order_id' => null,
    'cp_payload' => null,
];
$crashSnapshots = new MemorySnapshots();
$crashOrders = new FakeOrders($created);
$crashOrders->orderAlreadyOnCart = true;
$crashCp = new FakeCp();
$crashCp->queue[] = ['data' => ['id' => 911]];
$crashFlow = new OrderOrchestrator(
    $crashAttempts,
    $crashSnapshots,
    $crashOrders,
    $crashCp,
    new FinancingSnapshotFactory(new SensitiveDataCipher()),
    new ControlPanelOrderPayloadBuilder()
);
$crashResult = $crashFlow->orchestrate(1, 88, $request, $shop);
assertOrder($crashResult->idOrder === 55 && $crashResult->controlPanelOrderId === 911, 'crash recovery must complete CP create');
assertOrder($crashOrders->validateOrderCalls === 0, 'crash recovery must not call validateOrder');
assertOrder($crashOrders->createCalls === 1, 'crash recovery must enter gateway create for getIdByCartId path');
assertOrder((int) $crashAttempts->rows[$crashKey]['id_order'] === 55, 'crash recovery must attach existing id_order');
assertOrder((string) $crashAttempts->rows[$crashKey]['state'] === OrderOrchestrator::PS_ORDER_CREATED
    || (string) $crashAttempts->rows[$crashKey]['state'] === OrderOrchestrator::CP_CREATED, 'crash recovery must leave reserved');
assertOrder(isset($crashSnapshots->rows[77]), 'crash recovery must create snapshot');

// Second recovery retry: reuse attached order, no second validateOrder / CP.
$crashCp->queue[] = ['data' => ['id' => 999]];
$crashAgain = $crashFlow->orchestrate(1, 88, $request, $shop);
assertOrder($crashAgain->idOrder === 55 && $crashAgain->controlPanelOrderId === 911, 'second recovery must reuse CP success');
assertOrder($crashOrders->validateOrderCalls === 0 && $crashOrders->createCalls === 1, 'second recovery must not recreate PS order');
assertOrder(count($crashCp->calls) === 1, 'second recovery must not POST CP again');

// reserved + no PS order after stale lock takeover → create once.
$staleAttempts = new MemoryAttempts();
$staleKey = '1:89:' . $request->cartFingerprint;
$staleAttempts->rows[$staleKey] = [
    'id_attempt' => 78,
    'id_shop' => 1,
    'id_cart' => 89,
    'cart_fingerprint' => $request->cartFingerprint,
    'state' => OrderOrchestrator::RESERVED,
    'id_order' => null,
    'order_reference' => null,
    'control_panel_order_id' => null,
    'cp_payload' => null,
];
$staleOrders = new FakeOrders($created);
$staleCp = new FakeCp();
$staleCp->queue[] = ['data' => ['id' => 912]];
$staleFlow = new OrderOrchestrator(
    $staleAttempts,
    new MemorySnapshots(),
    $staleOrders,
    $staleCp,
    new FinancingSnapshotFactory(new SensitiveDataCipher()),
    new ControlPanelOrderPayloadBuilder()
);
$staleResult = $staleFlow->orchestrate(1, 89, $request, $shop);
assertOrder($staleResult->controlPanelOrderId === 912, 'stale reserved without PS order must create');
assertOrder($staleOrders->validateOrderCalls === 1, 'stale reserved without PS order must validateOrder once');
assertOrder((int) $staleAttempts->rows[$staleKey]['id_order'] === 55, 'stale reserved must attach new order');

// Retry after id_order already populated → load only.
$attachedAttempts = new MemoryAttempts();
$attachedKey = '1:90:' . $request->cartFingerprint;
$attachedAttempts->rows[$attachedKey] = [
    'id_attempt' => 79,
    'id_shop' => 1,
    'id_cart' => 90,
    'cart_fingerprint' => $request->cartFingerprint,
    'state' => OrderOrchestrator::PS_ORDER_CREATED,
    'id_order' => 55,
    'order_reference' => 'ABCD12345',
    'control_panel_order_id' => null,
    'cp_payload' => null,
];
$attachedOrders = new FakeOrders($created);
$attachedCp = new FakeCp();
$attachedCp->queue[] = ['data' => ['id' => 913]];
$attachedFlow = new OrderOrchestrator(
    $attachedAttempts,
    new MemorySnapshots(),
    $attachedOrders,
    $attachedCp,
    new FinancingSnapshotFactory(new SensitiveDataCipher()),
    new ControlPanelOrderPayloadBuilder()
);
$attachedResult = $attachedFlow->orchestrate(1, 90, $request, $shop);
assertOrder($attachedResult->controlPanelOrderId === 913, 'attached attempt must resume CP');
assertOrder($attachedOrders->createCalls === 0 && $attachedOrders->loadCalls >= 1, 'attached attempt must load, not create');
assertOrder($attachedOrders->validateOrderCalls === 0, 'attached attempt must not validateOrder');

// Live concurrency remains CheckoutSubmitLock (Aud-013), not attempt early-exit.
$validateCtrl = (string) file_get_contents(dirname(__DIR__, 2) . '/controllers/front/validatecheckout.php');
assertOrder(strpos($validateCtrl, 'CheckoutSubmitLock') !== false, 'validatecheckout must acquire checkout lock');
assertOrder(strpos($validateCtrl, '$lockToken = $lock->acquire($idShop, $idCart);') !== false, 'lock acquire before orchestrate');
assertOrder(
    (bool) preg_match('/\$lockToken\s*=\s*\$lock->acquire[\s\S]*OrderOrchestrator/s', $validateCtrl),
    'orchestrator runs only after lock ownership'
);

$a2 = new MemoryAttempts();
$s2 = new MemorySnapshots();
$o2 = new FakeOrders($created);
$c2 = new FakeCp();
$c2->queue[] = new ConnectionException('timeout');
$bank2 = new MemoryBankStatus();
$flow2 = new OrderOrchestrator($a2, $s2, $o2, $c2, new FinancingSnapshotFactory(new SensitiveDataCipher()), new ControlPanelOrderPayloadBuilder(), $bank2);
try {
    $flow2->orchestrate(1, 10, $request, $shop);
    assertOrder(false, 'timeout accepted');
} catch (OrderOrchestrationException $e) {
    assertOrder($e->isRetryable(), 'timeout not retryable');
    assertOrder($e->isPostOrder() && $e->idOrder() === 55, 'timeout must expose existing PS order');
    assertOrder($e->isOutcomeUnknown() && $e->state() === OrderOrchestrator::CP_OUTCOME_UNKNOWN, 'timeout must be outcome unknown');
}
assertOrder(($s2->rows[1]['lifecycle_status'] ?? '') === OrderOrchestrator::CP_OUTCOME_UNKNOWN, 'timeout must persist snapshot outcome unknown');
assertOrder($bank2->updates === [], 'timeout must NOT write bank_send_failed_cp');
assertOrder(count($c2->calls) === 1, 'timeout must perform exactly one createOrder');
$ambiguousAttempt = null;
foreach ($a2->rows as $row) {
    if ((int) ($row['id_attempt'] ?? 0) === 1) {
        $ambiguousAttempt = $row;
        break;
    }
}
assertOrder(is_array($ambiguousAttempt), 'timeout attempt row missing');
assertOrder(isset($ambiguousAttempt['cp_payload']) && is_string($ambiguousAttempt['cp_payload']) && $ambiguousAttempt['cp_payload'] !== '', 'timeout must preserve frozen CP payload');
try {
    $flow2->orchestrate(1, 10, $request, $shop);
    assertOrder(false, 'ambiguous replay must not auto-recover via blind create');
} catch (OrderOrchestrationException $e) {
    assertOrder($e->isOutcomeUnknown() && $e->state() === OrderOrchestrator::CP_OUTCOME_UNKNOWN, 'ambiguous replay must remain outcome unknown');
    assertOrder($e->isPostOrder() && $e->idOrder() === 55, 'ambiguous replay must stay post-order');
    assertOrder($e->isRetryable(), 'ambiguous replay remains operator-recoverable');
}
assertOrder(count($c2->calls) === 1, 'ambiguous replay must NOT issue a second POST /orders');
assertOrder($o2->created === 1, 'ambiguous replay must not create another PS order');
assertOrder($bank2->updates === [], 'ambiguous replay must NOT write bank_send_failed_cp');
assertOrder((int) ($s2->rows[1]['control_panel_order_id'] ?? 0) === 0, 'ambiguous replay must not fabricate CP id');
assertOrder(($s2->rows[1]['lifecycle_status'] ?? '') === OrderOrchestrator::CP_OUTCOME_UNKNOWN, 'ambiguous replay must preserve durable unknown state');
$ambiguousAttemptAfter = null;
foreach ($a2->rows as $row) {
    if ((int) ($row['id_attempt'] ?? 0) === 1) {
        $ambiguousAttemptAfter = $row;
        break;
    }
}
assertOrder(is_array($ambiguousAttemptAfter), 'ambiguous replay attempt row missing');
assertOrder((string) ($ambiguousAttemptAfter['state'] ?? '') === OrderOrchestrator::CP_OUTCOME_UNKNOWN, 'ambiguous replay must preserve attempt state');
assertOrder(json_encode(json_decode((string) $ambiguousAttemptAfter['cp_payload'], true)) === json_encode($c2->calls[0]), 'frozen payload must remain authoritative');

foreach ([
    [404, true, OrderOrchestrator::CP_OUTCOME_UNKNOWN, true],
    [409, true, OrderOrchestrator::CP_OUTCOME_UNKNOWN, true],
    [422, true, OrderOrchestrator::CP_OUTCOME_UNKNOWN, true],
    [500, true, OrderOrchestrator::CP_FAILED_RETRYABLE, true],
] as [$status, $retryable, $state, $outcomeUnknown]) {
    $a = new MemoryAttempts();
    $s = new MemorySnapshots();
    $o = new FakeOrders($created);
    $c = new FakeCp();
    $bank = new MemoryBankStatus();
    $c->queue[] = new HttpException($status, []);
    $flow = new OrderOrchestrator($a, $s, $o, $c, new FinancingSnapshotFactory(new SensitiveDataCipher()), new ControlPanelOrderPayloadBuilder(), $bank);
    try {
        $flow->orchestrate(2, $status, $request, $shop);
        assertOrder(false, "HTTP $status accepted");
    } catch (OrderOrchestrationException $e) {
        assertOrder($e->isRetryable() === $retryable, "HTTP $status classification differs");
        assertOrder($e->isPostOrder() && $e->idOrder() === 55, "HTTP $status must expose existing PS order");
        assertOrder($e->state() === $state, "HTTP $status attempt state differs");
        assertOrder($e->isOutcomeUnknown() === $outcomeUnknown, "HTTP $status outcome-unknown flag differs");
    }
    assertOrder($o->created === 1, "HTTP $status created duplicate");
    assertOrder($o->failed === [], "HTTP $status changed the native order state");
    assertOrder(($s->rows[1]['lifecycle_status'] ?? '') === $state, "HTTP $status snapshot lifecycle differs");
    assertOrder($bank->updates === [], "HTTP $status without machine code must NOT write bank_send_failed_cp");
    assertOrder((int) ($s->rows[1]['control_panel_order_id'] ?? 0) === 0, "HTTP $status must not fabricate a CP id");
}

foreach ([
    ['invalid_payload', 422],
    ['semantic_conflict', 409],
    ['unsupported_status', 422],
    ['shop_not_found', 404],
] as [$code, $status]) {
    $a = new MemoryAttempts();
    $s = new MemorySnapshots();
    $o = new FakeOrders($created);
    $c = new FakeCp();
    $bank = new MemoryBankStatus();
    $c->queue[] = new HttpException($status, ['error' => $code]);
    $flow = new OrderOrchestrator($a, $s, $o, $c, new FinancingSnapshotFactory(new SensitiveDataCipher()), new ControlPanelOrderPayloadBuilder(), $bank);
    try {
        $flow->orchestrate(3, $status + strlen($code), $request, $shop);
        assertOrder(false, "definitive $code accepted");
    } catch (OrderOrchestrationException $e) {
        assertOrder(!$e->isRetryable() && $e->state() === OrderOrchestrator::TERMINAL_FAILED, "definitive $code must be terminal");
        assertOrder(!$e->isOutcomeUnknown(), "definitive $code must not be outcome unknown");
        assertOrder($e->isPostOrder(), "definitive $code is post-order");
    }
    assertOrder(
        $bank->updates !== [] && $bank->updates[0]['statusId'] === BankStatus::SEND_FAILED_CP,
        "definitive $code must persist bank_send_failed_cp"
    );
}

$missingIdAttempts = new MemoryAttempts();
$missingIdSnapshots = new MemorySnapshots();
$missingIdOrders = new FakeOrders($created);
$missingIdCp = new FakeCp();
$missingIdBank = new MemoryBankStatus();
$missingIdCp->queue[] = ['data' => []];
$missingIdFlow = new OrderOrchestrator($missingIdAttempts, $missingIdSnapshots, $missingIdOrders, $missingIdCp, new FinancingSnapshotFactory(new SensitiveDataCipher()), new ControlPanelOrderPayloadBuilder(), $missingIdBank);
try {
    $missingIdFlow->orchestrate(4, 12, $request, $shop);
    assertOrder(false, 'missing CP id accepted');
} catch (OrderOrchestrationException $e) {
    assertOrder($e->isRetryable() && $e->state() === OrderOrchestrator::CP_OUTCOME_UNKNOWN, 'missing CP id must stay outcome unknown');
    assertOrder($e->isOutcomeUnknown(), 'missing CP id is ambiguous');
    assertOrder($e->isPostOrder(), 'missing CP id is post-order');
}
assertOrder($missingIdBank->updates === [], 'missing CP id must NOT write bank_send_failed_cp');
try {
    $missingIdFlow->orchestrate(4, 12, $request, $shop);
    assertOrder(false, 'missing CP id replay must not blind-resend create');
} catch (OrderOrchestrationException $e) {
    assertOrder($e->isOutcomeUnknown() && $e->state() === OrderOrchestrator::CP_OUTCOME_UNKNOWN, 'missing CP id replay stays unknown');
}
assertOrder(count($missingIdCp->calls) === 1, 'missing CP id replay createOrder count must remain 1');

$echoAttempts = new MemoryAttempts();
$echoSnapshots = new MemorySnapshots();
$echoOrders = new FakeOrders($created);
$echoCp = new FakeCp();
$echoBank = new MemoryBankStatus();
$echoCp->queue[] = new InvalidPayloadException('echo mismatch');
$echoFlow = new OrderOrchestrator($echoAttempts, $echoSnapshots, $echoOrders, $echoCp, new FinancingSnapshotFactory(new SensitiveDataCipher()), new ControlPanelOrderPayloadBuilder(), $echoBank);
try {
    $echoFlow->orchestrate(5, 15, $request, $shop);
    assertOrder(false, 'echo mismatch accepted');
} catch (OrderOrchestrationException $e) {
    assertOrder($e->isOutcomeUnknown() && $e->state() === OrderOrchestrator::CP_OUTCOME_UNKNOWN, 'echo mismatch is outcome unknown');
}
assertOrder($echoBank->updates === [], 'echo mismatch must NOT write bank_send_failed_cp');
try {
    $echoFlow->orchestrate(5, 15, $request, $shop);
    assertOrder(false, 'echo mismatch replay must not blind-resend');
} catch (OrderOrchestrationException $e) {
    assertOrder($e->isOutcomeUnknown(), 'echo mismatch replay stays unknown');
}
assertOrder(count($echoCp->calls) === 1, 'echo mismatch replay createOrder count must remain 1');

$badOrder = new CreatedOrder(56, 'BADTOTAL', 1049, 'BGN', 1, $created->customer, $created->addresses, $created->lines);
$badOrders = new FakeOrders($badOrder);
$badCp = new FakeCp();
$badFlow = new OrderOrchestrator(new MemoryAttempts(), new MemorySnapshots(), $badOrders, $badCp, new FinancingSnapshotFactory(new SensitiveDataCipher()), new ControlPanelOrderPayloadBuilder());
try {
    $badFlow->orchestrate(3, 11, $request, $shop);
    assertOrder(false, 'total mismatch accepted');
} catch (OrderOrchestrationException $e) {
}
assertOrder($badCp->calls === [] && $badOrders->failed === [], 'total mismatch reached CP or changed native order state');
fwrite(STDOUT, "OK (Phase 10 order orchestration)\n");
