<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Checkout;

use PrestaShop\Module\Unipayment\Cart\CartContext;

final class CartSnapshot
{
    public function fingerprint(CartContext $cart, string $currencyIso): string
    {
        $lines = [];
        foreach ($cart->lines as $line) {
            $lines[] = [
                'product_id' => $line->product->productId,
                'product_attribute_id' => $line->productAttributeId,
                'quantity' => $line->quantity,
                'line_total' => number_format($line->lineTotal, 2, '.', ''),
            ];
        }
        usort($lines, static function (array $a, array $b): int {
            return [$a['product_id'], $a['product_attribute_id']] <=> [$b['product_id'], $b['product_attribute_id']];
        });
        $checkoutState = $this->normalize($cart->checkoutState);
        if (is_array($checkoutState) && isset($checkoutState['cart_rules']) && is_array($checkoutState['cart_rules'])) {
            $rules = array_values($checkoutState['cart_rules']);
            usort($rules, static function ($a, $b): int {
                $idA = is_array($a) ? (int) ($a['id_cart_rule'] ?? 0) : 0;
                $idB = is_array($b) ? (int) ($b['id_cart_rule'] ?? 0) : 0;

                return $idA <=> $idB;
            });
            $checkoutState['cart_rules'] = $rules;
        }
        $payload = [
            'currency' => strtoupper(trim($currencyIso)),
            'total' => number_format($cart->total, 2, '.', ''),
            'lines' => $lines,
            'checkout_state' => $checkoutState,
        ];

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    /** @param mixed $value @return mixed */
    private function normalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }
}
