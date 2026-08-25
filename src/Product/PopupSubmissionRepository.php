<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Product;

/**
 * Persistence for Product Popup submission tokens (AUD-002A).
 *
 * Reservation is pre-cart: UNIQUE(submission_token) is the atomic claim authority.
 */
final class PopupSubmissionRepository
{
    public const TABLE = 'unipayment_popup_submission';

    /** Issued tokens expire if never claimed. */
    public const ISSUED_TTL_SECONDS = 1800;

    /** Completed mappings stay long enough for browser replay after success. */
    public const ORDER_CREATED_TTL_SECONDS = 2592000;

    /** @var \Db|object */
    private $database;

    public function __construct($database = null)
    {
        $this->database = $database ?? \Db::getInstance();
    }

    public function install(): bool
    {
        return (bool) $this->database->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE . '` (
                `id_submission` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT UNSIGNED NOT NULL,
                `submission_token` CHAR(64) NOT NULL,
                `selection_hash` CHAR(64) NOT NULL,
                `flow` VARCHAR(32) NOT NULL,
                `state` VARCHAR(32) NOT NULL,
                `id_guest` INT UNSIGNED NULL,
                `id_customer` INT UNSIGNED NULL,
                `id_cart` INT UNSIGNED NULL,
                `id_attempt` INT UNSIGNED NULL,
                `id_order` INT UNSIGNED NULL,
                `order_reference` VARCHAR(13) NULL,
                `control_panel_order_id` BIGINT UNSIGNED NULL,
                `expires_at` DATETIME NOT NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`id_submission`),
                UNIQUE KEY `uniq_popup_submission_token` (`submission_token`),
                KEY `idx_popup_submission_selection` (`id_shop`, `selection_hash`, `state`),
                KEY `idx_popup_submission_state` (`state`),
                KEY `idx_popup_submission_order` (`id_order`),
                KEY `idx_popup_submission_expires` (`expires_at`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function uninstall(): bool
    {
        return (bool) $this->database->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::TABLE . '`');
    }

    /**
     * Issues a new token or reuses an existing issued token for the same binding.
     *
     * @return array<string, mixed>
     */
    public function issueOrReuse(
        int $idShop,
        string $selectionHash,
        int $idGuest,
        int $idCustomer,
        string $preferredToken = ''
    ): array {
        $this->purgeExpired();

        $now = gmdate('Y-m-d H:i:s');
        $preferredToken = trim($preferredToken);

        if ($preferredToken !== '') {
            $existing = $this->findByToken($preferredToken);
            // Foreign preferred tokens are ignored silently (no existence oracle / no log).
            if (
                is_array($existing)
                && (string) $existing['state'] === PopupSubmissionStates::ISSUED
                && (string) $existing['selection_hash'] === $selectionHash
                && (int) $existing['id_shop'] === $idShop
                && self::identityMatches($existing, $idGuest, $idCustomer)
                && !$this->isExpired($existing, $now)
            ) {
                $this->touchIssuedExpiry((int) $existing['id_submission']);

                return $this->requireById((int) $existing['id_submission']);
            }
        }

        $reusable = $this->findReusableIssued($idShop, $selectionHash, $idGuest, $idCustomer, $now);
        if ($reusable !== null) {
            $this->touchIssuedExpiry((int) $reusable['id_submission']);

            return $this->requireById((int) $reusable['id_submission']);
        }

        $token = bin2hex(random_bytes(32));
        $expires = gmdate('Y-m-d H:i:s', time() + self::ISSUED_TTL_SECONDS);
        $sql = sprintf(
            "INSERT INTO `%s%s` (
                `id_shop`, `submission_token`, `selection_hash`, `flow`, `state`,
                `id_guest`, `id_customer`, `expires_at`, `created_at`, `updated_at`
            ) VALUES (
                %d, '%s', '%s', '%s', '%s',
                %s, %s, '%s', '%s', '%s'
            )",
            _DB_PREFIX_,
            self::TABLE,
            $idShop,
            pSQL($token),
            pSQL($selectionHash),
            pSQL(PopupSubmissionSelectionHash::FLOW_PRODUCT_POPUP),
            pSQL(PopupSubmissionStates::ISSUED),
            $idGuest > 0 ? (string) (int) $idGuest : 'NULL',
            $idCustomer > 0 ? (string) (int) $idCustomer : 'NULL',
            pSQL($expires),
            pSQL($now),
            pSQL($now)
        );
        if (!$this->database->execute($sql)) {
            throw new \RuntimeException('The popup submission token could not be issued.');
        }

        $row = $this->findByToken($token);
        if (!is_array($row)) {
            throw new \RuntimeException('The popup submission token could not be loaded after issue.');
        }

        return $row;
    }

    /** @return array<string, mixed>|null */
    public function findByToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || strlen($token) > 64) {
            return null;
        }
        $row = $this->database->getRow(
            sprintf(
                "SELECT * FROM `%s%s` WHERE `submission_token` = '%s'",
                _DB_PREFIX_,
                self::TABLE,
                pSQL($token)
            )
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Atomic issued → processing claim. Returns the row when this request won; null otherwise.
     *
     * @return array<string, mixed>|null
     */
    public function claimForProcessing(string $token): ?array
    {
        $token = trim($token);
        $now = gmdate('Y-m-d H:i:s');
        $sql = sprintf(
            "UPDATE `%s%s` SET `state` = '%s', `updated_at` = '%s'
             WHERE `submission_token` = '%s'
               AND `state` = '%s'
               AND `expires_at` > '%s'",
            _DB_PREFIX_,
            self::TABLE,
            pSQL(PopupSubmissionStates::PROCESSING),
            pSQL($now),
            pSQL($token),
            pSQL(PopupSubmissionStates::ISSUED),
            pSQL($now)
        );
        if (!$this->database->execute($sql)) {
            throw new \RuntimeException('The popup submission could not be claimed.');
        }
        if ((int) $this->database->Affected_Rows() !== 1) {
            return null;
        }

        return $this->findByToken($token);
    }

    public function attachCart(int $submissionId, int $idCart): void
    {
        if ($submissionId <= 0 || $idCart <= 0) {
            throw new \InvalidArgumentException('Invalid popup submission cart attachment.');
        }
        $now = gmdate('Y-m-d H:i:s');
        $ok = $this->database->update(
            self::TABLE,
            [
                'id_cart' => $idCart,
                'updated_at' => $now,
            ],
            '`id_submission` = ' . (int) $submissionId . " AND `state` = '" . pSQL(PopupSubmissionStates::PROCESSING) . "' AND (`id_cart` IS NULL OR `id_cart` = 0 OR `id_cart` = " . (int) $idCart . ')'
        );
        if (!$ok) {
            throw new \RuntimeException('The popup submission cart could not be persisted.');
        }
        $row = $this->requireById($submissionId);
        if ((int) ($row['id_cart'] ?? 0) !== $idCart) {
            throw new \RuntimeException('The popup submission already has a different cart.');
        }
    }

    public function markIdentityAccepted(int $submissionId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $expires = gmdate('Y-m-d H:i:s', time() + self::ISSUED_TTL_SECONDS);
        $ok = $this->database->update(
            self::TABLE,
            [
                'state' => PopupSubmissionStates::IDENTITY_ACCEPTED,
                'expires_at' => $expires,
                'updated_at' => $now,
            ],
            '`id_submission` = ' . (int) $submissionId
                . " AND `state` = '" . pSQL(PopupSubmissionStates::PROCESSING) . "'"
        );
        if (!$ok) {
            throw new \RuntimeException('The popup submission could not be marked identity_accepted.');
        }
        $row = $this->requireById($submissionId);
        if ((string) ($row['state'] ?? '') !== PopupSubmissionStates::IDENTITY_ACCEPTED) {
            throw new \RuntimeException('The popup submission identity could not be accepted.');
        }
    }

    public function markOrderCreated(
        int $submissionId,
        int $idAttempt,
        int $idOrder,
        string $orderReference,
        int $controlPanelOrderId
    ): void {
        $now = gmdate('Y-m-d H:i:s');
        $expires = gmdate('Y-m-d H:i:s', time() + self::ORDER_CREATED_TTL_SECONDS);
        $ok = $this->database->update(
            self::TABLE,
            [
                'state' => PopupSubmissionStates::ORDER_CREATED,
                'id_attempt' => $idAttempt,
                'id_order' => $idOrder,
                'order_reference' => pSQL(substr($orderReference, 0, 13)),
                'control_panel_order_id' => $controlPanelOrderId > 0 ? $controlPanelOrderId : 0,
                'expires_at' => $expires,
                'updated_at' => $now,
            ],
            '`id_submission` = ' . (int) $submissionId
        );
        if (!$ok) {
            throw new \RuntimeException('The popup submission could not be marked order_created.');
        }
    }

    public function markFailed(int $submissionId, bool $onlyIfProcessing = true): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $where = '`id_submission` = ' . (int) $submissionId;
        if ($onlyIfProcessing) {
            $where .= " AND `state` = '" . pSQL(PopupSubmissionStates::PROCESSING) . "'";
        }
        $this->database->update(
            self::TABLE,
            [
                'state' => PopupSubmissionStates::FAILED,
                'updated_at' => $now,
            ],
            $where
        );
    }

    /**
     * Revert a processing row with no cart back to issued so the shopper can fix validation errors.
     */
    public function revertProcessingWithoutCart(int $submissionId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $expires = gmdate('Y-m-d H:i:s', time() + self::ISSUED_TTL_SECONDS);
        $this->database->update(
            self::TABLE,
            [
                'state' => PopupSubmissionStates::ISSUED,
                'expires_at' => $expires,
                'updated_at' => $now,
            ],
            '`id_submission` = ' . (int) $submissionId
                . " AND `state` = '" . pSQL(PopupSubmissionStates::PROCESSING) . "'"
                . ' AND (`id_cart` IS NULL OR `id_cart` = 0)'
        );
    }

    /** @return array<string, mixed> */
    public function requireById(int $submissionId): array
    {
        $row = $this->database->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_submission` = ' . (int) $submissionId
        );
        if (!is_array($row)) {
            throw new \RuntimeException('The popup submission could not be loaded.');
        }

        return $row;
    }

    /**
     * Shared guest/customer identity check for issue reuse and apply gate.
     * NULL DB identity columns are treated as 0 (same as findReusableIssued).
     *
     * @param array<string, mixed> $row
     */
    public static function identityMatches(array $row, int $idGuest, int $idCustomer): bool
    {
        $rowGuest = (int) ($row['id_guest'] ?? 0);
        $rowCustomer = (int) ($row['id_customer'] ?? 0);

        return $rowGuest === $idGuest && $rowCustomer === $idCustomer;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function isExpired(array $row, ?string $nowUtc = null): bool
    {
        $nowUtc = $nowUtc ?? gmdate('Y-m-d H:i:s');
        $expires = (string) ($row['expires_at'] ?? '');
        if ($expires === '') {
            return true;
        }
        // Completed orders remain replayable; expires_at is retention hint only.
        if ((string) ($row['state'] ?? '') === PopupSubmissionStates::ORDER_CREATED) {
            return false;
        }

        return $expires <= $nowUtc;
    }

    /**
     * Opportunistic purge of expired issued/failed/identity_accepted rows.
     * Processing and order_created rows are never deleted here.
     */
    public function purgeExpired(): void
    {
        if (random_int(1, 20) !== 1) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');
        $this->database->execute(
            sprintf(
                "DELETE FROM `%s%s`
                 WHERE `expires_at` < '%s'
                   AND `state` IN ('%s', '%s', '%s')",
                _DB_PREFIX_,
                self::TABLE,
                pSQL($now),
                pSQL(PopupSubmissionStates::ISSUED),
                pSQL(PopupSubmissionStates::FAILED),
                pSQL(PopupSubmissionStates::IDENTITY_ACCEPTED)
            )
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findReusableIssued(
        int $idShop,
        string $selectionHash,
        int $idGuest,
        int $idCustomer,
        string $now
    ): ?array {
        $guestSql = $idGuest > 0 ? '`id_guest` = ' . (int) $idGuest : '`id_guest` IS NULL';
        $customerSql = $idCustomer > 0 ? '`id_customer` = ' . (int) $idCustomer : '`id_customer` IS NULL';
        $row = $this->database->getRow(
            sprintf(
                "SELECT * FROM `%s%s`
                 WHERE `id_shop` = %d
                   AND `selection_hash` = '%s'
                   AND `state` = '%s'
                   AND `expires_at` > '%s'
                   AND %s
                   AND %s
                 ORDER BY `id_submission` DESC",
                _DB_PREFIX_,
                self::TABLE,
                $idShop,
                pSQL($selectionHash),
                pSQL(PopupSubmissionStates::ISSUED),
                pSQL($now),
                $guestSql,
                $customerSql
            )
        );

        return is_array($row) ? $row : null;
    }

    private function touchIssuedExpiry(int $submissionId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $expires = gmdate('Y-m-d H:i:s', time() + self::ISSUED_TTL_SECONDS);
        $this->database->update(
            self::TABLE,
            [
                'expires_at' => $expires,
                'updated_at' => $now,
            ],
            '`id_submission` = ' . (int) $submissionId . " AND `state` = '" . pSQL(PopupSubmissionStates::ISSUED) . "'"
        );
    }
}
