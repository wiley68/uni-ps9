<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Configuration;

use PrestaShop\Module\Unipayment\Api\Exception\InvalidPayloadException;

final class ShopConfigurationCache implements ShopConfigurationCacheInterface
{
    public const TABLE = 'unipayment_shop_cache';
    public const TTL_SECONDS = 86400;

    /** @var \Db */
    private $database;

    /** @var callable */
    private $clock;

    public function __construct(?\Db $database = null, ?callable $clock = null)
    {
        $this->database = $database ?? \Db::getInstance();
        $this->clock = $clock ?? 'time';
    }

    public function install(): bool
    {
        $table = $this->tableName();
        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `unicid` VARCHAR(36) NOT NULL,
            `shop_data` LONGTEXT NOT NULL,
            `coeff_list` LONGTEXT NULL,
            `kop_data` LONGTEXT NULL,
            `consents` LONGTEXT NULL,
            `fetched_at` DATETIME NOT NULL,
            `expires_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_unipayment_cache_unicid` (`unicid`),
            KEY `idx_unipayment_cache_expires_at` (`expires_at`)
        ) ENGINE=" . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        return (bool) $this->database->execute($sql);
    }

    public function uninstall(): bool
    {
        return (bool) $this->database->execute('DROP TABLE IF EXISTS `' . $this->tableName() . '`');
    }

    public function getFresh(string $unicid): ?array
    {
        $unicid = trim($unicid);
        if ($unicid === '') {
            return null;
        }

        $row = $this->database->getRow(sprintf(
            "SELECT `shop_data` FROM `%s` WHERE `unicid` = '%s' AND `expires_at` > '%s'",
            $this->tableName(),
            pSQL($unicid),
            pSQL($this->formatTimestamp($this->now()))
        ));
        if (!is_array($row) || !isset($row['shop_data']) || !is_string($row['shop_data'])) {
            return null;
        }

        try {
            $decoded = json_decode($row['shop_data'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->delete($unicid);

            return null;
        }

        if (!is_array($decoded) || $decoded === []) {
            $this->delete($unicid);

            return null;
        }

        return $decoded;
    }

    public function replace(string $unicid, array $shopData): bool
    {
        $unicid = trim($unicid);
        if ($unicid === '' || $shopData === []) {
            throw new InvalidPayloadException('The shop configuration snapshot is empty or has no UNICID.');
        }

        $now = $this->now();
        $values = [
            'unicid' => $unicid,
            'shop_data' => $this->encode($shopData),
            'coeff_list' => $this->encodeOptional($shopData, 'coeff_list'),
            'kop_data' => $this->encodeOptional($shopData, 'kop'),
            'consents' => $this->encodeOptional($shopData, 'consents'),
            'fetched_at' => $this->formatTimestamp($now),
            'expires_at' => $this->formatTimestamp($now + self::TTL_SECONDS),
        ];

        $columns = [];
        $sqlValues = [];
        $updates = [];
        foreach ($values as $column => $value) {
            $columns[] = '`' . $column . '`';
            $sqlValues[] = $value === null ? 'NULL' : "'" . pSQL($value, true) . "'";
            if ($column !== 'unicid') {
                $updates[] = '`' . $column . '` = VALUES(`' . $column . '`)';
            }
        }

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
            $this->tableName(),
            implode(', ', $columns),
            implode(', ', $sqlValues),
            implode(', ', $updates)
        );

        return (bool) $this->database->execute($sql);
    }

    public function delete(string $unicid): bool
    {
        if (trim($unicid) === '') {
            return true;
        }

        return (bool) $this->database->delete(
            self::TABLE,
            "`unicid` = '" . pSQL(trim($unicid)) . "'"
        );
    }

    public function clear(): bool
    {
        return (bool) $this->database->execute('DELETE FROM `' . $this->tableName() . '`');
    }

    public function getMetadata(string $unicid): ?array
    {
        if (trim($unicid) === '') {
            return null;
        }

        $row = $this->database->getRow(sprintf(
            "SELECT `fetched_at`, `expires_at` FROM `%s` WHERE `unicid` = '%s'",
            $this->tableName(),
            pSQL(trim($unicid))
        ));
        if (!is_array($row) || !isset($row['fetched_at'], $row['expires_at'])) {
            return null;
        }

        $expiresAt = strtotime((string) $row['expires_at'] . ' UTC');

        return [
            'fetched_at' => (string) $row['fetched_at'],
            'expires_at' => (string) $row['expires_at'],
            'is_fresh' => $expiresAt !== false && $expiresAt > $this->now(),
        ];
    }

    /** @param mixed $data */
    private function encode($data): string
    {
        try {
            return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidPayloadException('The shop configuration snapshot cannot be encoded.', 0, $exception);
        }
    }

    /** @param array<string, mixed> $data */
    private function encodeOptional(array $data, string $key): ?string
    {
        return array_key_exists($key, $data) ? $this->encode($data[$key]) : null;
    }

    private function tableName(): string
    {
        return _DB_PREFIX_ . self::TABLE;
    }

    private function formatTimestamp(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function now(): int
    {
        return (int) call_user_func($this->clock);
    }
}
