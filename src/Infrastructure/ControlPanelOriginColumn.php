<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Infrastructure;

/** Additive lazy migration: existing durable rows retain NULL provenance. */
final class ControlPanelOriginColumn
{
    /** @var \WeakMap<object, array<string, bool>>|null */
    private static ?\WeakMap $ready = null;

    public static function ensure(object $database, string $table): void
    {
        self::$ready ??= new \WeakMap();
        $tables = self::$ready[$database] ?? [];
        if (isset($tables[$table])) {
            return;
        }
        $query = 'SHOW COLUMNS FROM `' . $table . '` LIKE \'cp_origin\'';
        if (!$database->getRow($query)) {
            try {
                $success = $database->execute('ALTER TABLE `' . $table . '` ADD `cp_origin` VARCHAR(272) NULL DEFAULT NULL');
            } catch (\Throwable $exception) {
                // Concurrent first requests may both observe the missing column.
                if (!$database->getRow($query)) {
                    throw new \RuntimeException('Control Panel provenance schema could not be upgraded.', 0, $exception);
                }
                $success = true;
            }
            if (!$success && !$database->getRow($query)) {
                throw new \RuntimeException('Control Panel provenance schema could not be upgraded.');
            }
        }
        $tables[$table] = true;
        self::$ready[$database] = $tables;
    }
}
