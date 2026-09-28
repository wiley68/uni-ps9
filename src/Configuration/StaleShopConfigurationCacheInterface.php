<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Configuration;

interface StaleShopConfigurationCacheInterface
{
    /** @return array{data:array<string,mixed>,fetched_at:string,expires_at:string,expires_at_timestamp:int}|null */
    public function getRetained(string $unicid): ?array;
}
