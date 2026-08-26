<?php

declare(strict_types=1);

/**
 * Atomic attachOrderIfReserved: reserved → ps_order_created with expected-state guard.
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

use PrestaShop\Module\Unipayment\Order\OrderAttemptRepository;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrator;

function assertAttach(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class AttachOrderFakeDb
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    public int $affectedRows = 0;
    public string $lastSql = '';

    public function execute(string $sql): bool
    {
        $this->lastSql = $sql;
        $this->affectedRows = 0;

        if (preg_match(
            '/UPDATE `[^`]+`\s+SET `state` = \'([^\']+)\',\s*`id_order` = (\d+),\s*`order_reference` = \'([^\']+)\',\s*`updated_at` = \'([^\']+)\'\s+WHERE `id_attempt` = (\d+)\s+AND `state` = \'reserved\'\s+AND \(`id_order` IS NULL OR `id_order` = 0\)/s',
            $sql,
            $m
        )) {
            $id = (int) $m[5];
            if (!isset($this->rows[$id])) {
                return true;
            }
            $row = $this->rows[$id];
            if ((string) $row['state'] === 'reserved' && (int) ($row['id_order'] ?? 0) <= 0) {
                $this->rows[$id]['state'] = $m[1];
                $this->rows[$id]['id_order'] = (int) $m[2];
                $this->rows[$id]['order_reference'] = $m[3];
                $this->rows[$id]['updated_at'] = $m[4];
                $this->affectedRows = 1;
            }

            return true;
        }

        return true;
    }

    /** @return array<string, mixed>|false */
    public function getRow(string $sql)
    {
        if (!preg_match('/WHERE `id_attempt`=(\d+)/', $sql, $m)) {
            return false;
        }
        $id = (int) $m[1];

        return $this->rows[$id] ?? false;
    }

    public function Affected_Rows(): int
    {
        return $this->affectedRows;
    }

    public function update(string $table, array $data, string $where): bool
    {
        unset($table, $data, $where);

        return true;
    }
}

$db = new AttachOrderFakeDb();
$db->rows[1] = [
    'id_attempt' => 1,
    'id_shop' => 1,
    'id_cart' => 10,
    'cart_fingerprint' => str_repeat('a', 64),
    'state' => OrderOrchestrator::RESERVED,
    'id_order' => null,
    'order_reference' => null,
];
$repo = new OrderAttemptRepository($db);
$attached = $repo->attachOrderIfReserved(1, 55, 'ABCD123456789EXTRA');
assertAttach((string) $attached['state'] === OrderOrchestrator::PS_ORDER_CREATED, 'must transition to ps_order_created');
assertAttach((int) $attached['id_order'] === 55, 'must persist id_order');
assertAttach((string) $attached['order_reference'] === 'ABCD123456789', 'reference truncated to 13');
assertAttach(strpos($db->lastSql, "state` = 'reserved'") !== false, 'UPDATE must guard reserved state');
assertAttach(strpos($db->lastSql, '`id_order` IS NULL OR `id_order` = 0') !== false, 'UPDATE must guard empty id_order');

$same = $repo->attachOrderIfReserved(1, 55, 'ABCD123456789');
assertAttach((int) $same['id_order'] === 55, 'idempotent same-order attach must succeed');

$conflictThrown = false;
try {
    $repo->attachOrderIfReserved(1, 99, 'OTHEROTHEROTH');
} catch (RuntimeException $e) {
    $conflictThrown = true;
}
assertAttach($conflictThrown, 'conflicting order attach must fail');

$db2 = new AttachOrderFakeDb();
$db2->rows[2] = [
    'id_attempt' => 2,
    'state' => OrderOrchestrator::CP_SUBMITTING,
    'id_order' => null,
];
$repo2 = new OrderAttemptRepository($db2);
$nonReservedThrown = false;
try {
    $repo2->attachOrderIfReserved(2, 55, 'ABCD123456789');
} catch (RuntimeException $e) {
    $nonReservedThrown = true;
}
assertAttach($nonReservedThrown, 'non-reserved state must not attach');

fwrite(STDOUT, "OK (attachOrderIfReserved atomic transition)\n");
