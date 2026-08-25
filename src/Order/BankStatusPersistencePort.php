<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

interface BankStatusPersistencePort
{
    /** @return array<string, mixed>|null */
    public function updateByOrderIdentifier(int $idShop, string $orderReference, string $statusId, string $statusLabel): ?array;
}
