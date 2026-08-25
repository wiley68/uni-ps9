<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Cart;

use PrestaShop\Module\Unipayment\Calculator\ProductContext;

/**
 * Builds authoritative cart financing context from PrestaShop Cart.
 *
 * Amount semantics (PS8 / Woo cart parity):
 * - Payable total = Cart::getOrderTotal(true, Cart::BOTH) — tax-inclusive products + shipping − vouchers.
 * - Each line keeps product/category identity; ProductContext::$price is set to that cart total
 *   (not per-line total_wt) so scheme filters evaluate against the financed cart amount.
 * - Line total_wt is retained for diagnostics only; eligibility/calculation use cart->total.
 */
final class CartContextFactory
{
    public function create(\Cart $cart): CartContext
    {
        $products = $cart->getProducts(true);
        $total = $this->payableTotal($cart);

        return $this->createContext(is_array($products) ? $products : [], $total);
    }

    public function createForCheckout(\Cart $cart): CartContext
    {
        $products = $cart->getProducts(true);
        $total = $this->payableTotal($cart);
        $rules = [];
        foreach ($cart->getCartRules() as $rule) {
            $rules[] = [
                'id_cart_rule' => (int) ($rule['id_cart_rule'] ?? 0),
                'value_real' => number_format((float) ($rule['value_real'] ?? 0), 2, '.', ''),
                'free_shipping' => (int) ($rule['free_shipping'] ?? 0),
            ];
        }
        usort($rules, static function (array $a, array $b): int {
            return $a['id_cart_rule'] <=> $b['id_cart_rule'];
        });
        $checkoutState = [
            'id_cart' => (int) $cart->id,
            'carrier_id' => (int) $cart->id_carrier,
            'delivery_option' => $cart->getDeliveryOption(null, false, false),
            'shipping_total' => number_format((float) $cart->getOrderTotal(true, \Cart::ONLY_SHIPPING), 2, '.', ''),
            'cart_rules' => $rules,
        ];

        return $this->createContext(is_array($products) ? $products : [], $total, $checkoutState);
    }

    private function payableTotal(\Cart $cart): float
    {
        return round((float) $cart->getOrderTotal(true, \Cart::BOTH), 2);
    }

    /** @param array<int, array<string, mixed>> $products @param array<string, mixed> $checkoutState */
    private function createContext(array $products, float $total, array $checkoutState = []): CartContext
    {
        $total = round($total, 2);
        $lines = [];
        foreach ($products as $row) {
            $productId = (int) ($row['id_product'] ?? 0);
            $quantity = (int) ($row['cart_quantity'] ?? $row['quantity'] ?? 0);
            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }
            $product = new \Product($productId, false, (int) \Context::getContext()->language->id);
            if (!\Validate::isLoadedObject($product)
                || !$product->active
                || !$product->available_for_order
                || !$product->checkQty($quantity)
            ) {
                continue;
            }
            $lines[] = new CartLine(
                new ProductContext($productId, array_map('intval', $product->getCategories()), $total),
                (int) ($row['id_product_attribute'] ?? 0),
                $quantity,
                (float) ($row['total_wt'] ?? 0)
            );
        }

        return new CartContext($lines, $total, $checkoutState);
    }
}
