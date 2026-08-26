<?php

declare(strict_types=1);

/**
 * AUD-019 — Post-order exceptions must never become fresh/pre-order submissions.
 */

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

use PrestaShop\Module\Unipayment\Api\Exception\InvalidPayloadException;
use PrestaShop\Module\Unipayment\Api\Exception\MalformedJsonException;
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
use PrestaShop\Module\Unipayment\Order\OrderOrchestrationException;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrator;
use PrestaShop\Module\Unipayment\Order\PostOrderPopupFailureResponse;
use PrestaShop\Module\Unipayment\Order\PrestaShopOrderGatewayInterface;
use PrestaShop\Module\Unipayment\Order\SensitiveDataCipher;

function assertAud019(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function aud019AssertOrderAwareResponse(array $response, string $label): void
{
    $encoded = json_encode($response, JSON_UNESCAPED_UNICODE);
    assertAud019(is_string($encoded), "{$label}: response encodes");
    assertAud019(!empty($response['final']), "{$label}: final=true");
    assertAud019(!empty($response['order']['id_order']), "{$label}: id_order present");
    assertAud019(
        strpos($encoded, 'start again') === false
            && strpos($encoded, 'Please start again') === false
            && strpos($encoded, 'опитайте отново') === false
            && strpos($encoded, 'Изборът на финансиране не може да бъде валидиран') === false,
        "{$label}: must not invite fresh submission"
    );
    assertAud019(
        strpos($encoded, 'Не изпращайте поръчката повторно') !== false
            || strpos($encoded, 'order_created') !== false
            || strpos($encoded, 'outcome_unknown') !== false,
        "{$label}: must be order-aware"
    );
}

final class Aud019Attempts implements OrderAttemptStoreInterface
{
    /** @var array<string, array<string, mixed>> */
    public $rows = [];
    public bool $failAttachOnce = false;
    public int $attachCalls = 0;

    public function reserve(int $shop, int $cart, string $fingerprint): array
    {
        $key = "$shop:$cart:$fingerprint";
        if (!isset($this->rows[$key])) {
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

        return $this->rows[$key] + ['_reservation_created' => true];
    }

    public function update(int $id, array $changes): array
    {
        foreach ($this->rows as $key => $row) {
            if ((int) $row['id_attempt'] === $id) {
                $this->rows[$key] = array_replace($row, $changes);

                return $this->rows[$key];
            }
        }
        throw new RuntimeException('attempt not found');
    }

    public function attachOrderIfReserved(int $attemptId, int $idOrder, string $orderReference): array
    {
        ++$this->attachCalls;
        if ($this->failAttachOnce) {
            $this->failAttachOnce = false;
            throw new RuntimeException('attach failed');
        }
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

final class Aud019Snapshots implements FinancingSnapshotStoreInterface
{
    public $rows = [];
    public bool $failSaveOnce = false;
    public int $saveCalls = 0;

    public function save(int $id, array $snapshot): void
    {
        ++$this->saveCalls;
        if ($this->failSaveOnce) {
            $this->failSaveOnce = false;
            throw new RuntimeException('snapshot save failed');
        }
        $this->rows[$id] = $snapshot;
    }

    public function findByAttempt(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function update(int $id, array $changes): void
    {
        $this->rows[$id] = array_replace($this->rows[$id] ?? [], $changes);
    }
}

final class Aud019Orders implements PrestaShopOrderGatewayInterface
{
    public int $validateOrderCalls = 0;
    public int $createCalls = 0;
    public bool $orderAlreadyOnCart = false;
    public bool $failCreatePreOrder = false;
    /** @var CreatedOrder */
    public $order;

    public function __construct(CreatedOrder $order)
    {
        $this->order = $order;
    }

    public function create(ValidatedPaymentRequest $request, array $shop = []): CreatedOrder
    {
        ++$this->createCalls;
        if ($this->failCreatePreOrder) {
            throw new RuntimeException('pre-order create failed');
        }
        if ($this->orderAlreadyOnCart) {
            return $this->load($this->order->idOrder);
        }
        ++$this->validateOrderCalls;
        $this->orderAlreadyOnCart = true;

        return $this->order;
    }

    public function load(int $id): CreatedOrder
    {
        return $this->order;
    }

    public function markFailed(int $id): void {}

    public function markAwaiting(int $id): void {}
}

final class Aud019Cp implements ControlPanelOrderClientInterface
{
    public $calls = [];
    public $queue = [];

    public function createOrder(array $payload): array
    {
        $this->calls[] = $payload;
        $next = array_shift($this->queue);
        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }

    public function updateOrderStatus(string $orderId, string $status, ?string $statusId = null): array
    {
        return ['ok' => true];
    }
}

$calculator = new Calculator('2026-08-17');
$shop = calculatorFixture(['uni_eur' => 0]);
$cart = new CartContext([new CartLine(new ProductContext(42, [7], 1050), 3, 2, 1000)], 1050, ['carrier_id' => 2, 'shipping_total' => '50.00']);
$scheme = (new CartSchemeResolver($calculator))->resolve($shop, $cart)->standardSchemes[0];
$calculation = $calculator->calculateScheme($shop, 1050, $scheme, 100);
$request = new ValidatedPaymentRequest(
    $calculation,
    ['first_name' => 'Ivan', 'last_name' => 'Ivanov', 'phone' => '+359888123', 'email' => 'ivan@example.com', 'egn' => '1990010199', 'phone2' => '+3592123'],
    [7],
    hash('sha256', 'aud019-cart'),
    [['id' => 7, 'name' => 'Terms', 'url' => 'https://example.com/terms', 'mandatory' => true]]
);
$created = new CreatedOrder(
    55,
    'AUD019ORD01',
    1050,
    'BGN',
    1,
    ['first_name' => 'Ivan', 'last_name' => 'Ivanov', 'phone' => '+359888123', 'email' => 'ivan@example.com'],
    ['invoice' => ['address1' => 'Sofia 1'], 'delivery' => ['address1' => 'Sofia 2']],
    [['id_product' => 42, 'id_product_attribute' => 3, 'name' => 'Product_Name', 'quantity' => 2, 'total' => 1000]]
);

$root = dirname(__DIR__, 2);
$orchestratorSrc = (string) file_get_contents($root . '/src/Order/OrderOrchestrator.php');
$productPopup = (string) file_get_contents($root . '/controllers/front/productpopup.php');
$cartPopup = (string) file_get_contents($root . '/controllers/front/cartpopup.php');
$checkout = (string) file_get_contents($root . '/controllers/front/validatecheckout.php');

assertAud019(strpos($orchestratorSrc, 'ControlPanelException') !== false, 'orchestrator normalizes ControlPanelException');
assertAud019(strpos($orchestratorSrc, 'attachNativeOrder') !== false, 'orchestrator wraps attach failures');
assertAud019(strpos($orchestratorSrc, 'persistSnapshot') !== false, 'orchestrator wraps snapshot failures');
assertAud019(strpos($productPopup, 'PopupSubmissionPostOrderBinder') !== false, 'product uses post-order binder');
assertAud019(strpos($cartPopup, 'PopupSubmissionPostOrderBinder') !== false, 'cart uses post-order binder');
assertAud019(strpos($checkout, 'Order::getIdByCartId') !== false, 'checkout recovers native order on Throwable');

// A. validateOrder succeeds → attach throws → retry recovers same order, zero second validateOrder
$aAttempts = new Aud019Attempts();
$aAttempts->failAttachOnce = true;
$aSnapshots = new Aud019Snapshots();
$aOrders = new Aud019Orders($created);
$aCp = new Aud019Cp();
$aCp->queue[] = ['data' => ['id' => 901]];
$aFlow = new OrderOrchestrator(
    $aAttempts,
    $aSnapshots,
    $aOrders,
    $aCp,
    new FinancingSnapshotFactory(new SensitiveDataCipher()),
    new ControlPanelOrderPayloadBuilder()
);
try {
    $aFlow->orchestrate(1, 101, $request, $shop);
    assertAud019(false, 'A: attach failure must throw');
} catch (OrderOrchestrationException $exception) {
    assertAud019($exception->isPostOrder() && $exception->idOrder() === 55, 'A: attach failure is post-order');
    assertAud019($exception->isRetryable(), 'A: attach failure retryable');
    aud019AssertOrderAwareResponse(PostOrderPopupFailureResponse::fromException($exception), 'A response');
}
assertAud019($aOrders->validateOrderCalls === 1, 'A: validateOrder once before attach fail');
$aCp->queue[] = ['data' => ['id' => 901]];
$aRetry = $aFlow->orchestrate(1, 101, $request, $shop);
assertAud019($aRetry->idOrder === 55 && $aRetry->controlPanelOrderId === 901, 'A: retry completes same order');
assertAud019($aOrders->validateOrderCalls === 1, 'A: retry must not call validateOrder again');
assertAud019($aAttempts->attachCalls === 2, 'A: retry re-attempts attach');

// B. snapshot save throws → order-aware → retry resumes
$bAttempts = new Aud019Attempts();
$bSnapshots = new Aud019Snapshots();
$bSnapshots->failSaveOnce = true;
$bOrders = new Aud019Orders($created);
$bCp = new Aud019Cp();
$bFlow = new OrderOrchestrator(
    $bAttempts,
    $bSnapshots,
    $bOrders,
    $bCp,
    new FinancingSnapshotFactory(new SensitiveDataCipher()),
    new ControlPanelOrderPayloadBuilder()
);
try {
    $bFlow->orchestrate(1, 102, $request, $shop);
    assertAud019(false, 'B: snapshot failure must throw');
} catch (OrderOrchestrationException $exception) {
    assertAud019($exception->isPostOrder() && $exception->idOrder() === 55, 'B: snapshot failure is post-order');
    aud019AssertOrderAwareResponse(PostOrderPopupFailureResponse::fromException($exception), 'B response');
}
$bCp->queue[] = ['data' => ['id' => 902]];
$bRetry = $bFlow->orchestrate(1, 102, $request, $shop);
assertAud019($bRetry->idOrder === 55 && $bRetry->controlPanelOrderId === 902, 'B: retry resumes same order');
assertAud019($bOrders->validateOrderCalls === 1, 'B: no second validateOrder');
assertAud019(isset($bSnapshots->rows[$bRetry->attemptId]), 'B: snapshot saved on retry');

// C. CP InvalidPayloadException after PS order
$cAttempts = new Aud019Attempts();
$cSnapshots = new Aud019Snapshots();
$cOrders = new Aud019Orders($created);
$cCp = new Aud019Cp();
$cCp->queue[] = new InvalidPayloadException('The Control Panel response does not confirm success.');
$cFlow = new OrderOrchestrator(
    $cAttempts,
    $cSnapshots,
    $cOrders,
    $cCp,
    new FinancingSnapshotFactory(new SensitiveDataCipher()),
    new ControlPanelOrderPayloadBuilder()
);
try {
    $cFlow->orchestrate(1, 103, $request, $shop);
    assertAud019(false, 'C: InvalidPayload must throw');
} catch (OrderOrchestrationException $exception) {
    assertAud019($exception->isPostOrder(), 'C: InvalidPayload is post-order');
    assertAud019($exception->isOutcomeUnknown(), 'C: InvalidPayload is outcome unknown');
    assertAud019($exception->state() === OrderOrchestrator::CP_OUTCOME_UNKNOWN, 'C: state unknown');
    aud019AssertOrderAwareResponse(PostOrderPopupFailureResponse::fromException($exception), 'C response');
}
assertAud019($cOrders->validateOrderCalls === 1, 'C: one PS order only');

// D. CP MalformedJsonException → outcome unknown
$dAttempts = new Aud019Attempts();
$dOrders = new Aud019Orders($created);
$dCp = new Aud019Cp();
$dCp->queue[] = new MalformedJsonException('The Control Panel returned malformed JSON.');
$dFlow = new OrderOrchestrator(
    $dAttempts,
    new Aud019Snapshots(),
    $dOrders,
    $dCp,
    new FinancingSnapshotFactory(new SensitiveDataCipher()),
    new ControlPanelOrderPayloadBuilder()
);
try {
    $dFlow->orchestrate(1, 104, $request, $shop);
    assertAud019(false, 'D: malformed must throw');
} catch (OrderOrchestrationException $exception) {
    assertAud019($exception->isPostOrder() && $exception->isOutcomeUnknown(), 'D: malformed → unknown');
    aud019AssertOrderAwareResponse(PostOrderPopupFailureResponse::fromException($exception), 'D response');
}

// E/F/G/H/I — controller contracts for post-order binder + no markFailed on post-order path
assertAud019(
    (bool) preg_match('/isPostOrder\(\)[\s\S]*PopupSubmissionPostOrderBinder::bind[\s\S]*fromException/s', $productPopup),
    'E: product post-order binds then returns order-aware response'
);
assertAud019(
    strpos($productPopup, 'markFailed($submissionId)') !== false
        && (bool) preg_match('/isPostOrder\(\)[\s\S]{0,400}return PostOrderPopupFailureResponse/s', $productPopup),
    'E: product does not markFailed before order-aware return'
);
assertAud019(strpos($productPopup, 'recoverPopupNativeOrderId') !== false, 'I: product recovers native order on Throwable');
assertAud019(
    (bool) preg_match('/isPostOrder\(\)[\s\S]*PopupSubmissionPostOrderBinder::bind[\s\S]*fromException/s', $cartPopup),
    'G: cart post-order binds then order-aware response'
);
assertAud019(
    (bool) preg_match('/isPostOrder\(\)[\s\S]*OrderConfirmationUrlBuilder/s', $checkout),
    'H: checkout post-order redirects confirmation'
);
assertAud019(
    strpos($checkout, 'Изборът на финансиране не може да бъде валидиран') !== false
        && (bool) preg_match('/getIdByCartId\(\$idCart\)[\s\S]*OrderConfirmationUrlBuilder/s', $checkout),
    'H: checkout Throwable recovers order before pre-order UX'
);

// J. True PRE-ORDER exception before validateOrder — original Throwable escapes
$jAttempts = new Aud019Attempts();
$jOrders = new Aud019Orders($created);
$jOrders->failCreatePreOrder = true;
$jFlow = new OrderOrchestrator(
    $jAttempts,
    new Aud019Snapshots(),
    $jOrders,
    new Aud019Cp(),
    new FinancingSnapshotFactory(new SensitiveDataCipher()),
    new ControlPanelOrderPayloadBuilder()
);
$preOrderThrown = false;
try {
    $jFlow->orchestrate(1, 105, $request, $shop);
} catch (OrderOrchestrationException $exception) {
    assertAud019(!$exception->isPostOrder(), 'J: must not classify as post-order');
    $preOrderThrown = true;
} catch (RuntimeException $exception) {
    assertAud019($exception->getMessage() === 'pre-order create failed', 'J: original pre-order exception preserved');
    $preOrderThrown = true;
}
assertAud019($preOrderThrown, 'J: pre-order failure still thrown');
assertAud019($jOrders->validateOrderCalls === 0, 'J: validateOrder not called');

fwrite(STDOUT, "OK (AUD-019 post-order exception boundary)\n");
