<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

interface ControlPanelOrderClientInterface
{
    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function createOrder(array $payload): array;

    /** @return array<string, mixed> */
    public function updateOrderStatus(string $orderId, string $status, ?string $statusId = null): array;
}
