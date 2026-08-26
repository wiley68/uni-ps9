<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

interface LeasingMailDispatchPort
{
    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $shop
     * @param array{status_id: string, status_label: string} $status
     */
    public function send(array $snapshot, int $attemptId, array $shop, array $status): void;
}
