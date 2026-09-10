<?php

declare(strict_types=1);

/**
 * Production P2 CP-client wiring + durable sync PATCH behavior.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Api\Exception\TimeoutException;
use PrestaShop\Module\Unipayment\Order\BankStatus;
use PrestaShop\Module\Unipayment\Order\ControlPanelOrderClientInterface;
use PrestaShop\Module\Unipayment\Order\ControlPanelStatusSyncService;
use PrestaShop\Module\Unipayment\Order\ControlPanelStatusSyncStates;
use PrestaShop\Module\Unipayment\Order\ControlPanelStatusSyncStoreAdapter;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotStoreInterface;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrationResult;
use PrestaShop\Module\Unipayment\Order\PostControlPanelLifecycleContext;
use PrestaShop\Module\Unipayment\Order\PostControlPanelLifecycleService;
use PrestaShop\Module\Unipayment\Order\PostControlPanelSmartUcfPort;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCoordinationResult;

if (!class_exists('PrestaShopLogger', false)) {
    class PrestaShopLogger
    {
        public static function addLog(string $message, int $severity = 1): void
        {
            unset($message, $severity);
        }
    }
}

function assertP2Wire(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);

// --- Production construction paths must pass CP client into lifecycle ---
$checkout = (string) file_get_contents($root . '/controllers/front/validatecheckout.php');
$product = (string) file_get_contents($root . '/controllers/front/productpopup.php');
$cart = (string) file_get_contents($root . '/controllers/front/cartpopup.php');

foreach ([
    'validatecheckout' => $checkout,
    'productpopup' => $product,
    'cartpopup' => $cart,
] as $name => $src) {
    assertP2Wire(
        strpos($src, 'new PostControlPanelLifecycleService(null, null, null, null, $cpClient)') !== false,
        $name . ' must construct PostControlPanelLifecycleService with $cpClient'
    );
    assertP2Wire(
        strpos($src, 'new PostControlPanelLifecycleService()') === false,
        $name . ' must not construct PostControlPanelLifecycleService without CP client'
    );
}

final class P2WireSnapshotStore implements FinancingSnapshotStoreInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    /** @var int */
    public int $handoffMarks = 0;

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
        $this->rows[$attemptId] = array_replace($this->rows[$attemptId] ?? [], $changes);
        if (isset($changes['cp_status_sync_state'])) {
            ++$this->handoffMarks;
        }
    }
}

final class P2WireCpClient implements ControlPanelOrderClientInterface
{
    /** @var list<array{orderId:string,status:string,statusId:string}> */
    public array $patches = [];

    /** @var list<\Throwable|array<string, mixed>> */
    public array $queue = [];

    public function createOrder(array $payload): array
    {
        throw new \RuntimeException('createOrder must not run during P2 status sync');
    }

    public function updateOrderStatus(string $orderId, string $status, string $statusId): array
    {
        $this->patches[] = [
            'orderId' => $orderId,
            'status' => $status,
            'statusId' => $statusId,
        ];
        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return is_array($next) ? $next : [
            'data' => [
                'order_id' => $orderId,
                'status_id' => $statusId,
                'status' => $status,
            ],
        ];
    }
}

final class P2WireBank implements \PrestaShop\Module\Unipayment\Order\BankStatusPersistencePort
{
    /** @var list<array{statusId:string}> */
    public array $updates = [];

    public function updateByOrderIdentifier(int $idShop, string $orderReference, string $statusId, string $statusLabel): ?array
    {
        unset($idShop, $orderReference, $statusLabel);
        $this->updates[] = ['statusId' => $statusId];

        return ['status_id' => $statusId];
    }
}

final class P2WireMail implements \PrestaShop\Module\Unipayment\Order\LeasingMailDispatchPort
{
    public int $sends = 0;

    public function send(array $snapshot, int $attemptId, array $shop, array $status): void
    {
        unset($snapshot, $attemptId, $shop, $status);
        ++$this->sends;
    }
}

final class P2WireSmartUcf implements PostControlPanelSmartUcfPort
{
    public int $runs = 0;

    public function run(int $attemptId, array $shop, bool $process2, ?array $snapshot = null): SmartUcfCoordinationResult
    {
        ++$this->runs;
        unset($attemptId, $shop, $process2, $snapshot);

        return SmartUcfCoordinationResult::process2();
    }

    public function resume(int $attemptId, array $shop, bool $process2): SmartUcfCoordinationResult
    {
        return $this->run($attemptId, $shop, $process2, null);
    }
}

$store = new P2WireSnapshotStore();
$store->save(42, [
    'id_attempt' => 42,
    'order_reference' => 'REF42',
    'id_order' => 42,
    'leasing_email_sent' => 0,
    'cp_status_sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
    'cp_status_sync_status_id' => null,
    'cp_status_sync_status' => null,
    'cp_status_sync_error_class' => null,
    'customer_json' => [],
    'address_json' => [],
    'lines_json' => [],
    'consents_json' => [],
]);
$cp = new P2WireCpClient();
$bank = new P2WireBank();
$mail = new P2WireMail();
$syncStore = new ControlPanelStatusSyncStoreAdapter($store);
$service = new PostControlPanelLifecycleService(
    $store,
    $mail,
    $bank,
    null,
    $cp,
    new ControlPanelStatusSyncService($syncStore, $cp)
);

$order = new OrderOrchestrationResult(42, 'cp_created', 42, 'REF42', 900);
$shop = ['uni_proces' => 1];
$ctx = new PostControlPanelLifecycleContext(1, 'BGN', false, true);
$result = $service->handle($order, $shop, $ctx, new P2WireSmartUcf());

assertP2Wire($result->isProcess2(), 'P2 handoff result');
assertP2Wire(count($cp->patches) === 1, 'P2 success must PATCH once');
assertP2Wire($cp->patches[0]['statusId'] === BankStatus::SENT_PROCESS2, 'PATCH target bank_sent_process2');
assertP2Wire(
    (string) ($store->rows[42]['cp_status_sync_state'] ?? '') === ControlPanelStatusSyncStates::CONFIRMED,
    'canonical PATCH success → confirmed'
);
assertP2Wire($mail->sends === 1, 'first P2 path may send leasing email');
assertP2Wire($bank->updates !== [] && $bank->updates[0]['statusId'] === BankStatus::SENT_PROCESS2, 'local P2 bank status persisted');

// Transient PATCH failure → pending
$store2 = new P2WireSnapshotStore();
$store2->save(43, [
    'id_attempt' => 43,
    'order_reference' => 'REF43',
    'id_order' => 43,
    'leasing_email_sent' => 0,
    'cp_status_sync_state' => ControlPanelStatusSyncStates::NOT_NEEDED,
    'cp_status_sync_status_id' => null,
    'cp_status_sync_status' => null,
    'customer_json' => [],
    'address_json' => [],
    'lines_json' => [],
    'consents_json' => [],
]);
$cp2 = new P2WireCpClient();
$cp2->queue[] = new TimeoutException('timeout');
$bank2 = new P2WireBank();
$mail2 = new P2WireMail();
$service2 = new PostControlPanelLifecycleService(
    $store2,
    $mail2,
    $bank2,
    null,
    $cp2,
    new ControlPanelStatusSyncService(new ControlPanelStatusSyncStoreAdapter($store2), $cp2)
);
$service2->handle(
    new OrderOrchestrationResult(43, 'cp_created', 43, 'REF43', 901),
    $shop,
    new PostControlPanelLifecycleContext(1, 'BGN', false, true),
    new P2WireSmartUcf()
);
assertP2Wire(count($cp2->patches) === 1, 'transient failure still attempted PATCH');
assertP2Wire(
    (string) ($store2->rows[43]['cp_status_sync_state'] ?? '') === ControlPanelStatusSyncStates::PENDING,
    'transient PATCH failure remains pending'
);

// Replay pending → PATCH retried; no email when sendLeasingEmail=false; no createOrder
$cp2->queue[] = [
    'data' => [
        'order_id' => 'REF43',
        'status_id' => BankStatus::SENT_PROCESS2,
        'status' => BankStatus::LABEL_SENT_PROCESS2,
    ],
];
$service2->handle(
    new OrderOrchestrationResult(43, 'cp_created', 43, 'REF43', 901),
    $shop,
    new PostControlPanelLifecycleContext(1, 'BGN', true, false),
    new P2WireSmartUcf()
);
assertP2Wire(count($cp2->patches) === 2, 'pending replay retries PATCH');
assertP2Wire(
    (string) ($store2->rows[43]['cp_status_sync_state'] ?? '') === ControlPanelStatusSyncStates::CONFIRMED,
    'pending replay can confirm'
);
assertP2Wire($mail2->sends === 1, 'replay with sendLeasingEmail=false must not repeat email');

fwrite(STDOUT, "OK (P2 production CP client wiring + durable sync)\n");
