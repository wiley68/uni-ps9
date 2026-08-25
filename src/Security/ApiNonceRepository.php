<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Security;

final class ApiNonceRepository
{
    public const TABLE = 'unipayment_api_nonce';

    /** @var \Db|object|null */
    private $database;

    public function __construct($database = null)
    {
        $this->database = $database ?? \Db::getInstance();
    }

    public function install(): bool
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . $this->tableName() . '` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `unicid` VARCHAR(64) NOT NULL,
            `nonce_hash` CHAR(64) NOT NULL,
            `used_at` DATETIME NOT NULL,
            `expires_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_unipayment_api_nonce` (`unicid`, `nonce_hash`),
            KEY `idx_unipayment_api_nonce_expires` (`expires_at`)
        ) ENGINE=' . constant('_MYSQL_ENGINE_') . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        return (bool) $this->database->execute($sql);
    }

    public function uninstall(): bool
    {
        return (bool) $this->database->execute('DROP TABLE IF EXISTS `' . $this->tableName() . '`');
    }

    public function claimNonce(string $unicid, string $nonce, int $now): bool
    {
        $this->purgeExpired($now);

        $nonceHash = hash('sha256', $nonce);
        $usedAt = date('Y-m-d H:i:s', $now);
        $expiresAt = date('Y-m-d H:i:s', $now + ModuleRequestSignatureProtocol::NONCE_RETENTION_SECONDS);

        $inserted = $this->database->insert(self::TABLE, [
            'unicid' => $unicid,
            'nonce_hash' => $nonceHash,
            'used_at' => $usedAt,
            'expires_at' => $expiresAt,
        ]);

        if ($inserted) {
            return true;
        }

        $error = (string) $this->database->getMsgError();
        if ($error !== '' && stripos($error, 'Duplicate') !== false) {
            return false;
        }

        return false;
    }

    private function purgeExpired(int $now): void
    {
        if (random_int(1, 20) !== 1) {
            return;
        }

        $this->database->execute(
            'DELETE FROM `' . $this->tableName() . '` WHERE `expires_at` < \'' . pSQL(date('Y-m-d H:i:s', $now)) . '\''
        );
    }

    private function tableName(): string
    {
        return _DB_PREFIX_ . self::TABLE;
    }
}
