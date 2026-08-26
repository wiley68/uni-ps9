<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Cart;

use Cart;
use Context;
use PrestaShop\Module\Unipayment\Calculator\AvailableScheme;
use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\CurrencyGate;
use PrestaShop\Module\Unipayment\Calculator\UnavailableSchemeException;
use PrestaShop\Module\Unipayment\Checkout\CheckoutValidationException;
use PrestaShop\Module\Unipayment\Checkout\ConsentResolver;
use PrestaShop\Module\Unipayment\Checkout\ValidatedPaymentRequest;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrator;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrationResult;
use PrestaShop\Module\Unipayment\Product\GuestCustomerFactory;
use PrestaShop\Module\Unipayment\Product\PopupCustomerIdentityGate;
use PrestaShop\Module\Unipayment\Product\PopupOrderAddressResolver;
use PrestaShop\Module\Unipayment\Product\ProductPopupCustomerValidator;
use PrestaShop\Module\Unipayment\Product\ProductPopupSchemeList;
use PrestaShop\Module\Unipayment\Product\ProductPopupValidationException;

/**
 * Direct credit application from the cart popup (Woo cart_popup parity).
 *
 * Uses the existing PrestaShop cart lines — does not rebuild a single-product cart
 * and does not redirect through checkout.
 */
final class CartPopupApplyService
{
    /** @var Calculator */
    private $calculator;
    /** @var CartPopupCalculator */
    private $popupCalculator;
    /** @var ProductPopupCustomerValidator */
    private $customerValidator;
    /** @var GuestCustomerFactory */
    private $guestFactory;
    /** @var OrderOrchestrator */
    private $orchestrator;
    /** @var CurrencyGate */
    private $currencyGate;
    /** @var ConsentResolver */
    private $consents;
    /** @var PopupCustomerIdentityGate */
    private $identityGate;
    /** @var PopupOrderAddressResolver */
    private $addressResolver;

    public function __construct(
        Calculator $calculator,
        CartPopupCalculator $popupCalculator,
        ProductPopupCustomerValidator $customerValidator,
        GuestCustomerFactory $guestFactory,
        OrderOrchestrator $orchestrator,
        ?CurrencyGate $currencyGate = null,
        ?ConsentResolver $consents = null,
        ?PopupCustomerIdentityGate $identityGate = null,
        ?PopupOrderAddressResolver $addressResolver = null
    ) {
        $this->calculator = $calculator;
        $this->popupCalculator = $popupCalculator;
        $this->customerValidator = $customerValidator;
        $this->guestFactory = $guestFactory;
        $this->orchestrator = $orchestrator;
        $this->currencyGate = $currencyGate ?? new CurrencyGate();
        $this->consents = $consents ?? new ConsentResolver();
        $this->identityGate = $identityGate ?? new PopupCustomerIdentityGate();
        $this->addressResolver = $addressResolver ?? new PopupOrderAddressResolver();
    }

    /**
     * @param array<string, mixed> $shop
     * @param array<string, mixed> $posted
     */
    public function apply(array $shop, array $posted, CartContext $cartContext, Context $context): OrderOrchestrationResult
    {
        if ($cartContext->lines === []) {
            throw new UnavailableSchemeException('The selected financing scheme is unavailable.');
        }

        $popupType = (string) ($posted['popup_offer_type'] ?? '');
        $schemeType = (string) ($posted['scheme_type'] ?? '');
        $kopCode = trim((string) ($posted['kop_code'] ?? ''));
        $months = (int) ($posted['months'] ?? 0);
        $filterId = (int) ($posted['filter_id'] ?? 0);
        $schemeKey = trim((string) ($posted['scheme_key'] ?? ''));
        $firstInstallment = is_numeric($posted['first_installment'] ?? null) ? (float) $posted['first_installment'] : 0.0;
        $currencyIso = (string) $context->currency->iso_code;

        $allowedTypes = $popupType === 'standard' ? ['standard', 'promo'] : ($popupType === 'promo' ? ['promo'] : []);
        if (!$this->currencyGate->supports($shop, $currencyIso) || !in_array($schemeType, $allowedTypes, true)) {
            throw new UnavailableSchemeException('The selected financing scheme is unavailable.');
        }

        $scheme = $this->findScheme(
            $this->popupCalculator->commonSchemes($shop, $cartContext, $popupType),
            $kopCode,
            $months,
            $filterId
        );
        if ($scheme === null || !hash_equals(ProductPopupSchemeList::key($scheme), $schemeKey)) {
            throw new UnavailableSchemeException('The selected financing scheme is unavailable.');
        }

        $calcResult = $this->calculator->calculateScheme($shop, $cartContext->total, $scheme, $firstInstallment);

        $requireEgn = ((int) ($shop['uni_proces'] ?? 0)) === 1;
        $customerData = $this->customerValidator->validate($posted, $requireEgn);

        try {
            $accepted = $this->consents->validate($shop, $posted['consent'] ?? []);
        } catch (CheckoutValidationException $exception) {
            throw new ProductPopupValidationException([
                'consents' => 'Моля, приемете всички задължителни съгласия.',
            ]);
        }
        $acceptedConsents = array_values(array_filter(
            $this->consents->normalize($shop),
            static function (array $consent) use ($accepted): bool {
                return in_array($consent['id'], $accepted, true);
            }
        ));

        $this->ensureCustomerOnExistingCart($customerData, $context);

        $cart = $context->cart;
        if (!$cart instanceof Cart || (int) $cart->id <= 0) {
            throw new \RuntimeException('The cart could not be prepared for the popup order.');
        }

        $cartFingerprint = md5((int) $cart->id . ':' . $cartContext->total . ':' . $schemeKey);
        $request = new ValidatedPaymentRequest($calcResult, $customerData, $accepted, $cartFingerprint, $acceptedConsents);

        return $this->orchestrator->orchestrate(
            (int) $context->shop->id,
            (int) $cart->id,
            $request,
            $shop,
            'cart_popup'
        );
    }

    /** @param array<string, string> $customerData */
    private function ensureCustomerOnExistingCart(array $customerData, Context $context): void
    {
        $cart = $context->cart;
        if (!$cart instanceof Cart || (int) $cart->id <= 0) {
            throw new \RuntimeException('The cart could not be prepared for the popup order.');
        }

        if ($this->identityGate->shouldUseAuthenticatedCustomer($context->customer)) {
            $addressId = $this->addressResolver->resolveForLoggedInCustomer(
                $context->customer,
                $customerData,
                $context,
                $cart
            );
            if ($addressId <= 0) {
                throw new \RuntimeException('The financing address could not be resolved.');
            }
            $cart->id_address_delivery = $addressId;
            $cart->id_address_invoice = $addressId;
            $cart->id_customer = (int) $context->customer->id;
            $cart->secure_key = (string) $context->customer->secure_key;
        } else {
            // AUD-001: form e-mail never selects Customer identity. Always create a fresh guest.
            $result = $this->guestFactory->ensure($customerData, $context);
            $context->customer = $result['customer'];
            $context->cookie->id_customer = (int) $result['customer']->id;
            $context->cookie->customer_lastname = $result['customer']->lastname;
            $context->cookie->customer_firstname = $result['customer']->firstname;
            $context->cookie->logged = 0;
            $context->cookie->passwd = $result['customer']->passwd;
            $context->cookie->email = $result['customer']->email;
            $context->cookie->is_guest = 1;

            $cart->id_customer = (int) $result['customer']->id;
            $cart->secure_key = (string) $result['customer']->secure_key;
            $cart->id_address_delivery = (int) $result['address']->id;
            $cart->id_address_invoice = (int) $result['address']->id;
        }

        if (!$cart->update()) {
            throw new \RuntimeException('The cart could not be prepared for the popup order.');
        }

        $context->cart = $cart;
        $context->cookie->id_cart = (int) $cart->id;
        $context->cookie->write();
    }

    /** @param AvailableScheme[] $schemes */
    private function findScheme(array $schemes, string $kopCode, int $months, int $filterId): ?AvailableScheme
    {
        foreach ($schemes as $scheme) {
            if ($scheme->kopCode === $kopCode && $scheme->months === $months && $scheme->filterId === $filterId) {
                return $scheme;
            }
        }

        return null;
    }
}
