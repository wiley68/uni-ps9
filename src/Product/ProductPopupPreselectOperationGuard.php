<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Product;

final class ProductPopupPreselectOperationGuard
{
    public const APPLIED_COOKIE = 'unipayment_preselect_applied';
    public const LEGACY_MUTATION_COOKIE = 'unipayment_preselect_mutation';

    public function validateOperationToken(string $token): void
    {
        if (!preg_match('/^[a-f0-9]{32,64}$/i', $token)) {
            throw new ProductPopupCheckoutPreselectionException('Заявката не може да бъде обработена. Моля, опитайте отново.');
        }
    }

    /** @return array<string, int|string>|null */
    public function readApplied(\Cookie $cookie): ?array
    {
        $raw = (string) $cookie->{self::APPLIED_COOKIE};
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $token = (string) ($decoded['token'] ?? '');
        if ($token === '') {
            return null;
        }

        return [
            'token' => $token,
            'cart_id' => (int) ($decoded['cart_id'] ?? 0),
            'product_id' => (int) ($decoded['product_id'] ?? 0),
            'product_attribute_id' => (int) ($decoded['product_attribute_id'] ?? 0),
            'line_qty_after' => (int) ($decoded['line_qty_after'] ?? 0),
        ];
    }

    /**
     * @param array<string, int|string>|null $applied
     */
    public function shouldSkipCartMutation(
        ?array $applied,
        string $operationToken,
        int $cartId,
        int $productId,
        int $productAttributeId,
        int $currentLineQty
    ): bool {
        if ($applied === null) {
            return false;
        }

        if (!hash_equals((string) $applied['token'], $operationToken)) {
            return false;
        }

        if ((int) $applied['cart_id'] !== $cartId
            || (int) $applied['product_id'] !== $productId
            || (int) $applied['product_attribute_id'] !== $productAttributeId
        ) {
            return false;
        }

        return $currentLineQty >= (int) $applied['line_qty_after'];
    }

    public function persistApplied(
        \Cookie $cookie,
        string $operationToken,
        int $cartId,
        int $productId,
        int $productAttributeId,
        int $lineQtyAfter
    ): void {
        $cookie->{self::APPLIED_COOKIE} = json_encode([
            'token' => $operationToken,
            'cart_id' => $cartId,
            'product_id' => $productId,
            'product_attribute_id' => $productAttributeId,
            'line_qty_after' => $lineQtyAfter,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function clearLegacyMarker(\Cookie $cookie): void
    {
        unset($cookie->{self::LEGACY_MUTATION_COOKIE});
    }
}
