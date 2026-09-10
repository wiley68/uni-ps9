<?php

declare(strict_types=1);

/**
 * Apostrophe / free-text SQL escaping at FinancingSnapshotRepository + OrderAttemptRepository.
 *
 * Mirrors PrestaShop Db::insert/update quoting ('{$value}') so unescaped "'" fails,
 * while pSQL-prepared values round-trip to the original semantic string.
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
use PrestaShop\Module\Unipayment\Order\ControlPanelOrderPayloadBuilder;
use PrestaShop\Module\Unipayment\Order\CreatedOrder;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotFactory;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotRepository;
use PrestaShop\Module\Unipayment\Order\OrderAttemptRepository;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrator;
use PrestaShop\Module\Unipayment\Order\SensitiveDataCipher;

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}
if (!defined('_MYSQL_ENGINE_')) {
    define('_MYSQL_ENGINE_', 'InnoDB');
}
if (!defined('_NEW_COOKIE_KEY_')) {
    define('_NEW_COOKIE_KEY_', 'apostrophe-sql-escaping-test-key');
}

if (!function_exists('pSQL')) {
    function pSQL(string $string, bool $htmlOK = false): string
    {
        unset($htmlOK);

        return str_replace(
            ['\\', "\0", "\n", "\r", "'", '"', "\x1a"],
            ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'],
            $string
        );
    }
}

if (!class_exists('PrestaShopDatabaseException', false)) {
    class PrestaShopDatabaseException extends \RuntimeException
    {
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

function assertApos(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * Rejects values that would break PrestaShop Db text quoting: "'{$value}'".
 */
function aposAssertSqlTextSafe(string $value): void
{
    $length = strlen($value);
    for ($i = 0; $i < $length; ++$i) {
        if ($value[$i] === '\\' && $i + 1 < $length) {
            ++$i;
            continue;
        }
        if ($value[$i] === "'") {
            throw new PrestaShopDatabaseException("You have an error in your SQL syntax near '" . substr($value, $i, 24) . "'");
        }
    }
}

/** Reverse the test pSQL() escapes to the semantic MySQL-stored value. */
function aposUnescapeSqlText(string $escaped): string
{
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
}

/**
 * @param mixed $value
 * @return mixed
 */
function aposMaterializeDbValue($value)
{
    if (is_array($value) && isset($value['type']) && $value['type'] === 'sql') {
        if (($value['value'] ?? null) === 'NULL') {
            return null;
        }

        return $value['value'];
    }
    if ($value === null) {
        return null;
    }
    if (is_int($value) || is_float($value) || is_bool($value)) {
        return $value;
    }
    $text = (string) $value;
    aposAssertSqlTextSafe($text);

    return aposUnescapeSqlText($text);
}

final class ApostropheQuotingDb
{
    /** @var array<int, array<string, mixed>> */
    public array $attemptRows = [];

    /** @var array<int, array<string, mixed>> */
    public array $snapshotRows = [];

    /** @var list<array<string, mixed>> */
    public array $lastInsertData = [];

    /** @var list<array<string, mixed>> */
    public array $lastUpdateData = [];

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
        $this->lastInsertData[] = $data;
        $row = [];
        foreach ($data as $key => $value) {
            $row[$key] = aposMaterializeDbValue($value);
        }
        $attemptId = (int) ($row['id_attempt'] ?? 0);
        if ($attemptId > 0) {
            $this->snapshotRows[$attemptId] = $row;
        }

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
        unset($limit, $null_values, $use_cache, $add_prefix);
        $this->lastUpdateData[] = $data;
        if (!preg_match('/`id_attempt`\s*=\s*(\d+)/', $where, $match)) {
            throw new PrestaShopDatabaseException('Missing id_attempt where clause');
        }
        $id = (int) $match[1];
        $materialized = [];
        foreach ($data as $key => $value) {
            $materialized[$key] = aposMaterializeDbValue($value);
        }
        if (strpos($table, 'order_attempt') !== false || $table === OrderAttemptRepository::TABLE) {
            if (!isset($this->attemptRows[$id])) {
                $this->attemptRows[$id] = ['id_attempt' => $id];
            }
            foreach ($materialized as $key => $value) {
                $this->attemptRows[$id][$key] = $value;
            }

            return true;
        }
        if (!isset($this->snapshotRows[$id])) {
            $this->snapshotRows[$id] = ['id_attempt' => $id];
        }
        foreach ($materialized as $key => $value) {
            $this->snapshotRows[$id][$key] = $value;
        }

        return true;
    }

    /** @return array<string, mixed>|false */
    public function getRow(string $sql)
    {
        if (!preg_match('/`id_attempt`\s*=\s*(\d+)/', $sql, $match)) {
            return false;
        }
        $id = (int) $match[1];
        if (strpos($sql, 'unipayment_order_attempt') !== false) {
            return $this->attemptRows[$id] ?? false;
        }

        return $this->snapshotRows[$id] ?? false;
    }

    public function execute(string $sql): bool
    {
        unset($sql);

        return true;
    }

    public function Affected_Rows(): int
    {
        return 0;
    }
}

$productName = "Test Product's Name";
$customerLast = "O'Brien";
$address1 = "Sofia's Street 1";

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
$request = new ValidatedPaymentRequest(
    $calculation,
    [
        'first_name' => 'Anna',
        'last_name' => $customerLast,
        'phone' => '0888000111',
        'email' => 'anna@example.com',
        'address' => $address1,
    ],
    [1],
    md5('apostrophe-fingerprint'),
    [['id' => 1, 'name' => "Consent's text", 'url' => 'https://example.com/c', 'mandatory' => true]]
);
$order = new CreatedOrder(
    88001,
    'APOSTROPHE1',
    1234.56,
    'EUR',
    2,
    [
        'first_name' => 'Anna',
        'last_name' => $customerLast,
        'phone' => '0888000111',
        'email' => 'anna@example.com',
    ],
    [
        'invoice' => ['address1' => $address1, 'address2' => '', 'postcode' => '1000', 'city' => 'Sofia', 'country' => 'Bulgaria'],
        'delivery' => ['address1' => $address1, 'address2' => '', 'postcode' => '1000', 'city' => 'Sofia', 'country' => 'Bulgaria'],
    ],
    [[
        'id_product' => 3,
        'id_product_attribute' => 0,
        'name' => $productName,
        'quantity' => 1,
        'total' => 1234.56,
    ]]
);

// ---------------------------------------------------------------------------
// 1. Financing snapshot apostrophe round-trip
// ---------------------------------------------------------------------------
$db = new ApostropheQuotingDb();
$snapshots = new FinancingSnapshotRepository($db);
$factory = new FinancingSnapshotFactory(new SensitiveDataCipher());
$snapshot = $factory->create($request, $order, 'product_popup');
$snapshots->save(42, $snapshot);

assertApos(isset($db->snapshotRows[42]), 'snapshot insert materialized');
$loaded = $snapshots->findByAttempt(42);
assertApos(is_array($loaded), 'snapshot reloadable');
assertApos(($loaded['lines_json'][0]['name'] ?? null) === $productName, 'product name preserved exactly');
assertApos(($loaded['customer_json']['last_name'] ?? null) === $customerLast, 'customer apostrophe preserved');
assertApos(($loaded['address_json']['invoice']['address1'] ?? null) === $address1, 'address apostrophe preserved');
assertApos(($loaded['consents_json'][0]['name'] ?? null) === "Consent's text", 'consent apostrophe preserved');
assertApos(strpos((string) ($db->lastInsertData[0]['lines_json'] ?? ''), "Product\\'s Name") !== false, 'lines_json SQL-escaped before insert');
assertApos(strpos((string) json_encode($loaded['lines_json']), 'Product\\\\') === false, 'no double escaping in decoded snapshot');

// Safe name still works
$safeOrder = new CreatedOrder(
    88002,
    'SAFEPRODUCT1',
    1000.0,
    'EUR',
    2,
    ['first_name' => 'Ivan', 'last_name' => 'Ivanov', 'phone' => '0888000111', 'email' => 'ivan@example.com'],
    [
        'invoice' => ['address1' => 'Main 1', 'address2' => '', 'postcode' => '1000', 'city' => 'Sofia', 'country' => 'Bulgaria'],
        'delivery' => ['address1' => 'Main 1', 'address2' => '', 'postcode' => '1000', 'city' => 'Sofia', 'country' => 'Bulgaria'],
    ],
    [['id_product' => 1, 'id_product_attribute' => 0, 'name' => 'Test Product', 'quantity' => 1, 'total' => 1000.0]]
);
$safeRequest = new ValidatedPaymentRequest(
    $calculation,
    ['first_name' => 'Ivan', 'last_name' => 'Ivanov', 'phone' => '0888000111', 'email' => 'ivan@example.com'],
    [1],
    md5('safe-fingerprint'),
    []
);
$snapshots->save(43, $factory->create($safeRequest, $safeOrder, 'checkout'));
$safeLoaded = $snapshots->findByAttempt(43);
assertApos(($safeLoaded['lines_json'][0]['name'] ?? null) === 'Test Product', 'safe product name unchanged');

// ---------------------------------------------------------------------------
// 2–3. cp_payload apostrophe + retry idempotency
// ---------------------------------------------------------------------------
$attempts = new OrderAttemptRepository($db);
$db->attemptRows[7] = [
    'id_attempt' => 7,
    'id_shop' => 1,
    'id_cart' => 99,
    'cart_fingerprint' => str_repeat('a', 64),
    'state' => OrderOrchestrator::PS_ORDER_CREATED,
    'id_order' => 88001,
    'order_reference' => 'APOSTROPHE1',
    'control_panel_order_id' => null,
    'cp_payload' => null,
    'last_error_class' => null,
];

$payload = (new ControlPanelOrderPayloadBuilder())->build($loaded, ['uni_proces' => 0, 'unicid' => 'u']);
assertApos(($payload['products_name'] ?? null) === $productName, 'CP builder keeps product apostrophe');
assertApos(strpos((string) ($payload['name'] ?? ''), "'") !== false, 'CP builder keeps customer apostrophe');
assertApos(strpos((string) ($payload['address'] ?? ''), "'") !== false, 'CP builder keeps address apostrophe');

$encoded = json_encode($payload, JSON_THROW_ON_ERROR);
$row1 = $attempts->update(7, ['cp_payload' => $encoded]);
assertApos(is_string($row1['cp_payload'] ?? null) && $row1['cp_payload'] !== '', 'cp_payload frozen');
$decoded1 = json_decode((string) $row1['cp_payload'], true);
assertApos(is_array($decoded1), 'cp_payload JSON valid after update');
assertApos(($decoded1['products_name'] ?? null) === $productName, 'products_name semantic after update');
assertApos(($decoded1['name'] ?? null) === "Anna {$customerLast}", 'name semantic after update');
assertApos(strpos((string) ($decoded1['address'] ?? ''), $address1) !== false, 'address semantic after update');
assertApos(strpos((string) $row1['cp_payload'], "\\'") === false, 'reloaded cp_payload has no SQL escape residues');
assertApos(strpos((string) ($db->lastUpdateData[count($db->lastUpdateData) - 1]['cp_payload'] ?? ''), "Product\\'s Name") !== false, 'cp_payload SQL-escaped at update boundary');

$row2 = $attempts->update(7, ['cp_payload' => $encoded]);
$decoded2 = json_decode((string) $row2['cp_payload'], true);
assertApos(($decoded2['products_name'] ?? null) === $productName, 'retry preserves products_name');
assertApos(($decoded2['name'] ?? null) === ($decoded1['name'] ?? null), 'retry preserves name');
assertApos((string) $row2['cp_payload'] === (string) $row1['cp_payload'], 'retry does not accumulate escaping');
assertApos(strpos((string) $row2['cp_payload'], "Product\\\\'s") === false, 'retry does not double-escape');

// Unescaped path would fail against quoting Db
$rawFail = false;
try {
    aposMaterializeDbValue("Test Product's Name");
} catch (PrestaShopDatabaseException $e) {
    $rawFail = true;
}
assertApos($rawFail, 'quoting Db detects unescaped apostrophe');

// Snapshot update free-text status with apostrophe
$snapshots->update(42, [
    'lifecycle_status' => OrderOrchestrator::CP_CREATED,
    'cp_status_sync_status' => "Bank's status",
    'cp_status_sync_status_id' => 'bank_sent_process1',
]);
assertApos(($db->snapshotRows[42]['cp_status_sync_status'] ?? null) === "Bank's status", 'snapshot update preserves status apostrophe');

fwrite(STDOUT, "OK (apostrophe SQL escaping persistence)\n");
