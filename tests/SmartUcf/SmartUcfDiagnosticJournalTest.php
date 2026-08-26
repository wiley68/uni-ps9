<?php

declare(strict_types=1);

/**
 * SmartUCF diagnostic journal gate, sanitization, and shop-scoped lookup API.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('_NEW_COOKIE_KEY_', 'test-key');

final class Configuration
{
    /** @var array<string, mixed> */
    public static $values = [];

    public static function get(
        string $key,
        ?int $idLang = null,
        ?int $idShopGroup = null,
        ?int $idShop = null,
        mixed $default = false
    ): mixed {
        return self::$values[$key] ?? $default;
    }

    public static function updateValue(string $key, mixed $value): bool
    {
        self::$values[$key] = $value;

        return true;
    }

    public static function deleteByName(string $key): bool
    {
        unset(self::$values[$key]);

        return true;
    }
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDebugLogStoreInterface;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDiagnosticJournal;

function assertJournal(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class MemoryDebugStore implements SmartUcfDebugLogStoreInterface
{
    /** @var list<array<string, mixed>> */
    public array $entries = [];

    public function insert(array $entry): bool
    {
        if ((int) ($entry['id_shop'] ?? 0) <= 0) {
            return false;
        }
        $entry['id'] = count($this->entries) + 1;
        $this->entries[] = $entry;

        return true;
    }

    public function findLatestByOrderIdAndShop(string $orderId, int $idShop): ?array
    {
        if ($idShop <= 0) {
            return null;
        }
        foreach (array_reverse($this->entries) as $entry) {
            if ($entry['order_id'] === $orderId && (int) ($entry['id_shop'] ?? 0) === $idShop) {
                return $entry;
            }
        }

        return null;
    }

    public function findAll(): array
    {
        return $this->entries;
    }

    public function prune(?DateTimeImmutable $now = null): bool
    {
        return true;
    }
}

$configuration = new ConfigurationRepository();
$store = new MemoryDebugStore();
$journal = new SmartUcfDiagnosticJournal($configuration, $store);
assertJournal(!$journal->record(1, 1, 'ORDER-1', 200, ['secret' => 'do-not-store'], []), 'disabled debug journal persisted an entry');
assertJournal($store->entries === [], 'disabled debug journal reached persistence');

Configuration::$values[ConfigurationRepository::DEBUG_ENABLED] = '1';
assertJournal(!$journal->record(0, 1, 'ORDER-1', 200, ['a' => 1], []), 'zero id_shop must fail closed');
assertJournal($journal->record(1, 1, 'ORDER-1', 200, [
    'user' => 'merchant',
    'pass' => 'password',
    'clientEmail' => 'person@example.com',
    'nested' => ['access_token' => 'token-value'],
], ['Authorization' => 'Bearer abc.def'], 'token=transport-secret'), 'enabled debug journal did not persist');
$json = json_encode($journal->buildExport(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
assertJournal(is_string($json) && json_decode($json, true) !== null, 'debug export is not valid JSON');
assertJournal(count($store->entries) === 1, 'debug export deleted journal entries');
foreach (['merchant', 'password', 'person@example.com', 'token-value', 'abc.def', 'transport-secret'] as $secret) {
    assertJournal(strpos((string) $json, $secret) === false, "debug export leaked {$secret}");
}
assertJournal(strpos((string) $json, '[REDACTED]') !== false, 'debug export did not contain redaction markers');

$latest = $journal->findLatestByOrderIdAndShop('ORDER-1', 1);
assertJournal(is_array($latest), 'findLatestByOrderIdAndShop returns entry');
assertJournal($journal->findLatestByOrderIdAndShop('ORDER-1', 2) === null, 'other shop must not see journal row');

$cutoff = PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDebugLogRepository::retentionCutoff(
    new DateTimeImmutable('2026-08-18 12:00:00', new DateTimeZone('UTC'))
);
assertJournal($cutoff === '2026-05-18 12:00:00', 'three-month retention');

$repoSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/src/SmartUcf/SmartUcfDebugLogRepository.php');
assertJournal(strpos($repoSrc, '`id_shop` INT UNSIGNED NOT NULL') !== false, 'schema must store id_shop');
assertJournal(strpos($repoSrc, 'findLatestByOrderIdAndShop') !== false, 'repository shop-scoped lookup');
assertJournal(!preg_match('/function\s+findLatestByOrderId\s*\(/', $repoSrc), 'global findLatestByOrderId must be removed');

fwrite(STDOUT, "OK (SmartUCF diagnostic journal gate and sanitization)\n");
