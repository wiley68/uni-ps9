<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Product;

/**
 * Builds a deterministic non-PII selection hash for Product Popup submissions.
 *
 * Uses a fixed-key JSON object (not delimiter concatenation) so field values
 * cannot shift identity boundaries.
 */
final class PopupSubmissionSelectionHash
{
    public const FLOW_PRODUCT_POPUP = 'product_popup';

    /**
     * @param array{
     *   id_shop: int,
     *   id_product: int,
     *   id_product_attribute: int,
     *   quantity: int,
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
        $canonical = [
            'flow' => self::FLOW_PRODUCT_POPUP,
            'id_shop' => (int) $binding['id_shop'],
            'id_product' => (int) $binding['id_product'],
            'id_product_attribute' => (int) $binding['id_product_attribute'],
            'quantity' => (int) $binding['quantity'],
            'scheme_type' => (string) $binding['scheme_type'],
            'kop_code' => (string) $binding['kop_code'],
            'months' => (int) $binding['months'],
            'filter_id' => (int) $binding['filter_id'],
            'scheme_key' => (string) $binding['scheme_key'],
            'first_installment' => $this->normalizeFirstInstallment($binding['first_installment']),
            'id_guest' => (int) $binding['id_guest'],
            'id_customer' => (int) $binding['id_customer'],
        ];

        return hash(
            'sha256',
            json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    /** @param float|int|string $value */
    private function normalizeFirstInstallment($value): string
    {
        return number_format(round((float) $value, 2), 2, '.', '');
    }
}
