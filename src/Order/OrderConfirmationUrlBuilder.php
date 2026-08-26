<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/**
 * Native PrestaShop order-confirmation URL (Woo thank-you / order-received equivalent).
 */
final class OrderConfirmationUrlBuilder
{
    /**
     * @param array<string, mixed> $query
     */
    public function build(\Context $context, \PaymentModule $module, int $idOrder): string
    {
        $order = new \Order($idOrder);
        if (!\Validate::isLoadedObject($order)) {
            return (string) $context->link->getPageLink('history', true);
        }

        return (string) $context->link->getPageLink(
            'order-confirmation',
            true,
            (int) $context->language->id,
            [
                'id_cart' => (int) $order->id_cart,
                'id_module' => (int) $module->id,
                'id_order' => (int) $order->id,
                'key' => (string) $order->secure_key,
            ]
        );
    }
}
