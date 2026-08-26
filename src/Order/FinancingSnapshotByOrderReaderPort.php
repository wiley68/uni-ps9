<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

interface FinancingSnapshotByOrderReaderPort
{
    /** @return array<string, mixed>|null */
    public function findByOrderId(int $idOrder): ?array;
}
