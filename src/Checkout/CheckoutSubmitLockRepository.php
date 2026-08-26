<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Checkout;

final class CheckoutSubmitLockRepository
{
    public const TABLE = 'unipayment_checkout_lock';

    public const TTL_SECONDS = 45;

    /** @var \Db|object|null */
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
        $sql = 'CREATE TABLE IF NOT EXISTS `' . $this->tableName() . '` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_shop` INT UNSIGNED NOT NULL,
            `id_cart` INT UNSIGNED NOT NULL,
            `owner_token` CHAR(32) NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_unipayment_checkout_lock` (`id_shop`, `id_cart`),
            KEY `idx_unipayment_checkout_lock_expires` (`expires_at`)
        ) ENGINE=' . constant('_MYSQL_ENGINE_') . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        return (bool) $this->database->execute($sql);
    }

    public function uninstall(): bool
    {
        return (bool) $this->database->execute('DROP TABLE IF EXISTS `' . $this->tableName() . '`');
    }

    public function acquire(int $idShop, int $idCart, int $now, string $ownerToken): bool
    {
        if ($idShop <= 0 || $idCart <= 0 || $ownerToken === '') {
            return false;
        }

        $expiresAt = date('Y-m-d H:i:s', $now + self::TTL_SECONDS);
        $createdAt = date('Y-m-d H:i:s', $now);

        $inserted = $this->database->insert(self::TABLE, [
            'id_shop' => $idShop,
            'id_cart' => $idCart,
            'owner_token' => $ownerToken,
            'expires_at' => $expiresAt,
            'created_at' => $createdAt,
        ]);
        if ($inserted) {
            return true;
        }

        $nowSql = pSQL(date('Y-m-d H:i:s', $now));
        $this->database->execute(
            'UPDATE `' . $this->tableName() . '`
            SET `owner_token` = \'' . pSQL($ownerToken) . '\',
                `expires_at` = \'' . pSQL($expiresAt) . '\',
                `created_at` = \'' . pSQL($createdAt) . '\'
            WHERE `id_shop` = ' . (int) $idShop . '
              AND `id_cart` = ' . (int) $idCart . '
              AND `expires_at` <= \'' . $nowSql . '\''
        );

        return (int) $this->database->Affected_Rows() === 1;
    }

    public function release(int $idShop, int $idCart, string $ownerToken): bool
    {
        if ($idShop <= 0 || $idCart <= 0 || $ownerToken === '') {
            return false;
        }

        $this->database->execute(
            'DELETE FROM `' . $this->tableName() . '`
            WHERE `id_shop` = ' . (int) $idShop . '
              AND `id_cart` = ' . (int) $idCart . '
              AND `owner_token` = \'' . pSQL($ownerToken) . '\''
        );

        return (int) $this->database->Affected_Rows() === 1;
    }

    /** @return array<string, string>|null */
    public function find(int $idShop, int $idCart): ?array
    {
        if ($idShop <= 0 || $idCart <= 0) {
            return null;
        }

        $row = $this->database->getRow(
            'SELECT `id_shop`, `id_cart`, `owner_token`, `expires_at`, `created_at`
             FROM `' . $this->tableName() . '`
             WHERE `id_shop` = ' . (int) $idShop . '
               AND `id_cart` = ' . (int) $idCart
        );

        return is_array($row) ? $row : null;
    }

    private function tableName(): string
    {
        return _DB_PREFIX_ . self::TABLE;
    }
}
