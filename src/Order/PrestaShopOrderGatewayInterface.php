<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

use PrestaShop\Module\Unipayment\Checkout\ValidatedPaymentRequest;

interface PrestaShopOrderGatewayInterface
{
    /**
     * @param array<string, mixed> $shop Cached shop configuration for order mail extras.
     */
    public function create(ValidatedPaymentRequest $request, array $shop = []): CreatedOrder;

    public function load(int $idOrder): CreatedOrder;

    public function markFailed(int $idOrder): void;

    public function markAwaiting(int $idOrder): void;
}
