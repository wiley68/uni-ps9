<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Configuration;

interface ShopConfigurationRefreshCoordinatorInterface
{
    public function acquire(string $unicid, int $waitSeconds): ?ShopConfigurationRefreshLeaseInterface;
}
