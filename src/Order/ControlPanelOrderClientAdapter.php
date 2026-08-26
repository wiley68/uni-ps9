<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

use PrestaShop\Module\Unipayment\Api\ControlPanelClient;

final class ControlPanelOrderClientAdapter implements ControlPanelOrderClientInterface
{
    /** @var ControlPanelClient */
    private $client;

    public function __construct(ControlPanelClient $client)
    {
        $this->client = $client;
    }

    public function createOrder(array $payload): array
    {
        return $this->client->createOrder($payload);
    }

    public function updateOrderStatus(string $orderId, string $status, ?string $statusId = null): array
    {
        return $this->client->updateOrderStatus($orderId, $status, $statusId);
    }
}
