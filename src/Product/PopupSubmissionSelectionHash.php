<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Product;

/**
 * Builds a deterministic non-PII selection hash for popup submissions.
 *
 * Uses a fixed-key JSON object (not delimiter concatenation) so field values
 * cannot shift identity boundaries. Product and cart flows use distinct
 * canonical shapes so tokens cannot be reused across flows.
 */
final class PopupSubmissionSelectionHash
{
    public const FLOW_PRODUCT_POPUP = 'product_popup';
    public const FLOW_CART_POPUP = 'cart_popup';

    /**
     * @param array{
     *   flow?: string,
     *   id_shop: int,
     *   id_product?: int,
     *   id_product_attribute?: int,
     *   quantity?: int,
     *   id_cart?: int,
     *   cart_total?: float|int|string,
     *   scheme_type: string,
     *   kop_code: string,
     *   months: int,
     *   filter_id: int,
     *   scheme_key: string,
     *   first_installment: float|int|string,
     *   id_guest: int,
     *   id_customer: int
     * } $binding
     */
    public function hash(array $binding): string
    {
        $flow = (string) ($binding['flow'] ?? self::FLOW_PRODUCT_POPUP);
        if ($flow === self::FLOW_CART_POPUP) {
            $canonical = [
                'flow' => self::FLOW_CART_POPUP,
                'id_shop' => (int) $binding['id_shop'],
                'id_cart' => (int) ($binding['id_cart'] ?? 0),
                'cart_total' => $this->normalizeAmount($binding['cart_total'] ?? 0),
                'scheme_type' => (string) $binding['scheme_type'],
                'kop_code' => (string) $binding['kop_code'],
                'months' => (int) $binding['months'],
                'filter_id' => (int) $binding['filter_id'],
                'scheme_key' => (string) $binding['scheme_key'],
                'first_installment' => $this->normalizeAmount($binding['first_installment']),
                'id_guest' => (int) $binding['id_guest'],
                'id_customer' => (int) $binding['id_customer'],
            ];
        } else {
            $canonical = [
                'flow' => self::FLOW_PRODUCT_POPUP,
                'id_shop' => (int) $binding['id_shop'],
                'id_product' => (int) ($binding['id_product'] ?? 0),
                'id_product_attribute' => (int) ($binding['id_product_attribute'] ?? 0),
                'quantity' => (int) ($binding['quantity'] ?? 0),
                'scheme_type' => (string) $binding['scheme_type'],
                'kop_code' => (string) $binding['kop_code'],
                'months' => (int) $binding['months'],
                'filter_id' => (int) $binding['filter_id'],
                'scheme_key' => (string) $binding['scheme_key'],
                'first_installment' => $this->normalizeAmount($binding['first_installment']),
                'id_guest' => (int) $binding['id_guest'],
                'id_customer' => (int) $binding['id_customer'],
            ];
        }

        return hash(
            'sha256',
            json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    /** @param float|int|string $value */
    private function normalizeAmount($value): string
    {
        return number_format(round((float) $value, 2), 2, '.', '');
    }
}
