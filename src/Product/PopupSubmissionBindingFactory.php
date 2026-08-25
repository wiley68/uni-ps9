<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Product;

use Context;

/**
 * Resolves popup submission binding inputs from request + context.
 *
 * Identity comes from PrestaShop Context/session, never from client POST.
 * Cart amount / id_cart come from authoritative CartContext (server-side).
 */
final class PopupSubmissionBindingFactory
{
    /** @var PopupSubmissionSelectionHash */
    private $hash;

    public function __construct(?PopupSubmissionSelectionHash $hash = null)
    {
        $this->hash = $hash ?? new PopupSubmissionSelectionHash();
    }

    /**
     * @param array{
     *   id_product: int,
     *   id_product_attribute: int,
     *   quantity: int,
     *   scheme_type: string,
     *   kop_code: string,
     *   months: int,
     *   filter_id: int,
     *   scheme_key: string,
     *   first_installment: float|int|string
     * } $selection
     * @return array{hash: string, id_guest: int, id_customer: int, binding: array<string, mixed>}
     */
    public function fromSelection(array $selection, Context $context): array
    {
        $idGuest = $this->resolveGuestId($context);
        $idCustomer = $this->resolveCustomerId($context);

        $binding = [
            'flow' => PopupSubmissionSelectionHash::FLOW_PRODUCT_POPUP,
            'id_shop' => (int) $context->shop->id,
            'id_product' => (int) $selection['id_product'],
            'id_product_attribute' => (int) $selection['id_product_attribute'],
            'quantity' => (int) $selection['quantity'],
            'scheme_type' => (string) $selection['scheme_type'],
            'kop_code' => (string) $selection['kop_code'],
            'months' => (int) $selection['months'],
            'filter_id' => (int) $selection['filter_id'],
            'scheme_key' => (string) $selection['scheme_key'],
            'first_installment' => $selection['first_installment'],
            'id_guest' => $idGuest,
            'id_customer' => $idCustomer,
        ];

        return [
            'hash' => $this->hash->hash($binding),
            'id_guest' => $idGuest,
            'id_customer' => $idCustomer,
            'binding' => $binding,
        ];
    }

    /**
     * Cart-wide financing binding (PS8 cart calculator semantics).
     *
     * @param array{
     *   id_cart: int,
     *   cart_total: float|int|string,
     *   scheme_type: string,
     *   kop_code: string,
     *   months: int,
     *   filter_id: int,
     *   scheme_key: string,
     *   first_installment: float|int|string
     * } $selection
     * @return array{hash: string, id_guest: int, id_customer: int, binding: array<string, mixed>}
     */
    public function fromCartSelection(array $selection, Context $context): array
    {
        $idGuest = $this->resolveGuestId($context);
        $idCustomer = $this->resolveCustomerId($context);

        $binding = [
            'flow' => PopupSubmissionSelectionHash::FLOW_CART_POPUP,
            'id_shop' => (int) $context->shop->id,
            'id_cart' => (int) $selection['id_cart'],
            'cart_total' => $selection['cart_total'],
            'scheme_type' => (string) $selection['scheme_type'],
            'kop_code' => (string) $selection['kop_code'],
            'months' => (int) $selection['months'],
            'filter_id' => (int) $selection['filter_id'],
            'scheme_key' => (string) $selection['scheme_key'],
            'first_installment' => $selection['first_installment'],
            'id_guest' => $idGuest,
            'id_customer' => $idCustomer,
        ];

        return [
            'hash' => $this->hash->hash($binding),
            'id_guest' => $idGuest,
            'id_customer' => $idCustomer,
            'binding' => $binding,
        ];
    }

    private function resolveGuestId(Context $context): int
    {
        return (int) ($context->cookie->id_guest ?? 0);
    }

    private function resolveCustomerId(Context $context): int
    {
        $customer = $context->customer;
        if ($customer instanceof \Customer && method_exists($customer, 'isLogged') && $customer->isLogged()) {
            return (int) $customer->id;
        }

        return 0;
    }
}
