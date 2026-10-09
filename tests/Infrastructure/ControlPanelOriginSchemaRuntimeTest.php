<?php

declare(strict_types=1);

// Opt-in: native Db driver and real SQL, only connection-local TEMPORARY tables.
if (getenv('UNIPAYMENT_ORIGIN_SCHEMA_RUNTIME') !== '1') {
    echo "SKIP (origin schema runtime: opt-in temporary-table integration)\n";
    exit(0);
}
$root = dirname(__DIR__, 2);
$shop = dirname($root, 2);
$core = $argv[1] ?? $shop . '/classes/db';
require $root . '/vendor/autoload.php';
require $shop . '/classes/exception/PrestaShopException.php';
class_alias(PrestaShopExceptionCore::class, 'PrestaShopException');
require $shop . '/classes/exception/PrestaShopDatabaseException.php';
class_alias(PrestaShopDatabaseExceptionCore::class, 'PrestaShopDatabaseException');
require $core . '/Db.php';
class_alias(DbCore::class, 'Db');
require $core . '/DbPDO.php';
define('_PS_ALLOW_MULTI_STATEMENTS_QUERIES_', false);
define('_PS_CACHE_ENABLED_', false);
define('_MYSQL_ENGINE_', 'InnoDB');
define('_DB_PREFIX_', 'origin_schema_' . bin2hex(random_bytes(6)) . '_');

use PrestaShop\Module\Unipayment\Order\FinancingSnapshotRepository;
use PrestaShop\Module\Unipayment\Order\OrderAttemptRepository;

function assertSchema(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

final class NativeOriginSchemaDatabase extends DbPDOCore
{
    public bool $legacy = false;
    public array $sql = [];
    public array $legacyRows = [];

    public function disconnect(): void
    {
        $this->link = null;
    }

    protected function _query(mixed $sql): PDOStatement|false
    {
        $this->sql[] = $sql;
        assertSchema(!preg_match('/^SHOW\s+(COLUMNS|FIELDS|INDEX|KEYS).*\bLIMIT\b/is', $sql), 'invalid SHOW LIMIT reached native transport');
        if (str_starts_with($sql, 'CREATE TABLE IF NOT EXISTS')) {
            preg_match('/CREATE TABLE IF NOT EXISTS `([^`]+)`/', $sql, $match);
            $table = $match[1];
            assertSchema(str_starts_with($table, _DB_PREFIX_), 'test may only create isolated tables');
            $statement = str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE IF NOT EXISTS', $sql);
            if ($this->legacy) { $statement = preg_replace('/^\s*`cp_origin`[^\n]+\n/m', '', $statement); }
            $result = parent::_query($statement);
            if ($this->legacy && !isset($this->legacyRows[$table])) {
                $columns = parent::_query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
                $fields = [];
                $values = [];
                foreach ($columns as $column) {
                    if (str_contains($column['Extra'], 'auto_increment')) { continue; }
                    $fields[] = '`' . $column['Field'] . '`';
                    $type = strtolower($column['Type']);
                    $value = preg_match('/int|decimal/', $type) ? '1' : (str_contains($type, 'datetime') ? '2026-10-09 00:00:00' : 'legacy');
                    if ($column['Field'] === 'cp_payload') { $value = 'frozen-history'; }
                    if ($column['Field'] === 'control_panel_order_id') { $value = '41'; }
                    $values[] = $this->link->quote($value);
                }
                parent::_query('INSERT INTO `' . $table . '` (' . implode(',', $fields) . ') VALUES (' . implode(',', $values) . ')');
                $this->legacyRows[$table] = parent::_query('SELECT * FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
            }
            return $result;
        }
        return parent::_query($sql);
    }
}

// Read deployment DB credentials in memory only; never bootstrap the shop or print them.
$configuration = require ($argv[2] ?? $shop . '/app/config/parameters.php');
$parameters = $configuration['parameters'];
$host = (string) $parameters['database_host'];
if (!empty($parameters['database_port'])) { $host .= ':' . (int) $parameters['database_port']; }
try {
    foreach (['fresh', 'legacy'] as $case) {
        $database = new NativeOriginSchemaDatabase($host, $parameters['database_user'], $parameters['database_password'], $parameters['database_name']);
        $database->legacy = $case === 'legacy';
        foreach ([OrderAttemptRepository::class, FinancingSnapshotRepository::class] as $repositoryClass) {
            $repository = new $repositoryClass($database);
            assertSchema($repository->install(), 'native repository install');
            $table = _DB_PREFIX_ . $repositoryClass::TABLE;
            $columns = $database->executeS("SHOW COLUMNS FROM `{$table}` LIKE 'cp_origin'", true, false);
            assertSchema(count($columns) === 1 && $columns[0]['Null'] === 'YES' && $columns[0]['Default'] === null, 'nullable NULL origin');
            if ($case === 'legacy') {
                $rows = $database->executeS('SELECT * FROM `' . $table . '`', true, false);
                foreach ($rows as &$row) { assertSchema($row['cp_origin'] === null, 'legacy origin must not be backfilled'); unset($row['cp_origin']); }
                unset($row);
                assertSchema($rows === $database->legacyRows[$table], 'history preserved by real ALTER');
            }
            $before = count(array_filter($database->sql, static fn (string $sql): bool => str_starts_with($sql, 'ALTER')));
            assertSchema($repository->install(), 'repeated install');
            // A new adapter object models a later request, bypassing the WeakMap memoization.
            $nextRequest = new class($database) {
                private NativeOriginSchemaDatabase $database;
                public function __construct(NativeOriginSchemaDatabase $database) { $this->database = $database; }
                public function executeS(string $sql, bool $array = true, bool $useCache = true): array|false { return $this->database->executeS($sql, $array, $useCache); }
                public function execute(string $sql): bool { return $this->database->execute($sql); }
            };
            \PrestaShop\Module\Unipayment\Infrastructure\ControlPanelOriginColumn::ensure($nextRequest, $table);
            assertSchema($before === count(array_filter($database->sql, static fn (string $sql): bool => str_starts_with($sql, 'ALTER'))), 'existing origin does not ALTER again');
        }
        echo 'OK (' . $case . ' + repeated native schema install; ' . $database->getVersion() . ")\n";
        $database->disconnect(); // Server removes only these connection-local temporary tables.
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL (native origin schema integration; ' . get_class($exception) . ")\n");
    exit(1);
}
