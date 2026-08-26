<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

interface FinancingSnapshotStoreInterface
{
    /** @param array<string, mixed> $snapshot */
    public function save(int $attemptId, array $snapshot): void;

    /** @return array<string, mixed>|null */
    public function findByAttempt(int $attemptId): ?array;

    /** @param array<string, mixed> $changes */
    public function update(int $attemptId, array $changes): void;
}
