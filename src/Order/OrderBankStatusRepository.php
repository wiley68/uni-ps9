<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

final class OrderBankStatusRepository implements BankStatusPersistencePort, BankStatusReaderPort
{
    public const TABLE = 'unipayment_order_bank_status';

    /** @var \Db */
    private $database;

    public function __construct(?\Db $database = null)
    {
        $this->database = $database ?? \Db::getInstance();
    }

    public function install(): bool
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . $this->tableName() . '` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_order` INT UNSIGNED NOT NULL,
            `id_shop` INT UNSIGNED NOT NULL,
            `order_id` VARCHAR(64) NOT NULL,
            `status_id` VARCHAR(255) NOT NULL,
            `status_label` VARCHAR(255) NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_unipayment_bank_id_order` (`id_order`),
            KEY `idx_unipayment_bank_order_id` (`order_id`),
            KEY `idx_unipayment_bank_id_shop` (`id_shop`)
        ) ENGINE=' . constant('_MYSQL_ENGINE_') . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        return (bool) $this->database->execute($sql);
    }

    public function uninstall(): bool
    {
        return (bool) $this->database->execute('DROP TABLE IF EXISTS `' . $this->tableName() . '`');
    }

    /** @return array<string, mixed>|null */
    public function updateByOrderIdentifier(int $idShop, string $orderReference, string $statusId, string $statusLabel): ?array
    {
        $order = $this->resolveAuthorizedFinancingOrder($idShop, $orderReference);
        if ($order === null) {
            return null;
        }

        $idOrder = (int) $order['id_order'];
        $resolvedReference = (string) $order['order_reference'];
        $updatedAt = gmdate('Y-m-d H:i:s');
        $progression = new BankStatusProgression();

        // Atomic write: incompatible P1↔P2 terminal replacement keeps the existing row
        // (no read-then-unconditional-write race). Compatible / same-status updates apply.
        $p1 = pSQL(BankStatus::SENT_PROCESS1);
        $p2 = pSQL(BankStatus::SENT_PROCESS2);
        $conflictGuard = sprintf(
            "((`status_id` = '%s' AND VALUES(`status_id`) = '%s') OR (`status_id` = '%s' AND VALUES(`status_id`) = '%s'))",
            $p1,
            $p2,
            $p2,
            $p1
        );

        $saved = $this->database->execute(sprintf(
            "INSERT INTO `%s`
                (`id_order`, `id_shop`, `order_id`, `status_id`, `status_label`, `updated_at`)
             VALUES (%d, %d, '%s', '%s', '%s', '%s')
             ON DUPLICATE KEY UPDATE
                `id_shop` = IF(%s, `id_shop`, VALUES(`id_shop`)),
                `order_id` = IF(%s, `order_id`, VALUES(`order_id`)),
                `status_label` = IF(%s, `status_label`, VALUES(`status_label`)),
                `updated_at` = IF(%s, `updated_at`, VALUES(`updated_at`)),
                `status_id` = IF(%s, `status_id`, VALUES(`status_id`))",
            $this->tableName(),
            $idOrder,
            (int) $order['id_shop'],
            pSQL($resolvedReference, true),
            pSQL($statusId, true),
            pSQL($statusLabel, true),
            pSQL($updatedAt),
            $conflictGuard,
            $conflictGuard,
            $conflictGuard,
            $conflictGuard,
            $conflictGuard
        ));
        if (!$saved) {
            throw new \RuntimeException('The bank status could not be stored.');
        }

        $current = $this->findByOrderId($idOrder);
        if (!is_array($current)) {
            throw new \RuntimeException('The bank status could not be reloaded after write.');
        }

        $persistedStatusId = (string) ($current['status_id'] ?? '');
        if ($progression->isIncompatibleTerminalSentPair($persistedStatusId, $statusId)
            && $persistedStatusId !== $statusId
        ) {
            throw new OrderBankStatusSemanticConflictException(
                'Incompatible terminal bank status progression.'
            );
        }

        return [
            'order_id' => $resolvedReference,
            'ps_order_id' => $idOrder,
            'status' => (string) ($current['status_label'] ?? $statusLabel),
            'status_id' => $persistedStatusId !== '' ? $persistedStatusId : $statusId,
        ];
    }

    /** @return array<string, mixed>|null */
    public function findByOrderId(int $idOrder): ?array
    {
        if ($idOrder <= 0) {
            return null;
        }

        $row = $this->database->getRow(sprintf(
            'SELECT `order_id`, `status_id`, `status_label`, `updated_at` FROM `%s` WHERE `id_order` = %d',
            $this->tableName(),
            $idOrder
        ));

        return is_array($row) ? $row : null;
    }

    /**
     * Resolve a UniPayment financing order in the authorized shop by shop order reference.
     *
     * Incoming order_id from Control Panel is always ps_orders.reference, never id_order,
     * even when the reference consists only of digits.
     *
     * Fail-closed: 0 candidates → null; 1 → continue; 2+ → OrderBankStatusAmbiguousException.
     * Never collapses duplicates via getRow().
     *
     * @return array{id_order: int, id_shop: int, order_reference: string, smartucf_state: string}|null
     */
    public function resolveAuthorizedFinancingOrder(int $idShop, string $orderReference): ?array
    {
        if ($idShop <= 0) {
            return null;
        }

        $orderReference = trim($orderReference);
        if ($orderReference === '') {
            return null;
        }

        if (!$this->financingSnapshotTableExists()) {
            return null;
        }

        $rows = $this->database->executeS(sprintf(
            'SELECT o.`id_order`, o.`id_shop`, o.`reference`, s.`smartucf_state`
             FROM `%1$sorders` o
             INNER JOIN `%2$s` s ON s.`id_order` = o.`id_order`
             WHERE o.`reference` = \'%3$s\'
               AND o.`id_shop` = %4$d',
            _DB_PREFIX_,
            _DB_PREFIX_ . FinancingSnapshotRepository::TABLE,
            pSQL($orderReference, true),
            $idShop
        ));
        if (!is_array($rows) || $rows === []) {
            return null;
        }
        if (count($rows) > 1) {
            throw new OrderBankStatusAmbiguousException(
                'Multiple financing orders match this shop reference.'
            );
        }

        $row = $rows[0];
        if (!is_array($row)) {
            return null;
        }

        return [
            'id_order' => (int) $row['id_order'],
            'id_shop' => (int) $row['id_shop'],
            'order_reference' => (string) $row['reference'],
            'smartucf_state' => (string) ($row['smartucf_state'] ?? 'not_started'),
        ];
    }

    private function financingSnapshotTableExists(): bool
    {
        $table = _DB_PREFIX_ . FinancingSnapshotRepository::TABLE;
        $rows = $this->database->executeS('SHOW TABLES LIKE "' . pSQL($table) . '"');

        return is_array($rows) && $rows !== [];
    }

    private function tableName(): string
    {
        return _DB_PREFIX_ . self::TABLE;
    }
}
