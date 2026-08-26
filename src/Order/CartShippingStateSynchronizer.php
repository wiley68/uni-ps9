<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/**
 * Recomputes PrestaShop cart delivery/package state after customer/address mutation.
 *
 * PaymentModule::validateOrder() creates one Order per package address. When
 * cart.delivery_option / static delivery caches still reference a previous address,
 * validateOrder mutates package_list with empty slots and materializes an empty twin.
 */
final class CartShippingStateSynchronizer
{
    public function synchronize(\Cart $cart): void
    {
        if ((int) $cart->id <= 0) {
            throw new \InvalidArgumentException('Cart id is required to synchronize shipping state.');
        }

        $cart->delivery_option = '';
        $cart->id_carrier = 0;
        if (!$cart->update()) {
            throw new \RuntimeException('The cart shipping state could not be reset.');
        }

        \Cart::resetStaticCache();

        $deliveryOption = $cart->getDeliveryOption(null, false, false);
        if (!is_array($deliveryOption) || $deliveryOption === []) {
            \Cart::resetStaticCache();

            return;
        }

        // Keep only the current cart delivery address — drop stale multi-address keys.
        $idAddress = (int) $cart->id_address_delivery;
        if ($idAddress > 0 && isset($deliveryOption[$idAddress])) {
            $deliveryOption = [$idAddress => $deliveryOption[$idAddress]];
        } elseif ($idAddress > 0) {
            $list = $cart->getDeliveryOptionList(null, true);
            if (isset($list[$idAddress]) && is_array($list[$idAddress]) && $list[$idAddress] !== []) {
                $deliveryOption = [$idAddress => (string) array_key_first($list[$idAddress])];
            }
        }

        $cart->setDeliveryOption($deliveryOption);
        \Cart::resetStaticCache();
        $cart->getPackageList(true);
    }
}
