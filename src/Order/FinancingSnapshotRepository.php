<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

final class FinancingSnapshotRepository implements FinancingSnapshotStoreInterface, FinancingSnapshotByOrderReaderPort, ControlPanelStatusSyncStoreInterface
{
    public const TABLE = 'unipayment_financing_snapshot';
    /** Matches PrestaShop {@see \Db}::INSERT_IGNORE for idempotent snapshot persistence. */
    private const INSERT_IGNORE = 2;
    /** @var \Db|object */
    private $database;

    /**
     * @param \Db|object|null $database
     */
    public function __construct($database = null)
    {
        $this->database = $database ?? \Db::getInstance();
    }

    public function install(): bool
    {
        return (bool) $this->database->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE . '` (
            `id_snapshot` INT UNSIGNED NOT NULL AUTO_INCREMENT, `id_attempt` INT UNSIGNED NOT NULL, `id_order` INT UNSIGNED NOT NULL,
            `order_reference` VARCHAR(13) NOT NULL, `cart_fingerprint` CHAR(64) NOT NULL, `scheme_type` VARCHAR(16) NOT NULL,
            `scheme_key` VARCHAR(64) NOT NULL, `kop_code` VARCHAR(64) NOT NULL, `months` SMALLINT UNSIGNED NOT NULL, `filter_id` INT UNSIGNED NOT NULL,
            `first_installment` DECIMAL(20,6) NOT NULL, `financed_amount` DECIMAL(20,6) NOT NULL, `monthly_installment` DECIMAL(20,6) NOT NULL,
            `total_payable` DECIMAL(20,6) NOT NULL, `glp` DECIMAL(20,6) NOT NULL, `gpr` DECIMAL(20,6) NOT NULL, `coefficient` DECIMAL(20,10) NOT NULL,
            `order_total` DECIMAL(20,6) NOT NULL, `currency_iso` CHAR(3) NOT NULL, `id_currency` INT UNSIGNED NOT NULL,
            `module_version` VARCHAR(11) NOT NULL, `submission_source` VARCHAR(32) NOT NULL,
            `customer_json` LONGTEXT NOT NULL, `address_json` LONGTEXT NOT NULL, `lines_json` LONGTEXT NOT NULL, `consents_json` LONGTEXT NOT NULL,
            `sensitive_payload` LONGTEXT NULL, `control_panel_order_id` BIGINT UNSIGNED NULL, `lifecycle_status` VARCHAR(32) NOT NULL, `leasing_email_sent` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `smartucf_state` VARCHAR(32) NOT NULL DEFAULT \'not_started\',
            `smartucf_session_id` VARCHAR(128) NULL,
            `smartucf_redirect_url` VARCHAR(768) NULL,
            `smartucf_http_code` SMALLINT NULL,
            `smartucf_error_class` VARCHAR(64) NULL,
            `smartucf_retryable` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `smartucf_claimed_at` DATETIME NULL,
            `smartucf_completed_at` DATETIME NULL,
            `cp_status_sync_state` VARCHAR(32) NOT NULL DEFAULT \'not_needed\',
            `cp_status_sync_status_id` VARCHAR(255) NULL,
            `cp_status_sync_status` VARCHAR(255) NULL,
            `cp_status_sync_error_class` VARCHAR(64) NULL,
            `cp_status_sync_updated_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL, PRIMARY KEY (`id_snapshot`),
            UNIQUE KEY `uniq_unipayment_snapshot_attempt` (`id_attempt`), UNIQUE KEY `uniq_unipayment_snapshot_order` (`id_order`),
            KEY `idx_unipayment_snapshot_smartucf_state` (`smartucf_state`, `smartucf_claimed_at`),
            KEY `idx_unipayment_snapshot_cp_status_sync` (`cp_status_sync_state`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function uninstall(): bool
    {
        return (bool) $this->database->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::TABLE . '`');
    }

    public function save(int $attemptId, array $snapshot): void
    {
        $values = $snapshot;
        $values['id_attempt'] = $attemptId;
        foreach (['customer_json', 'address_json', 'lines_json', 'consents_json'] as $key) {
            $values[$key] = json_encode($values[$key] ?? [], JSON_THROW_ON_ERROR);
        }
        $values['created_at'] = $values['created_at'] ?? gmdate('Y-m-d H:i:s');
        $values['updated_at'] = gmdate('Y-m-d H:i:s');
        $values = $this->normalizeCpSyncNullableFieldsForInsert($values);
        // PrestaShop Db::insert does not quote-escape string values. Apostrophes in
        // product names / addresses / consent text break INSERT without pSQL().
        $values = $this->prepareInsertValues($values);
        if (!$this->database->insert(self::TABLE, $values, false, true, self::INSERT_IGNORE) && $this->findByAttempt($attemptId) === null) {
            throw new \RuntimeException('The financing snapshot could not be stored.');
        }
    }

    public function findByAttempt(int $attemptId): ?array
    {
        $row = $this->database->getRow('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_attempt`=' . $attemptId);
        if (!is_array($row)) return null;
        foreach (['customer_json', 'address_json', 'lines_json', 'consents_json'] as $key) {
            $decoded = json_decode((string) $row[$key], true);
            $row[$key] = is_array($decoded) ? $decoded : [];
        }

        return $row;
    }

    public function findByOrderId(int $idOrder): ?array
    {
        if ($idOrder <= 0) {
            return null;
        }
        $row = $this->database->getRow('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_order`=' . $idOrder);
        if (!is_array($row)) return null;
        foreach (['customer_json', 'address_json', 'lines_json', 'consents_json'] as $key) {
            $decoded = json_decode((string) $row[$key], true);
            $row[$key] = is_array($decoded) ? $decoded : [];
        }

        return $row;
    }

    public function update(int $attemptId, array $changes): void
    {
        $allowed = [
            'control_panel_order_id',
            'lifecycle_status',
            'leasing_email_sent',
            'cp_status_sync_state',
            'cp_status_sync_status_id',
            'cp_status_sync_status',
            'cp_status_sync_error_class',
            'cp_status_sync_updated_at',
        ];
        $data = [];
        foreach ($changes as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $data[$key] = $value;
            }
        }
        $data['updated_at'] = gmdate('Y-m-d H:i:s');
        // Same Db::update quoting gap as insert — escape free-text status fields at the SQL boundary.
        $data = $this->prepareInsertValues($data);
        if (!$this->database->update(self::TABLE, $data, '`id_attempt`=' . $attemptId, 0, false)) {
            throw new \RuntimeException('The financing snapshot could not be updated.');
        }
    }

    public function redactExpiredPii(string $cutoffDatetime, int $limit): int
    {
        $limit = max(1, min(500, $limit));
        $table = _DB_PREFIX_ . self::TABLE;
        $emptyJson = '{}';
        $updatedAt = gmdate('Y-m-d H:i:s');

        $this->database->execute(
            'UPDATE `' . $table . '`
            SET `customer_json` = \'' . pSQL($emptyJson) . '\',
                `address_json` = \'' . pSQL($emptyJson) . '\',
                `sensitive_payload` = NULL,
                `updated_at` = \'' . pSQL($updatedAt) . '\'
            WHERE `created_at` < \'' . pSQL($cutoffDatetime) . '\'
              AND (
                `customer_json` NOT IN (\'' . pSQL($emptyJson) . '\', \'[]\', \'\')
                OR `address_json` NOT IN (\'' . pSQL($emptyJson) . '\', \'[]\', \'\')
                OR `sensitive_payload` IS NOT NULL
              )
            LIMIT ' . (int) $limit
        );

        return (int) $this->database->Affected_Rows();
    }

    public function compareAndSetPendingTarget(
        int $attemptId,
        string $expectedState,
        ?string $expectedStatusId,
        ?string $expectedStatus,
        string $newStatusId,
        string $newStatus
    ): bool {
        $now = gmdate('Y-m-d H:i:s');
        $sql = sprintf(
            "UPDATE `%s%s` SET
                `cp_status_sync_state` = '%s',
                `cp_status_sync_status_id` = '%s',
                `cp_status_sync_status` = '%s',
                `cp_status_sync_error_class` = NULL,
                `cp_status_sync_updated_at` = '%s',
                `updated_at` = '%s'
             WHERE `id_attempt` = %d
               AND `cp_status_sync_state` = '%s'
               AND %s
               AND %s",
            _DB_PREFIX_,
            self::TABLE,
            pSQL(ControlPanelStatusSyncStates::PENDING),
            pSQL($newStatusId),
            pSQL($newStatus, true),
            pSQL($now),
            pSQL($now),
            $attemptId,
            pSQL($expectedState),
            $this->nullSafeEqualsSql('cp_status_sync_status_id', $expectedStatusId),
            $this->nullSafeEqualsSql('cp_status_sync_status', $expectedStatus, true)
        );

        if (!$this->database->execute($sql)) {
            return false;
        }

        return (int) $this->database->Affected_Rows() > 0;
    }

    public function compareAndSetConfirmed(
        int $attemptId,
        string $expectedStatusId,
        string $expectedStatus
    ): bool {
        $now = gmdate('Y-m-d H:i:s');
        $sql = sprintf(
            "UPDATE `%s%s` SET
                `cp_status_sync_state` = '%s',
                `cp_status_sync_status_id` = '%s',
                `cp_status_sync_status` = '%s',
                `cp_status_sync_error_class` = NULL,
                `cp_status_sync_updated_at` = '%s',
                `updated_at` = '%s'
             WHERE `id_attempt` = %d
               AND `cp_status_sync_state` = '%s'
               AND `cp_status_sync_status_id` = '%s'
               AND `cp_status_sync_status` = '%s'",
            _DB_PREFIX_,
            self::TABLE,
            pSQL(ControlPanelStatusSyncStates::CONFIRMED),
            pSQL($expectedStatusId),
            pSQL($expectedStatus, true),
            pSQL($now),
            pSQL($now),
            $attemptId,
            pSQL(ControlPanelStatusSyncStates::PENDING),
            pSQL($expectedStatusId),
            pSQL($expectedStatus, true)
        );

        if (!$this->database->execute($sql)) {
            return false;
        }

        return (int) $this->database->Affected_Rows() > 0;
    }

    public function compareAndSetFailure(
        int $attemptId,
        string $expectedStatusId,
        string $expectedStatus,
        string $newState,
        string $errorClass
    ): bool {
        if (!in_array($newState, [ControlPanelStatusSyncStates::PENDING, ControlPanelStatusSyncStates::TERMINAL_FAILED], true)) {
            return false;
        }

        $now = gmdate('Y-m-d H:i:s');
        $sql = sprintf(
            "UPDATE `%s%s` SET
                `cp_status_sync_state` = '%s',
                `cp_status_sync_error_class` = '%s',
                `cp_status_sync_updated_at` = '%s',
                `updated_at` = '%s'
             WHERE `id_attempt` = %d
               AND `cp_status_sync_state` = '%s'
               AND `cp_status_sync_status_id` = '%s'
               AND `cp_status_sync_status` = '%s'",
            _DB_PREFIX_,
            self::TABLE,
            pSQL($newState),
            pSQL($errorClass),
            pSQL($now),
            pSQL($now),
            $attemptId,
            pSQL(ControlPanelStatusSyncStates::PENDING),
            pSQL($expectedStatusId),
            pSQL($expectedStatus, true)
        );

        if (!$this->database->execute($sql)) {
            return false;
        }

        return (int) $this->database->Affected_Rows() > 0;
    }

    /**
     * Nullable CP sync string columns where PrestaShop insert historically persisted '' instead of SQL NULL.
     * Semantic "no value" must match both representations in CAS predicates.
     */
    private const CP_SYNC_SEMANTIC_NULL_STRING_COLUMNS = [
        'cp_status_sync_status_id',
        'cp_status_sync_status',
        'cp_status_sync_error_class',
    ];

    /**
     * Persist semantic-null CP sync string fields as SQL NULL on insert (type=sql),
     * without enabling blanket Db::$null_values for the whole row.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function normalizeCpSyncNullableFieldsForInsert(array $values): array
    {
        foreach (self::CP_SYNC_SEMANTIC_NULL_STRING_COLUMNS as $column) {
            if (!array_key_exists($column, $values)) {
                continue;
            }
            $value = $values[$column];
            if ($value === null || $value === '') {
                $values[$column] = ['type' => 'sql', 'value' => 'NULL'];
            }
        }

        return $values;
    }

    /**
     * Escape string literals for {@see \Db::insert()} / {@see \Db::update()} and map remaining PHP nulls to SQL NULL.
     * Does not enable blanket Db null conversion — only explicit null keys become SQL NULL.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function prepareInsertValues(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value) && isset($value['type']) && $value['type'] === 'sql') {
                continue;
            }
            if ($value === null) {
                $values[$key] = ['type' => 'sql', 'value' => 'NULL'];
                continue;
            }
            if (is_bool($value)) {
                $values[$key] = $value ? 1 : 0;
                continue;
            }
            if (is_int($value) || is_float($value)) {
                continue;
            }
            $values[$key] = $this->escapeInsertString((string) $value);
        }

        return $values;
    }

    private function escapeInsertString(string $value): string
    {
        if (function_exists('pSQL')) {
            return pSQL($value, true);
        }

        return addslashes($value);
    }

    /**
     * Exact equality for non-null expected values; NULL/'' equivalence for semantic null.
     */
    private function nullSafeEqualsSql(string $column, ?string $value, bool $htmlOk = false): string
    {
        if ($value === null || $value === '') {
            return $this->semanticNullEqualsSql($column);
        }

        return '`' . $column . '` <=> \'' . pSQL($value, $htmlOk) . '\'';
    }

    private function semanticNullEqualsSql(string $column): string
    {
        return '(`' . $column . '` IS NULL OR `' . $column . '` = \'\')';
    }
}
