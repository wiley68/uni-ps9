<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

final class OrderAttemptRepository implements OrderAttemptStoreInterface
{
    public const TABLE = 'unipayment_order_attempt';
    private $database;

    public function __construct(?\Db $database = null) { $this->database = $database ?? \Db::getInstance(); }

    public function install(): bool
    {
        return (bool) $this->database->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE . '` (
            `id_attempt` INT UNSIGNED NOT NULL AUTO_INCREMENT, `id_shop` INT UNSIGNED NOT NULL, `id_cart` INT UNSIGNED NOT NULL,
            `cart_fingerprint` CHAR(64) NOT NULL, `state` VARCHAR(32) NOT NULL, `id_order` INT UNSIGNED NULL,
            `order_reference` VARCHAR(13) NULL, `control_panel_order_id` BIGINT UNSIGNED NULL, `cp_payload` LONGTEXT NULL,
            `last_error_class` VARCHAR(255) NULL, `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id_attempt`), UNIQUE KEY `uniq_unipayment_attempt` (`id_shop`,`id_cart`,`cart_fingerprint`),
            KEY `idx_unipayment_attempt_order` (`id_order`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function uninstall(): bool { return (bool) $this->database->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::TABLE . '`'); }

    public function reserve(int $idShop, int $idCart, string $cartFingerprint): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $sql = sprintf("INSERT IGNORE INTO `%s%s` (`id_shop`,`id_cart`,`cart_fingerprint`,`state`,`created_at`,`updated_at`) VALUES (%d,%d,'%s','reserved','%s','%s')", _DB_PREFIX_, self::TABLE, $idShop, $idCart, pSQL($cartFingerprint), $now, $now);
        if (!$this->database->execute($sql)) throw new \RuntimeException('The financing attempt could not be reserved.');
        $created = (int) $this->database->Affected_Rows() === 1;
        $row = $this->database->getRow(sprintf("SELECT * FROM `%s%s` WHERE `id_shop`=%d AND `id_cart`=%d AND `cart_fingerprint`='%s'", _DB_PREFIX_, self::TABLE, $idShop, $idCart, pSQL($cartFingerprint)));
        if (!is_array($row)) throw new \RuntimeException('The financing attempt could not be loaded.');

        $row['_reservation_created'] = $created;
        return $row;
    }

    public function update(int $attemptId, array $changes): array
    {
        $allowed = ['state','id_order','order_reference','control_panel_order_id','cp_payload','last_error_class'];
        $data = [];
        foreach ($changes as $key => $value) if (in_array($key, $allowed, true)) $data[$key] = $value;
        $data['updated_at'] = gmdate('Y-m-d H:i:s');
        if (!$this->database->update(self::TABLE, $data, '`id_attempt`=' . $attemptId)) throw new \RuntimeException('The financing attempt could not be updated.');
        $row = $this->database->getRow('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_attempt`=' . $attemptId);
        if (!is_array($row)) throw new \RuntimeException('The financing attempt could not be reloaded.');

        return $row;
    }
}
