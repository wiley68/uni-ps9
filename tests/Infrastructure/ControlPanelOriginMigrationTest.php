<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/Support/ControlPanelDoubles.php';

use PrestaShop\Module\Unipayment\Infrastructure\ControlPanelOriginColumn;

final class OriginMigrationDatabase
{
    public bool $exists = false;
    public bool $concurrent = false;
    public bool $fail = false;
    public array $queries = [];
    public array $rows = [['id_attempt' => 17, 'cp_payload' => 'frozen-history', 'control_panel_order_id' => 41]];
    public bool $inspectionFails = false;
    public function getRow(string $sql): array|false
    {
        // Model the actual PrestaShop API bug, so using getRow regresses the test.
        $this->queries[] = rtrim($sql, " \t\n\r\0\x0B;") . ' LIMIT 1';
        throw new RuntimeException('SHOW COLUMNS cannot use Db::getRow().');
    }
    public function executeS(string $sql, bool $array = true, bool $useCache = true): array|false
    {
        $this->queries[] = $sql;
        cpAssert($array && !$useCache, 'schema probe must return rows without stale cache');
        cpAssert(!preg_match('/\bLIMIT\b/i', $sql), 'invalid LIMIT appended to SHOW');
        if ($this->inspectionFails) { return false; }
        return $this->exists ? [['Field' => 'cp_origin', 'Default' => null]] : [];
    }
    public function execute(string $sql): bool
    {
        $this->queries[] = $sql;
        if ($this->fail) { return false; }
        $this->exists = true;
        if ($this->concurrent) { throw new RuntimeException('synthetic concurrent column addition'); }
        return true;
    }
}

foreach (['ps_unipayment_order_attempt', 'ps_unipayment_financing_snapshot'] as $table) {
    foreach (['existing', 'legacy', 'concurrent', 'failure', 'inspection_failure'] as $case) {
        $database = new OriginMigrationDatabase();
        $database->exists = $case === 'existing';
        $database->concurrent = $case === 'concurrent';
        $database->fail = $case === 'failure';
        $database->inspectionFails = $case === 'inspection_failure';
        $history = $database->rows;
        if ($database->fail || $database->inspectionFails) { cpRejects(static fn () => ControlPanelOriginColumn::ensure($database, $table), 'migration/inspection failure must fail closed'); }
        else {
            ControlPanelOriginColumn::ensure($database, $table);
            $queries = $database->queries;
            ControlPanelOriginColumn::ensure($database, $table);
            cpAssert($queries === $database->queries, 'once per live DB object/table');
            cpAssert($database->exists, 'column exists');
        }
        cpAssert($database->rows === $history, 'legacy history preserved without provenance backfill');
        foreach ($database->queries as $sql) {
            cpAssert(!preg_match('/\b(DROP|TRUNCATE|DELETE|UPDATE|REPLACE)\b/i', $sql), 'additive only');
            if (str_starts_with($sql, 'ALTER')) { cpAssert(str_contains($sql, 'NULL DEFAULT NULL'), 'legacy origin remains unproven'); }
            if (str_starts_with($sql, 'SHOW')) { cpAssert($sql === "SHOW COLUMNS FROM `{$table}` LIKE 'cp_origin'", 'exact compatible SHOW syntax'); }
        }
        if ($database->inspectionFails) { cpAssert(count($database->queries) === 1, 'failed inspection must not attempt ALTER'); }
    }
}
echo "OK (lazy additive CP origin columns, concurrent migration, preserved legacy history)\n";
