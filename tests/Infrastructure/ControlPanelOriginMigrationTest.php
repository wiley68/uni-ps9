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
    public function getRow(string $sql): array|false
    {
        $this->queries[] = $sql;
        return $this->exists ? ['Field' => 'cp_origin', 'Default' => null] : false;
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
    foreach (['existing', 'legacy', 'concurrent', 'failure'] as $case) {
        $database = new OriginMigrationDatabase();
        $database->exists = $case === 'existing';
        $database->concurrent = $case === 'concurrent';
        $database->fail = $case === 'failure';
        $history = $database->rows;
        if ($database->fail) { cpRejects(static fn () => ControlPanelOriginColumn::ensure($database, $table), 'migration failure must fail closed'); }
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
        }
    }
}
echo "OK (lazy additive CP origin columns, concurrent migration, preserved legacy history)\n";
