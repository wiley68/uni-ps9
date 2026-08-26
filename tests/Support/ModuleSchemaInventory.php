<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Tests\Support;

use PrestaShop\Module\Unipayment\Checkout\CheckoutSubmitLockRepository;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationCache;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotRepository;
use PrestaShop\Module\Unipayment\Order\OrderAttemptRepository;
use PrestaShop\Module\Unipayment\Order\OrderBankStatusRepository;
use PrestaShop\Module\Unipayment\Order\OrderStateInstaller;
use PrestaShop\Module\Unipayment\Product\PopupSubmissionRepository;
use PrestaShop\Module\Unipayment\Security\ApiNonceRepository;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDebugLogRepository;

/**
 * Test-only inventory aligned with Unipayment::install() repository/table owners.
 */
final class ModuleSchemaInventory
{
    /** @return list<string> */
    public static function tableNames(): array
    {
        return [
            ShopConfigurationCache::TABLE,
            SmartUcfDebugLogRepository::TABLE,
            OrderBankStatusRepository::TABLE,
            ApiNonceRepository::TABLE,
            CheckoutSubmitLockRepository::TABLE,
            OrderAttemptRepository::TABLE,
            FinancingSnapshotRepository::TABLE,
            PopupSubmissionRepository::TABLE,
        ];
    }

    public static function tableExists(\Db $db, string $table): bool
    {
        $exists = PrestaShopDbAdapter::wrap($db)->executeS(
            'SHOW TABLES LIKE "' . pSQL(_DB_PREFIX_ . $table) . '"'
        );

        return is_array($exists) && $exists !== [];
    }

    /** @return array<string, bool> */
    public static function tablePresence(\Db $db): array
    {
        $presence = [];
        foreach (self::tableNames() as $table) {
            $presence[$table] = self::tableExists($db, $table);
        }

        return $presence;
    }

    public static function installAll(\Db $db): bool
    {
        return (new ConfigurationRepository())->install()
            && (new ShopConfigurationCache($db))->install()
            && (new SmartUcfDebugLogRepository($db))->install()
            && (new OrderBankStatusRepository($db))->install()
            && (new ApiNonceRepository($db))->install()
            && (new CheckoutSubmitLockRepository($db))->install()
            && (new OrderAttemptRepository($db))->install()
            && (new FinancingSnapshotRepository($db))->install()
            && (new PopupSubmissionRepository($db))->install()
            && (new OrderStateInstaller())->install();
    }
}

final class PrestaShopDbAdapter implements PrestaShopDbConnection
{
    /** @var \Db */
    private $database;

    public function __construct(\Db $database)
    {
        $this->database = $database;
    }

    public static function wrap(\Db $database): self
    {
        return new self($database);
    }

    /** @return array<int, array<string, mixed>>|false|null */
    public function executeS(string $sql)
    {
        return \call_user_func([$this->database, 'executeS'], $sql);
    }
}
