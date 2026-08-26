<?php

declare(strict_types=1);

/**
 * AUD-020: SmartUCF diagnostic lookup is shop-scoped (two-shop matrix).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}
if (!defined('_MYSQL_ENGINE_')) {
    define('_MYSQL_ENGINE_', 'InnoDB');
}
if (!function_exists('pSQL')) {
    function pSQL(string $string, bool $htmlOK = false): string
    {
        unset($htmlOK);

        return addslashes($string);
    }
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDebugLogRepository;

function assertAud020(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class FakeSmartUcfDb
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    public int $nextId = 1;
    /** @var list<string> */
    public array $sql = [];

    public function execute(string $sql): bool
    {
        $this->sql[] = $sql;
        if (stripos($sql, 'CREATE TABLE') !== false || stripos($sql, 'DROP TABLE') !== false) {
            return true;
        }
        if (stripos($sql, 'DELETE FROM') !== false && preg_match("/created_at` < '([^']+)'/", $sql, $m)) {
            foreach ($this->rows as $id => $row) {
                if ((string) $row['created_at'] < $m[1]) {
                    unset($this->rows[$id]);
                }
            }
        }

        return true;
    }

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data): bool
    {
        unset($table);
        $id = $this->nextId++;
        $row = $data;
        $row['id'] = $id;
        $this->rows[$id] = $row;

        return true;
    }

    public function getRow(string $sql)
    {
        $this->sql[] = $sql;
        if (!preg_match("/id_shop` = (\d+).*order_id` = '([^']+)'/s", $sql, $m)
            && !preg_match("/`id_shop` = (\d+) AND `order_id` = '([^']+)'/", $sql, $m)
        ) {
            return false;
        }
        $idShop = (int) $m[1];
        $orderId = $m[2];
        $matches = [];
        foreach ($this->rows as $row) {
            if ((int) $row['id_shop'] === $idShop && (string) $row['order_id'] === $orderId) {
                $matches[] = $row;
            }
        }
        if ($matches === []) {
            return false;
        }
        usort($matches, static function (array $a, array $b): int {
            return (int) $b['id'] <=> (int) $a['id'];
        });

        return $matches[0];
    }

    public function executeS(string $sql)
    {
        $this->sql[] = $sql;
        if (stripos($sql, 'SHOW TABLES') !== false) {
            return [['Tables_in_db' => _DB_PREFIX_ . SmartUcfDebugLogRepository::TABLE]];
        }
        $rows = array_values($this->rows);
        usort($rows, static function (array $a, array $b): int {
            return (int) $a['id'] <=> (int) $b['id'];
        });

        return $rows;
    }
}

$db = new FakeSmartUcfDb();
$repo = new SmartUcfDebugLogRepository($db);
assertAud020($repo->install(), 'install must succeed');

$sameRef = 'SAME_REF';
assertAud020($repo->insert([
    'id_shop' => 10,
    'ps_order_id' => 101,
    'order_id' => $sameRef,
    'http_code' => 200,
    'request' => ['shop' => 'A'],
    'response' => ['log' => 'A'],
    'created_at_gmt' => '2026-08-01 10:00:00',
]), 'insert LOG_A');
assertAud020($repo->insert([
    'id_shop' => 20,
    'ps_order_id' => 202,
    'order_id' => $sameRef,
    'http_code' => 200,
    'request' => ['shop' => 'B'],
    'response' => ['log' => 'B'],
    'created_at_gmt' => '2026-08-01 11:00:00',
]), 'insert newer LOG_B');

// A: shop A → LOG_A only
$logA = $repo->findLatestByOrderIdAndShop($sameRef, 10);
assertAud020(is_array($logA) && (int) $logA['ps_order_id'] === 101, 'shop A receives LOG_A');
assertAud020((int) ($logA['id_shop'] ?? 0) === 10, 'LOG_A id_shop');

// B: shop B → LOG_B only
$logB = $repo->findLatestByOrderIdAndShop($sameRef, 20);
assertAud020(is_array($logB) && (int) $logB['ps_order_id'] === 202, 'shop B receives LOG_B');

// D: newer LOG_B must not become shop A's latest
assertAud020((int) $logA['ps_order_id'] === 101, 'shop A must not get globally latest LOG_B');

// C: shop A where only shop B has reference → not found
$db2 = new FakeSmartUcfDb();
$repo2 = new SmartUcfDebugLogRepository($db2);
$repo2->install();
$repo2->insert([
    'id_shop' => 20,
    'ps_order_id' => 202,
    'order_id' => $sameRef,
    'http_code' => 200,
    'request' => [],
    'response' => [],
    'created_at_gmt' => '2026-08-01 11:00:00',
]);
assertAud020($repo2->findLatestByOrderIdAndShop($sameRef, 10) === null, 'shop A must not see shop B-only row');

// Fail closed without id_shop
assertAud020(!$repo->insert([
    'ps_order_id' => 1,
    'order_id' => 'NO-SHOP',
    'http_code' => 200,
    'request' => [],
    'response' => [],
]), 'insert without id_shop must fail closed');

assertAud020($repo->findLatestByOrderIdAndShop($sameRef, 0) === null, 'id_shop 0 lookup must be null');

// G: controller must not call global lookup
$controller = (string) file_get_contents(dirname(__DIR__, 2) . '/controllers/front/smartucfdebuglog.php');
assertAud020(strpos($controller, 'findLatestByOrderIdAndShop') !== false, 'controller uses shop-scoped lookup');
assertAud020(!preg_match('/findLatestByOrderId\s*\(/', $controller), 'controller must not call global findLatestByOrderId');
assertAud020(strpos($controller, 'context->shop->id') !== false, 'controller resolves shop from context');
assertAud020(strpos($controller, "payload['id_shop']") === false, 'controller must not trust request id_shop');

$interface = (string) file_get_contents(dirname(__DIR__, 2) . '/src/SmartUcf/SmartUcfDebugLogStoreInterface.php');
assertAud020(
    strpos($interface, 'findLatestByOrderIdAndShop') !== false
        && !preg_match('/function\s+findLatestByOrderId\s*\(/', $interface),
    'store interface exposes only shop-scoped lookup'
);

$schema = (string) file_get_contents(dirname(__DIR__, 2) . '/src/SmartUcf/SmartUcfDebugLogRepository.php');
assertAud020(strpos($schema, 'idx_unipayment_smartucf_shop_order') !== false, 'index supports shop+order+latest');

fwrite(STDOUT, "OK (AUD-020 SmartUCF diagnostic shop isolation)\n");
