<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\SmartUcf;

final class SmartUcfDebugLogRepository implements SmartUcfDebugLogStoreInterface
{
    public const TABLE = 'unipayment_smartucf_log';
    public const RETENTION_MONTHS = 3;

    /** @var \Db|object */
    private $database;

    public function __construct($database = null)
    {
        $this->database = $database ?? \Db::getInstance();
    }

    public function install(): bool
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . $this->tableName() . '` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_shop` INT UNSIGNED NOT NULL,
            `id_order` INT UNSIGNED NOT NULL,
            `order_id` VARCHAR(64) NOT NULL,
            `http_status` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `request_json` LONGTEXT NOT NULL,
            `response_json` LONGTEXT NOT NULL,
            `transport_error` TEXT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_unipayment_smartucf_shop_order` (`id_shop`, `order_id`, `id`),
            KEY `idx_unipayment_smartucf_id_order` (`id_order`),
            KEY `idx_unipayment_smartucf_created_at` (`created_at`)
        ) ENGINE=' . constant('_MYSQL_ENGINE_') . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        return (bool) $this->database->execute($sql);
    }

    public function uninstall(): bool
    {
        return (bool) $this->database->execute('DROP TABLE IF EXISTS `' . $this->tableName() . '`');
    }

    /** @param array<string, mixed> $entry */
    public function insert(array $entry): bool
    {
        $idShop = (int) ($entry['id_shop'] ?? 0);
        if ($idShop <= 0) {
            return false;
        }

        $this->ensureTable();
        $this->prune();

        return (bool) $this->database->insert(self::TABLE, [
            'id_shop' => $idShop,
            'id_order' => max(0, (int) ($entry['ps_order_id'] ?? 0)),
            'order_id' => trim((string) ($entry['order_id'] ?? '')),
            'http_status' => max(0, (int) ($entry['http_code'] ?? 0)),
            'request_json' => $this->encodeBody($entry['request'] ?? null),
            'response_json' => $this->encodeBody($entry['response'] ?? null),
            'transport_error' => isset($entry['transport_error']) ? (string) $entry['transport_error'] : null,
            'created_at' => (string) ($entry['created_at_gmt'] ?? gmdate('Y-m-d H:i:s')),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function findLatestByOrderIdAndShop(string $orderId, int $idShop): ?array
    {
        $this->prune();
        $orderId = trim($orderId);
        if ($orderId === '' || $idShop <= 0) {
            return null;
        }

        $row = $this->database->getRow(sprintf(
            "SELECT * FROM `%s` WHERE `id_shop` = %d AND `order_id` = '%s' ORDER BY `id` DESC",
            $this->tableName(),
            $idShop,
            pSQL($orderId)
        ));
        if (!is_array($row)) {
            return null;
        }

        return $this->formatRow($row);
    }

    /** @return array<int, array<string, mixed>> */
    public function findAll(): array
    {
        $this->prune();
        $rows = $this->database->executeS(sprintf('SELECT * FROM `%s` ORDER BY `id` ASC', $this->tableName()));
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_map(function (array $row): array {
            return $this->formatRow($row);
        }, $rows));
    }

    public function prune(?\DateTimeImmutable $now = null): bool
    {
        $cutoff = self::retentionCutoff($now);

        return (bool) $this->database->execute(sprintf(
            "DELETE FROM `%s` WHERE `created_at` < '%s'",
            $this->tableName(),
            pSQL($cutoff)
        ));
    }

    public static function retentionCutoff(?\DateTimeImmutable $now = null): string
    {
        return ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-' . self::RETENTION_MONTHS . ' months')
            ->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'id_shop' => (int) ($row['id_shop'] ?? 0),
            'order_id' => (string) $row['order_id'],
            'ps_order_id' => (int) $row['id_order'],
            'http_code' => (int) $row['http_status'],
            'created_at_gmt' => (string) $row['created_at'],
            'request' => $this->decodeBody((string) $row['request_json']),
            'response' => $this->decodeBody((string) $row['response_json']),
            'transport_error' => $row['transport_error'] !== null ? (string) $row['transport_error'] : null,
        ];
    }

    /** @param mixed $body */
    private function encodeBody($body): string
    {
        try {
            return json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return 'null';
        }
    }

    /** @return mixed */
    private function decodeBody(string $body)
    {
        $decoded = json_decode($body, true);

        return $decoded !== null || $body === 'null' ? $decoded : $body;
    }

    private function tableName(): string
    {
        return _DB_PREFIX_ . self::TABLE;
    }

    private function ensureTable(): void
    {
        $table = $this->tableName();
        $exists = $this->database->executeS('SHOW TABLES LIKE "' . pSQL($table) . '"');
        if (!is_array($exists) || $exists === []) {
            $this->install();
        }
    }
}
