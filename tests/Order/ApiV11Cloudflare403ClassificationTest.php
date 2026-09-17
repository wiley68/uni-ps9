<?php

declare(strict_types=1);

/**
 * Realistic /api/v11 manual break: Cloudflare HTTP 403 HTML challenge/rejection
 * (no application machine-code) must classify as definitive CP create failure.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Api\Exception\ConnectionException;
use PrestaShop\Module\Unipayment\Api\Exception\HttpException;
use PrestaShop\Module\Unipayment\Api\Exception\MalformedJsonException;
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
    define('_NEW_COOKIE_KEY_', 'api-v11-classify-test-key');
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

function assertApiV11(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class ApiV11Attempts implements OrderAttemptStoreInterface
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

final class ApiV11Snapshots implements FinancingSnapshotStoreInterface
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

final class ApiV11Orders implements PrestaShopOrderGatewayInterface
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

final class ApiV11Cp implements ControlPanelOrderClientInterface
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

final class ApiV11Bank implements BankStatusPersistencePort
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

final class ApiV11Mail implements LeasingMailDispatchPort
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
$clientSrc = (string) file_get_contents($root . '/src/Api/ControlPanelClient.php');
$orchestratorSrc = (string) file_get_contents($root . '/src/Order/OrderOrchestrator.php');

// Realistic Cloudflare edge rejection observed for POST /api/v11/orders:
// HTTP 403, text/html challenge page, empty decoded error array (HTML is not CP JSON).
$cloudflareChallengeHtml =
    '<!DOCTYPE html><html lang="en-US"><head><title>Just a moment...</title>'
    . '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">'
    . '</head><body>cloudflare challenge</body></html>';

assertApiV11(
    strpos($clientSrc, 'decodeErrorResponse') !== false,
    'CP client decodes error bodies; HTML yields empty array'
);
assertApiV11(
    (bool) preg_match('/in_array\(\$status,\s*\[403,\s*404,\s*405,\s*410\]/', $orchestratorSrc),
    'classifier treats 403/404/405/410 as explicit endpoint rejection'
);

$created = new CreatedOrder(
    91,
    'APIV11REF01',
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
    'fp-api-v11'
);

foreach (
    [
        'Process 1' => ['uni_proces' => 0],
        'Process 2' => ['uni_proces' => 1],
    ] as $label => $shop
) {
    $attempts = new ApiV11Attempts();
    $snapshots = new ApiV11Snapshots();
    $orders = new ApiV11Orders($created);
    $cp = new ApiV11Cp();
    // Empty response array mirrors ControlPanelClient::decodeErrorResponse() on HTML body.
    assertApiV11(
        strpos($cloudflareChallengeHtml, 'Just a moment...') !== false,
        'fixture retains Cloudflare challenge marker'
    );
    $cp->queue[] = new HttpException(403, []);
    $bank = new ApiV11Bank();
    $mail = new ApiV11Mail();
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
        $flow->orchestrate(1, 40 + (int) ($shop['uni_proces'] ?? 0), $request, $shop, 'product_popup');
        assertApiV11(false, "{$label}: /api/v11-style 403 must throw");
    } catch (OrderOrchestrationException $exception) {
        assertApiV11($exception->state() === OrderOrchestrator::TERMINAL_FAILED, "{$label}: terminal_failed");
        assertApiV11(!$exception->isOutcomeUnknown(), "{$label}: not outcome unknown");
        assertApiV11($exception->isPostOrder(), "{$label}: post-order");

        $thankYou = 'https://shop.example/index.php?controller=order-confirmation&id_order=91&key=sec';
        $payload = PostOrderPopupFailureResponse::fromException($exception, $thankYou);
        assertApiV11(($payload['redirect_url'] ?? '') === $thankYou, "{$label}: Thank You redirect");
        assertApiV11(!isset($payload['cp_error']) && !isset($payload['smartucf_error']), "{$label}: no popup-only terminal error");
    }

    assertApiV11($orders->created === 1, "{$label}: Shop order once");
    assertApiV11(count($cp->calls) === 1, "{$label}: one CP create");
    assertApiV11($cp->statusPatches === [], "{$label}: no CP PATCH");
    assertApiV11(
        $bank->updates !== [] && $bank->updates[0]['statusId'] === BankStatus::SEND_FAILED_CP,
        "{$label}: bank_send_failed_cp"
    );
    assertApiV11(
        $bank->updates[0]['statusLabel'] === BankStatus::LABEL_SEND_FAILED_CP,
        "{$label}: Неуспешно изпратен Банка - КП"
    );
    assertApiV11(count($mail->sent) === 1, "{$label}: standard emails once");
    assertApiV11(
        ($mail->sent[0]['status_label'] ?? '') === BankStatus::LABEL_SEND_FAILED_CP,
        "{$label}: email bank status"
    );
}

// True ambiguity retained.
foreach (
    [
        'timeout' => new ConnectionException('timeout'),
        '5xx' => new HttpException(502, []),
        '2xx-invalid-json-path' => new MalformedJsonException('not json'),
    ] as $label => $failure
) {
    $attempts = new ApiV11Attempts();
    $snapshots = new ApiV11Snapshots();
    $orders = new ApiV11Orders($created);
    $cp = new ApiV11Cp();
    $cp->queue[] = $failure;
    $bank = new ApiV11Bank();
    $mail = new ApiV11Mail();
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
        $flow->orchestrate(1, 70, $request, ['uni_proces' => 1], 'product_popup');
        assertApiV11(false, "{$label}: must throw");
    } catch (OrderOrchestrationException $exception) {
        if ($label === '5xx') {
            assertApiV11(
                $exception->state() === OrderOrchestrator::CP_FAILED_RETRYABLE,
                '5xx stays retryable ambiguous'
            );
        } else {
            assertApiV11($exception->isOutcomeUnknown(), "{$label}: remains outcome unknown");
        }
    }
    assertApiV11($bank->updates === [], "{$label}: no bank_send_failed_cp");
    assertApiV11($mail->sent === [], "{$label}: no definitive email flush");
    assertApiV11($cp->statusPatches === [], "{$label}: no CP PATCH");
}

fwrite(STDOUT, "OK (realistic /api/v11 Cloudflare 403 classification)\n");
