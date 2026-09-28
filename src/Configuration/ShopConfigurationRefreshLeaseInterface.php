<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Configuration;

interface ShopConfigurationRefreshLeaseInterface
{
    public function release(): void;
}
