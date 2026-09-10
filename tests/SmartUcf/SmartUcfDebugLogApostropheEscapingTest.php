<?php

declare(strict_types=1);

/**
 * SmartUcfDebugLogRepository: apostrophe / free-text SQL escaping at Db::insert boundary (PS9).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDebugLogRepository;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDebugLogStoreInterface;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDiagnosticJournal;

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}
if (!defined('_MYSQL_ENGINE_')) {
    define('_MYSQL_ENGINE_', 'InnoDB');
}

if (!function_exists('pSQL')) {
    /**
     * @param mixed $string
     */
    function pSQL($string, $htmlOK = false): string
    {
        unset($htmlOK);

        return str_replace(
            ['\\', "\0", "\n", "\r", "'", '"', "\x1a"],
            ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'],
            (string) $string
        );
    }
}

if (!class_exists('PrestaShopDatabaseException', false)) {
    class PrestaShopDatabaseException extends \RuntimeException
    {
    }
}

if (!class_exists('Configuration', false)) {
    class Configuration
    {
        /** @var array<string, mixed> */
        public static $values = [];

        /** @param mixed $idLang @param mixed $idShopGroup @param mixed $idShop @param mixed $default @return mixed */
        public static function get(string $key, $idLang = null, $idShopGroup = null, $idShop = null, $default = false)
        {
            return self::$values[$key] ?? $default;
        }
    }
}

function assertDebugApos9(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function debugApos9AssertSqlTextSafe(string $value): void
{
    $length = strlen($value);
    for ($i = 0; $i < $length; ++$i) {
        if ($value[$i] === '\\' && $i + 1 < $length) {
            ++$i;
            continue;
        }
        if ($value[$i] === "'") {
            throw new PrestaShopDatabaseException("SQL syntax near '" . substr($value, $i, 24) . "'");
        }
    }
}

function debugApos9UnescapeSqlText(string $escaped): string
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
function debugApos9MaterializeDbValue($value)
{
    if (is_array($value) && isset($value['type']) && $value['type'] === 'sql') {
        if (($value['value'] ?? null) === 'NULL') {
            return null;
        }

        return $value['value'];
    }
    if ($value === null || is_int($value) || is_float($value) || is_bool($value)) {
        return $value;
    }
    $text = (string) $value;
    debugApos9AssertSqlTextSafe($text);

    return debugApos9UnescapeSqlText($text);
}

final class DebugLogApostropheQuotingDb9
{
    /** @var array<int, array<string, mixed>> */
    public $rows = [];

    /** @var int */
    public $nextId = 1;

    /** @var list<array<string, mixed>> */
    public $lastInsertData = [];

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
        unset($table, $null_values, $use_cache, $type, $add_prefix);
        $this->lastInsertData[] = $data;
        $row = [];
        foreach ($data as $key => $value) {
            $row[$key] = debugApos9MaterializeDbValue($value);
        }
        $id = $this->nextId++;
        $row['id'] = $id;
        $this->rows[$id] = $row;

        return true;
    }

    public function getRow(string $sql)
    {
        if (!preg_match("/id_shop` = (\d+).*order_id` = '([^']*)'/s", $sql, $match)
            && !preg_match("/`id_shop` = (\d+) AND `order_id` = '([^']*)'/", $sql, $match)
        ) {
            return false;
        }
        $idShop = (int) $match[1];
        $orderId = $match[2];
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

    public function execute(string $sql): bool
    {
        unset($sql);

        return true;
    }

    /** @return list<array<string, mixed>> */
    public function executeS(string $sql)
    {
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

$productName = "Test Product's Name";
$bankResponse = "Bank's response";
$transportError = "Connection failed at bank's gateway";

$db = new DebugLogApostropheQuotingDb9();
$repo = new SmartUcfDebugLogRepository($db);

assertDebugApos9($repo->install(), 'install ok');
assertDebugApos9($repo->insert([
    'id_shop' => 1,
    'ps_order_id' => 501,
    'order_id' => 'DBGAPOS001',
    'http_code' => 200,
    'request' => ['products_name' => $productName],
    'response' => ['message' => $bankResponse],
    'transport_error' => $transportError,
    'created_at_gmt' => '2026-09-10 12:00:00',
]), 'A/B/C: insert with apostrophes succeeds');

$loaded = $repo->findLatestByOrderIdAndShop('DBGAPOS001', 1);
assertDebugApos9(is_array($loaded), 'row reloadable');
assertDebugApos9(($loaded['request']['products_name'] ?? null) === $productName, 'A: request_json product apostrophe round-trip');
assertDebugApos9(($loaded['response']['message'] ?? null) === $bankResponse, 'B: response_json apostrophe round-trip');
assertDebugApos9(($loaded['transport_error'] ?? null) === $transportError, 'C: transport_error apostrophe round-trip');
assertDebugApos9(
    strpos((string) ($db->lastInsertData[0]['request_json'] ?? ''), "Product\\'s Name") !== false,
    'request_json SQL-escaped before insert'
);
assertDebugApos9(
    strpos((string) ($db->lastInsertData[0]['response_json'] ?? ''), "Bank\\'s response") !== false,
    'response_json SQL-escaped before insert'
);
assertDebugApos9(
    strpos((string) ($db->lastInsertData[0]['transport_error'] ?? ''), "bank\\'s gateway") !== false,
    'transport_error SQL-escaped before insert'
);

assertDebugApos9($repo->insert([
    'id_shop' => 1,
    'ps_order_id' => 501,
    'order_id' => 'DBGAPOS001',
    'http_code' => 200,
    'request' => ['products_name' => $productName],
    'response' => ['message' => $bankResponse],
    'transport_error' => $transportError,
    'created_at_gmt' => '2026-09-10 12:01:00',
]), 'D: second insert succeeds');
$loaded2 = $repo->findLatestByOrderIdAndShop('DBGAPOS001', 1);
assertDebugApos9(($loaded2['request']['products_name'] ?? null) === $productName, 'D: retry preserves request semantic');
assertDebugApos9(($loaded2['response']['message'] ?? null) === $bankResponse, 'D: retry preserves response semantic');
assertDebugApos9(($loaded2['transport_error'] ?? null) === $transportError, 'D: retry preserves transport_error semantic');
assertDebugApos9(strpos((string) json_encode($loaded2['request']), 'Product\\\\') === false, 'D: no double escaping in decoded request');

$rawFail = false;
try {
    debugApos9MaterializeDbValue("Test Product's Name");
} catch (PrestaShopDatabaseException $e) {
    $rawFail = true;
}
assertDebugApos9($rawFail, 'quoting Db rejects unescaped apostrophe');

Configuration::$values[ConfigurationRepository::DEBUG_ENABLED] = true;
$memoryRows = [];
$memoryStore = new class($memoryRows) implements SmartUcfDebugLogStoreInterface {
    /** @var list<array<string, mixed>> */
    public $entries;

    /** @param list<array<string, mixed>> $entries */
    public function __construct(array &$entries)
    {
        $this->entries = &$entries;
    }

    public function insert(array $entry): bool
    {
        $entry['id'] = count($this->entries) + 1;
        $this->entries[] = $entry;

        return true;
    }

    public function findLatestByOrderIdAndShop(string $orderId, int $idShop): ?array
    {
        return null;
    }

    public function findAll(): array
    {
        return $this->entries;
    }

    public function prune(?\DateTimeImmutable $now = null): bool
    {
        return true;
    }
};
$journal = new SmartUcfDiagnosticJournal(new ConfigurationRepository(), $memoryStore);
assertDebugApos9($journal->record(1, 9, 'REDACT1', 200, [
    'products_name' => $productName,
    'password' => 'secret-pass',
    'egn' => '1234567890',
], ['message' => $bankResponse], $transportError), 'E: journal record succeeds');
$entry = $memoryStore->entries[0];
assertDebugApos9(($entry['request']['products_name'] ?? null) === $productName, 'E: product name kept through sanitization');
assertDebugApos9(($entry['request']['password'] ?? null) === '[REDACTED]', 'E: password redacted');
assertDebugApos9(($entry['request']['egn'] ?? null) === '[REDACTED]', 'E: egn redacted');

$coordinator = (string) file_get_contents($root . '/src/SmartUcf/SmartUcfSessionCoordinator.php');
assertDebugApos9(
    preg_match('/function logSession[\s\S]*catch \(\\\\Throwable \$e\)[\s\S]*\/\/ Diagnostic only/m', $coordinator) === 1,
    'F: logSession isolates diagnostic failures'
);
assertDebugApos9(
    preg_match('/function logFailure[\s\S]*catch \(\\\\Throwable \$e\)[\s\S]*\/\/ Diagnostic only/m', $coordinator) === 1,
    'F: logFailure isolates diagnostic failures'
);

fwrite(STDOUT, "OK (SmartUCF debug log apostrophe SQL escaping)\n");
