<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Configuration;

interface ShopConfigurationCacheInterface
{
    /** @return array<string, mixed>|null */
    public function getFresh(string $unicid): ?array;

    /** @param array<string, mixed> $shopData */
    public function replace(string $unicid, array $shopData): bool;

    public function delete(string $unicid): bool;

    public function clear(): bool;

    /** @return array<string, mixed>|null */
    public function getMetadata(string $unicid): ?array;
}
