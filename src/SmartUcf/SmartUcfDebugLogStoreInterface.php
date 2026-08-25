<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\SmartUcf;

interface SmartUcfDebugLogStoreInterface
{
    /** @param array<string, mixed> $entry */
    public function insert(array $entry): bool;

    /** @return array<string, mixed>|null */
    public function findLatestByOrderId(string $orderId): ?array;

    /** @return array<int, array<string, mixed>> */
    public function findAll(): array;

    public function prune(?\DateTimeImmutable $now = null): bool;
}
