<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

interface OrderAttemptStoreInterface
{
    /** @return array<string, mixed> */
    public function reserve(int $idShop, int $idCart, string $cartFingerprint): array;

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    public function update(int $attemptId, array $changes): array;
}
