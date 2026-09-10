<?php

declare(strict_types=1);

/**
 * Product Page P1 snapshot persistence + orchestration boundary regressions.
 *
 * Covers the proven runtime defect: unescaped apostrophe in product name broke
 * FinancingSnapshotRepository::save via PrestaShop Db::insert (no pSQL).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Calculator\AvailableScheme;
use PrestaShop\Module\Unipayment\Calculator\CalculationResult;
use PrestaShop\Module\Unipayment\Calculator\FirstInstallmentState;
use PrestaShop\Module\Unipayment\Checkout\ValidatedPaymentRequest;
use PrestaShop\Module\Unipayment\Order\ControlPanelOrderClientInterface;
use PrestaShop\Module\Unipayment\Order\ControlPanelOrderPayloadBuilder;
use PrestaShop\Module\Unipayment\Order\CreatedOrder;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotFactory;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotRepository;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotStoreInterface;
use PrestaShop\Module\Unipayment\Order\OrderAttemptStoreInterface;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrationException;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrator;
use PrestaShop\Module\Unipayment\Order\PrestaShopOrderGatewayInterface;
use PrestaShop\Module\Unipayment\Order\SensitiveDataCipher;

if (!function_exists('pSQL')) {
    function pSQL(string $string, bool $htmlOK = false): string
    {
        unset($htmlOK);

        return str_replace(['\\', "\0", "\n", "\r", "'", '"', "\x1a"], ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'], $string);
    }
}

if (!class_exists('PrestaShopDatabaseException', false)) {
    class PrestaShopDatabaseException extends \RuntimeException
    {
    }
}

if (!class_exists('PrestaShopLogger', false)) {
    class PrestaShopLogger
    {
        /** @var list<string> */
        public static array $messages = [];

        public static function addLog(
            string $message,
            int $severity = 1,
            ?int $errorCode = null,
            ?string $objectType = null,
            ?int $objectId = null,
            bool $allowDuplicate = false,
            ?int $idEmployee = null
        ): bool {
            unset($severity, $errorCode, $objectType, $objectId, $allowDuplicate, $idEmployee);
            self::$messages[] = $message;

            return true;
        }
    }
}

if (!class_exists('PhpEncryption', false)) {
    class PhpEncryption
    {
        public function __construct(?string $key = null)
        {
            unset($key);
        }

        public function encrypt(string $data): string
        {
            return base64_encode($data);
        }

        /** @return string|false */
        public function decrypt(string $data)
        {
            $decoded = base64_decode($data, true);

            return $decoded === false ? false : $decoded;
        }
    }
}

if (!defined('_NEW_COOKIE_KEY_')) {
    define('_NEW_COOKIE_KEY_', 'product-p1-snapshot-test-key');
}

function assertProductP1(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class ProductP1CaptureDb
{
    /** @var list<array<string, mixed>> */
    public array $insertRows = [];

    public bool $failInsert = false;

    public ?\Throwable $insertException = null;

    /**
     * @param array<string, mixed> $data
     */
    public function insert(
        string $table,
        array $data,
        bool $null_values = false,
        bool $use_cache = true,
        int $type = 1,
        bool $add_prefix = true
    ): bool {
        unset($null_values, $use_cache, $type, $add_prefix);
        if ($this->insertException instanceof \Throwable) {
            throw $this->insertException;
        }
        if ($this->failInsert) {
            return false;
        }
        $this->insertRows[] = ['table' => $table, 'data' => $data];

        return true;
    }

    /** @return array<string, mixed>|false */
    public function getRow(string $sql)
    {
        unset($sql);

        return false;
    }

    public function execute(string $sql): bool
    {
        unset($sql);

        return true;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(
        string $table,
        array $data,
        string $where = '',
        int $limit = 0,
        bool $null_values = false,
        bool $use_cache = true,
        bool $add_prefix = true
    ): bool {
        unset($table, $data, $where, $limit, $null_values, $use_cache, $add_prefix);

        return true;
    }
}

final class ProductP1Attempts implements OrderAttemptStoreInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    /** @var int */
    private $nextId = 1;

    public function reserve(int $idShop, int $idCart, string $cartFingerprint): array
    {
        foreach ($this->rows as $row) {
            if ((int) $row['id_shop'] === $idShop && (int) $row['id_cart'] === $idCart && (string) $row['cart_fingerprint'] === $cartFingerprint) {
                return $row;
            }
        }
        $id = $this->nextId++;
        $this->rows[$id] = [
            'id_attempt' => $id,
            'id_shop' => $idShop,
            'id_cart' => $idCart,
            'cart_fingerprint' => $cartFingerprint,
            'state' => OrderOrchestrator::RESERVED,
            'id_order' => null,
            'order_reference' => null,
            'control_panel_order_id' => null,
            'cp_payload' => null,
            'last_error_class' => null,
        ];

        return $this->rows[$id];
    }

    public function update(int $attemptId, array $changes): array
    {
        foreach ($changes as $key => $value) {
            $this->rows[$attemptId][$key] = $value;
        }

        return $this->rows[$attemptId];
    }

    public function attachOrderIfReserved(int $attemptId, int $idOrder, string $orderReference): array
    {
        $this->rows[$attemptId]['state'] = OrderOrchestrator::PS_ORDER_CREATED;
        $this->rows[$attemptId]['id_order'] = $idOrder;
        $this->rows[$attemptId]['order_reference'] = substr($orderReference, 0, 13);

        return $this->rows[$attemptId];
    }

    public function findById(int $attemptId): ?array
    {
        return $this->rows[$attemptId] ?? null;
    }
}

final class ProductP1Snapshots implements FinancingSnapshotStoreInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    /** @var \Throwable|null */
    public $failSaveWith = null;

    public function save(int $attemptId, array $snapshot): void
    {
        if ($this->failSaveWith instanceof \Throwable) {
            throw $this->failSaveWith;
        }
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
        foreach ($changes as $key => $value) {
            $this->rows[$attemptId][$key] = $value;
        }
    }
}

final class ProductP1Orders implements PrestaShopOrderGatewayInterface
{
    /** @var CreatedOrder */
    private $order;

    /** @var int */
    public int $createCalls = 0;

    public function __construct(CreatedOrder $order)
    {
        $this->order = $order;
    }

    public function create(ValidatedPaymentRequest $request, array $shop = []): CreatedOrder
    {
        unset($request, $shop);
        ++$this->createCalls;

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

final class ProductP1Cp implements ControlPanelOrderClientInterface
{
    /** @var int */
    public int $createCalls = 0;

    /** @var list<array<string, mixed>> */
    public array $payloads = [];

    public function createOrder(array $order): array
    {
        ++$this->createCalls;
        $this->payloads[] = $order;

        return ['data' => ['id' => 501, 'order_id' => (string) ($order['order_id'] ?? ''), 'unicid' => 'u', 'shop_id' => 1, 'created_at' => '2026-09-10T00:00:00+00:00']];
    }

    public function updateOrderStatus(string $orderId, string $status, string $statusId): array
    {
        unset($orderId, $status, $statusId);

        return [];
    }
}

function productP1Request(string $productName): ValidatedPaymentRequest
{
    $scheme = new AvailableScheme('standard', 'POS COM 50', 12, 8, null, ['coeff' => 0.097487, 'interestPercent' => 30.0]);
    $calculation = new CalculationResult(
        $scheme,
        1234.56,
        new FirstInstallmentState(0.0, true, true),
        1234.56,
        120.0,
        1440.0,
        100.0,
        30.0
    );

    return new ValidatedPaymentRequest(
        $calculation,
        [
            'first_name' => 'Ivan',
            'last_name' => 'Ivanov',
            'phone' => '0888000111',
            'email' => 'ivan@example.com',
            'address' => "Sofia's Street 1",
        ],
        [1],
        md5('product-p1-fingerprint'),
        [['id' => 1, 'name' => "Consent's text", 'url' => 'https://example.com/c', 'mandatory' => true]]
    );
}

function productP1Order(string $productName): CreatedOrder
{
    return new CreatedOrder(
        73001,
        'GPNCGCDNY',
        1234.56,
        'EUR',
        2,
        [
            'first_name' => 'Ivan',
            'last_name' => 'Ivanov',
            'phone' => '0888000111',
            'email' => 'ivan@example.com',
        ],
        [
            'invoice' => ['address1' => "Sofia's Street 1", 'address2' => '', 'postcode' => '1000', 'city' => 'Sofia', 'country' => 'Bulgaria'],
            'delivery' => ['address1' => "Sofia's Street 1", 'address2' => '', 'postcode' => '1000', 'city' => 'Sofia', 'country' => 'Bulgaria'],
        ],
        [[
            'id_product' => 3,
            'id_product_attribute' => 0,
            'name' => $productName,
            'quantity' => 1,
            'total' => 1234.56,
        ]]
    );
}

$productName = "The best is yet to come' Framed poster";
$shop = ['uni_proces' => 0, 'unicid' => 'u'];

// ---------------------------------------------------------------------------
// D. Repository: apostrophe in lines_json must be pSQL-escaped before insert
// ---------------------------------------------------------------------------
$captureDb = new ProductP1CaptureDb();
$repo = new FinancingSnapshotRepository($captureDb);
$factory = new FinancingSnapshotFactory(new SensitiveDataCipher());
$snapshot = $factory->create(productP1Request($productName), productP1Order($productName), 'product_popup');
$repo->save(3, $snapshot);

assertProductP1(count($captureDb->insertRows) === 1, 'D: snapshot insert attempted');
$inserted = $captureDb->insertRows[0]['data'];
assertProductP1(is_string($inserted['lines_json'] ?? null), 'D: lines_json stored as string');
assertProductP1(strpos((string) $inserted['lines_json'], "come\\' Framed") !== false, 'D: apostrophe escaped with pSQL');
assertProductP1(strpos((string) $inserted['address_json'], "Sofia\\'s") !== false, 'D: address apostrophe escaped in address_json');
assertProductP1(strpos((string) $inserted['consents_json'], "Consent\\'s") !== false, 'D: consent apostrophe escaped');
$unescapePs = static function (string $escaped): string {
    $out = '';
    $length = strlen($escaped);
    for ($i = 0; $i < $length; ++$i) {
        if ($escaped[$i] === '\\' && $i + 1 < $length) {
            $next = $escaped[$i + 1];
            if ($next === '\\') {
                $out .= '\\';
            } elseif ($next === "'") {
                $out .= "'";
            } elseif ($next === '"') {
                $out .= '"';
            } elseif ($next === '0') {
                $out .= "\0";
            } elseif ($next === 'n') {
                $out .= "\n";
            } elseif ($next === 'r') {
                $out .= "\r";
            } elseif ($next === 'Z') {
                $out .= "\x1a";
            } else {
                $out .= $next;
            }
            ++$i;
            continue;
        }
        $out .= $escaped[$i];
    }

    return $out;
};
$decodedLines = json_decode($unescapePs((string) $inserted['lines_json']), true);
assertProductP1(is_array($decodedLines) && ($decodedLines[0]['name'] ?? null) === $productName, 'D: decoded product name equals original');
assertProductP1(is_array($inserted['control_panel_order_id'] ?? null)
    && ($inserted['control_panel_order_id']['type'] ?? '') === 'sql'
    && ($inserted['control_panel_order_id']['value'] ?? '') === 'NULL', 'D: null control_panel_order_id becomes SQL NULL');
assertProductP1(is_array($inserted['sensitive_payload'] ?? null)
    && ($inserted['sensitive_payload']['value'] ?? '') === 'NULL', 'D: null sensitive_payload becomes SQL NULL');
assertProductP1(is_array($inserted['cp_status_sync_updated_at'] ?? null)
    && ($inserted['cp_status_sync_updated_at']['value'] ?? '') === 'NULL', 'D: null cp_status_sync_updated_at becomes SQL NULL');

// ---------------------------------------------------------------------------
// A. Successful Product Page P1 orchestration: snapshot then createOrder
// ---------------------------------------------------------------------------
$aAttempts = new ProductP1Attempts();
$aSnapshots = new ProductP1Snapshots();
$aOrders = new ProductP1Orders(productP1Order($productName));
$aCp = new ProductP1Cp();
$aFlow = new OrderOrchestrator(
    $aAttempts,
    $aSnapshots,
    $aOrders,
    $aCp,
    new FinancingSnapshotFactory(new SensitiveDataCipher()),
    new ControlPanelOrderPayloadBuilder()
);
$aResult = $aFlow->orchestrate(1, 84, productP1Request($productName), $shop, 'product_popup');
assertProductP1($aResult->state === OrderOrchestrator::CP_CREATED, 'A: attempt advances to cp_created');
assertProductP1($aOrders->createCalls === 1, 'A: native order created once');
assertProductP1(isset($aSnapshots->rows[$aResult->attemptId]), 'A: snapshot persisted');
assertProductP1((string) ($aSnapshots->rows[$aResult->attemptId]['submission_source'] ?? '') === 'product_popup', 'A: product_popup source');
assertProductP1($aCp->createCalls === 1, 'A: createOrder invoked after snapshot');
assertProductP1(isset($aAttempts->rows[$aResult->attemptId]['cp_payload'])
    && is_string($aAttempts->rows[$aResult->attemptId]['cp_payload'])
    && $aAttempts->rows[$aResult->attemptId]['cp_payload'] !== '', 'A: payload frozen');

// ---------------------------------------------------------------------------
// B. Snapshot factory failure (via save throw from factory path using failing store)
//     Simulate create-time failure by throwing before save completes.
// ---------------------------------------------------------------------------
$bAttempts = new ProductP1Attempts();
$bSnapshots = new ProductP1Snapshots();
$bSnapshots->failSaveWith = new RuntimeException('snapshot factory downstream failure');
$bOrders = new ProductP1Orders(productP1Order($productName));
$bCp = new ProductP1Cp();
PrestaShopLogger::$messages = [];
$bFlow = new OrderOrchestrator(
    $bAttempts,
    $bSnapshots,
    $bOrders,
    $bCp,
    new FinancingSnapshotFactory(new SensitiveDataCipher()),
    new ControlPanelOrderPayloadBuilder()
);
try {
    $bFlow->orchestrate(1, 85, productP1Request($productName), $shop, 'product_popup');
    assertProductP1(false, 'B: must throw on snapshot failure');
} catch (OrderOrchestrationException $exception) {
    assertProductP1($exception->isPostOrder(), 'B: post-order failure');
    assertProductP1($exception->state() === OrderOrchestrator::PS_ORDER_CREATED, 'B: remains ps_order_created');
    assertProductP1($exception->getPrevious() instanceof RuntimeException, 'B: previous throwable preserved');
    $joined = implode("\n", PrestaShopLogger::$messages);
    assertProductP1(strpos($joined, 'phase=snapshot') !== false, 'B: boundary log includes snapshot phase');
    assertProductP1(strpos($joined, 'RuntimeException') !== false, 'B: boundary log includes underlying class');
}
assertProductP1($bCp->createCalls === 0, 'B: no CP create after snapshot failure');
assertProductP1($bSnapshots->rows === [], 'B: no snapshot row');
assertProductP1((string) $bAttempts->rows[1]['state'] === OrderOrchestrator::PS_ORDER_CREATED, 'B: attempt state ps_order_created');
assertProductP1(($bAttempts->rows[1]['cp_payload'] ?? null) === null, 'B: no frozen payload');
assertProductP1(($bAttempts->rows[1]['last_error_class'] ?? null) === null, 'B: no false last_error_class');

// ---------------------------------------------------------------------------
// C. Snapshot repository insert failure
// ---------------------------------------------------------------------------
$cAttempts = new ProductP1Attempts();
$cSnapshots = new ProductP1Snapshots();
$cSnapshots->failSaveWith = new PrestaShopDatabaseException("You have an error in your SQL syntax near 'Framed poster'");
$cOrders = new ProductP1Orders(productP1Order($productName));
$cCp = new ProductP1Cp();
PrestaShopLogger::$messages = [];
$cFlow = new OrderOrchestrator(
    $cAttempts,
    $cSnapshots,
    $cOrders,
    $cCp,
    new FinancingSnapshotFactory(new SensitiveDataCipher()),
    new ControlPanelOrderPayloadBuilder()
);
try {
    $cFlow->orchestrate(1, 86, productP1Request($productName), $shop, 'product_popup');
    assertProductP1(false, 'C: must throw on repository failure');
} catch (OrderOrchestrationException $exception) {
    assertProductP1($exception->state() === OrderOrchestrator::PS_ORDER_CREATED, 'C: remains ps_order_created');
    assertProductP1($exception->getPrevious() instanceof PrestaShopDatabaseException, 'C: previous DB exception');
    $joined = implode("\n", PrestaShopLogger::$messages);
    assertProductP1(strpos($joined, 'PrestaShopDatabaseException') !== false, 'C: diagnosable underlying class');
    assertProductP1(strpos($joined, '[sql-redacted]') !== false || strpos($joined, 'SQL syntax') !== false, 'C: sanitized/diagnosable message');
}
assertProductP1($cCp->createCalls === 0, 'C: no CP create');
assertProductP1($cSnapshots->rows === [], 'C: no snapshot');

// Contract: productpopup logs previous throwable metadata
$productPopup = (string) file_get_contents($root . '/controllers/front/productpopup.php');
assertProductP1(strpos($productPopup, 'previous=') !== false, 'productpopup logs previous exception class');
assertProductP1(strpos($productPopup, 'sanitizeExceptionMessage($previous)') !== false, 'productpopup sanitizes previous message');

$repoSrc = (string) file_get_contents($root . '/src/Order/FinancingSnapshotRepository.php');
assertProductP1(strpos($repoSrc, 'prepareInsertValues') !== false, 'repository prepares insert values');
assertProductP1(strpos($repoSrc, 'escapeInsertString') !== false, 'repository escapes insert strings');
$attemptSrc = (string) file_get_contents($root . '/src/Order/OrderAttemptRepository.php');
assertProductP1(strpos($attemptSrc, 'prepareUpdateValues') !== false, 'attempt repository prepares update values');
assertProductP1(strpos($attemptSrc, 'escapeUpdateString') !== false, 'attempt repository escapes update strings');

fwrite(STDOUT, "OK (product P1 snapshot apostrophe + orchestration boundary)\n");
