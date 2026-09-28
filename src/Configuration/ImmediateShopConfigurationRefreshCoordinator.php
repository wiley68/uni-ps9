<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Configuration;

final class ImmediateShopConfigurationRefreshCoordinator implements ShopConfigurationRefreshCoordinatorInterface
{
    public function acquire(string $unicid, int $waitSeconds): ?ShopConfigurationRefreshLeaseInterface
    {
        return new ImmediateShopConfigurationRefreshLease();
    }
}

final class ImmediateShopConfigurationRefreshLease implements ShopConfigurationRefreshLeaseInterface
{
    public function release(): void
    {
    }
}
