<?php

declare(strict_types=1);

/**
 * Empty financing order lines must not call Control Panel create.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('_NEW_COOKIE_KEY_', 'test-key');

final class PhpEncryption
{
    public function __construct(string $key)
    {
    }

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
use PrestaShop\Module\Unipayment\Order\PrestaShopOrderGatewayInterface;
use PrestaShop\Module\Unipayment\Order\SensitiveDataCipher;

function assertEmptyLines(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class EmptyLinesAttempts implements OrderAttemptStoreInterface
{
    /** @var array<string, array<string, mixed>> */
    public $rows = [];

    public function reserve(int $shop, int $cart, string $fingerprint): array
    {
        $key = $shop . ':' . $cart . ':' . $fingerprint;
        if (!isset($this->rows[$key])) {
            $this->rows[$key] = [
                'id_attempt' => 1,
                'id_shop' => $shop,
                'id_cart' => $cart,
                'cart_fingerprint' => $fingerprint,
                'state' => OrderOrchestrator::RESERVED,
                'id_order' => null,
                'order_reference' => null,
                'control_panel_order_id' => null,
                'cp_payload' => null,
                'last_error_class' => null,
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
        return $this->update($attemptId, [
            'state' => OrderOrchestrator::PS_ORDER_CREATED,
            'id_order' => $idOrder,
            'order_reference' => substr($orderReference, 0, 13),
        ]);
    }
}

final class EmptyLinesSnapshots implements FinancingSnapshotStoreInterface
{
    public function save(int $attemptId, array $snapshot): void
    {
    }

    public function findByAttempt(int $attemptId): ?array
    {
        return null;
    }

    public function update(int $attemptId, array $changes): void
    {
    }
}

final class EmptyLinesOrders implements PrestaShopOrderGatewayInterface
{
    public function create(ValidatedPaymentRequest $request, array $shop = []): CreatedOrder
    {
        return new CreatedOrder(51, 'EMPTYTWINORD', 555.46, 'EUR', 2, [
            'first_name' => 'A',
            'last_name' => 'B',
            'email' => 'a@example.com',
            'phone' => '0888123456',
        ], ['invoice' => [], 'delivery' => []], []);
    }

    public function load(int $idOrder): CreatedOrder
    {
        return $this->create(new ValidatedPaymentRequest(
            $GLOBALS['__empty_lines_calc'],
            ['first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.com', 'phone' => '1', 'address' => 'x'],
            [],
            'fp'
        ));
    }

    public function markFailed(int $idOrder): void
    {
    }

    public function markAwaiting(int $idOrder): void
    {
    }
}

final class EmptyLinesCp implements ControlPanelOrderClientInterface
{
    public int $calls = 0;

    public function createOrder(array $payload): array
    {
        ++$this->calls;

        return ['data' => ['id' => 1]];
    }

    public function updateOrderStatus(string $orderId, string $status, ?string $statusId = null): array
    {
        return ['ok' => true];
    }
}

$calculator = new Calculator('2026-08-17');
$shop = calculatorFixture(['uni_eur' => 0]);
$cart = new CartContext([new CartLine(new ProductContext(42, [7], 555.46), 0, 1, 555.46)], 555.46, ['carrier_id' => 2, 'shipping_total' => '0.00']);
$scheme = (new CartSchemeResolver($calculator))->resolve($shop, $cart)->standardSchemes[0];
$calculation = $calculator->calculateScheme($shop, 555.46, $scheme, 0.0);
$GLOBALS['__empty_lines_calc'] = $calculation;

$request = new ValidatedPaymentRequest(
    $calculation,
    [
        'first_name' => 'A',
        'last_name' => 'B',
        'email' => 'a@example.com',
        'phone' => '0888123456',
        'egn' => '1990010199',
        'address' => 'Street 1',
    ],
    [7],
    'empty-lines-fp'
);

$attempts = new EmptyLinesAttempts();
$cp = new EmptyLinesCp();
$orchestrator = new OrderOrchestrator(
    $attempts,
    new EmptyLinesSnapshots(),
    new EmptyLinesOrders(),
    $cp,
    new FinancingSnapshotFactory(new SensitiveDataCipher()),
    new ControlPanelOrderPayloadBuilder()
);

$threw = false;
try {
    $orchestrator->orchestrate(1, 61, $request, $shop, 'cart_popup');
} catch (OrderOrchestrationException $exception) {
    $threw = true;
    assertEmptyLines($exception->isPostOrder(), 'empty lines is post-order');
    assertEmptyLines($exception->state() === OrderOrchestrator::TERMINAL_FAILED, 'terminal_failed');
    assertEmptyLines($exception->idOrder() === 51, 'keeps id_order');
}
assertEmptyLines($threw, 'must throw');
assertEmptyLines($cp->calls === 0, 'F: CP must not be called');
$row = reset($attempts->rows);
assertEmptyLines(is_array($row) && ($row['last_error_class'] ?? '') === 'EmptyOrderLines', 'EmptyOrderLines persisted');

fwrite(STDOUT, "OK (empty order lines CP guard)\n");
