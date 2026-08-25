<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Product;

use Cart;
use Context;
use PrestaShop\Module\Unipayment\Checkout\CheckoutValidationException;
use PrestaShop\Module\Unipayment\Checkout\ConsentResolver;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationFlags;

/**
 * Phase 7 apply identity: validate customer/consents and resolve logged-in/guest context.
 *
 * Does not create PrestaShop customers, addresses, carts, or orders.
 */
final class ProductPopupApplyIdentityService
{
    /** @var ProductPopupCustomerValidator */
    private $customerValidator;
    /** @var ConsentResolver */
    private $consents;
    /** @var PopupCustomerIdentityGate */
    private $identityGate;
    /** @var PopupOrderAddressResolver */
    private $addressResolver;

    public function __construct(
        ?ProductPopupCustomerValidator $customerValidator = null,
        ?ConsentResolver $consents = null,
        ?PopupCustomerIdentityGate $identityGate = null,
        ?PopupOrderAddressResolver $addressResolver = null
    ) {
        $this->customerValidator = $customerValidator ?? new ProductPopupCustomerValidator();
        $this->consents = $consents ?? new ConsentResolver();
        $this->identityGate = $identityGate ?? new PopupCustomerIdentityGate();
        $this->addressResolver = $addressResolver ?? new PopupOrderAddressResolver();
    }

    /**
     * @param array<string, mixed> $shop
     * @param array<string, mixed> $posted
     * @return array{
     *   customer: array<string, string>,
     *   identity: array{id_guest: int, id_customer: int, is_logged: bool},
     *   preferred_address_id: int,
     *   matched_address_id: int
     * }
     */
    public function accept(array $shop, array $posted, Context $context): array
    {
        $requireEgn = ShopConfigurationFlags::isProcess2($shop);
        $customerData = $this->customerValidator->validate($posted, $requireEgn);

        try {
            $this->consents->validate($shop, $posted['consent'] ?? []);
        } catch (CheckoutValidationException $exception) {
            throw new ProductPopupValidationException([
                'consents' => 'Моля, приемете всички задължителни съгласия.',
            ]);
        }

        $isLogged = $this->identityGate->shouldUseAuthenticatedCustomer($context->customer);
        $idCustomer = $isLogged ? (int) $context->customer->id : 0;
        $idGuest = (int) ($context->cookie->id_guest ?? 0);
        $preferredAddressId = 0;
        $matchedAddressId = 0;

        if ($isLogged) {
            $cart = $context->cart instanceof Cart ? $context->cart : null;
            $preferredAddressId = $this->addressResolver->resolvePreferredAddressId(
                $idCustomer,
                $cart instanceof Cart ? (int) $cart->id_address_delivery : 0,
                $cart instanceof Cart ? (int) $cart->id_address_invoice : 0,
                (int) $context->language->id
            );
            $matchedAddressId = $this->addressResolver->findExactMatch(
                $idCustomer,
                $customerData,
                (int) $context->language->id
            );

            $postedAddressId = filter_var($posted['id_address'] ?? 0, FILTER_VALIDATE_INT);
            if (is_int($postedAddressId) && $postedAddressId > 0
                && !$this->addressResolver->assertOwnedActiveAddress($postedAddressId, $idCustomer)
            ) {
                throw new ProductPopupValidationException([
                    'address' => 'Избраният адрес не принадлежи на текущия клиент.',
                ]);
            }
        }

        unset($customerData['egn']);

        return [
            'customer' => $customerData,
            'identity' => [
                'id_guest' => $idGuest,
                'id_customer' => $idCustomer,
                'is_logged' => $isLogged,
            ],
            'preferred_address_id' => $preferredAddressId,
            'matched_address_id' => $matchedAddressId,
        ];
    }
}
