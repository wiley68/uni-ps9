<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Api;

interface ShopConfigurationProviderInterface
{
    /** @return array<string, mixed> */
    public function getShop(): array;
}
