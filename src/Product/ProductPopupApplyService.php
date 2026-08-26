<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Product;

use Cart;
use Context;
use Customer;
use PrestaShop\Module\Unipayment\Calculator\AvailableScheme;
use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\CurrencyGate;
use PrestaShop\Module\Unipayment\Calculator\ProductContext;
use PrestaShop\Module\Unipayment\Calculator\UnavailableSchemeException;
use PrestaShop\Module\Unipayment\Checkout\CheckoutValidationException;
use PrestaShop\Module\Unipayment\Checkout\ConsentResolver;
use PrestaShop\Module\Unipayment\Checkout\ValidatedPaymentRequest;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrator;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrationResult;
use PrestaShop\Module\Unipayment\Order\SensitiveDataCipher;
use Validate;

/**
 * Coordinates the direct order flow from the product popup Step 2 "Submit".
 *
 * Flow:
 *  1. Re-validate financing scheme availability and recalculate
 *  2. Validate customer data (including EGN when Process 2)
 *  3. Authenticated customers keep Context identity; anonymous visitors get a fresh guest
 *  4. Build a single-product cart (or reuse cart bound to the popup submission)
 *  5. Persist id_cart on the submission BEFORE OrderOrchestrator (AUD-002A)
 *  6. Build ValidatedPaymentRequest
 *  7. Delegate to OrderOrchestrator
 */
final class ProductPopupApplyService
{
    /** @var Calculator */
    private $calculator;
    /** @var ProductPopupCustomerValidator */
    private $customerValidator;
    /** @var GuestCustomerFactory */
    private $guestFactory;
    /** @var OrderOrchestrator */
    private $orchestrator;
    /** @var SensitiveDataCipher */
    private $cipher;
    /** @var CurrencyGate */
    private $currencyGate;
    /** @var ConsentResolver */
    private $consents;
    /** @var PopupCustomerIdentityGate */
    private $identityGate;
    /** @var PopupSubmissionRepository|null */
    private $submissions;
    /** @var PopupOrderAddressResolver */
    private $addressResolver;

    public function __construct(
        Calculator $calculator,
        ProductPopupCustomerValidator $customerValidator,
        GuestCustomerFactory $guestFactory,
        OrderOrchestrator $orchestrator,
        SensitiveDataCipher $cipher,
        ?CurrencyGate $currencyGate = null,
        ?ConsentResolver $consents = null,
        ?PopupCustomerIdentityGate $identityGate = null,
        ?PopupSubmissionRepository $submissions = null,
        ?PopupOrderAddressResolver $addressResolver = null
    ) {
        $this->calculator = $calculator;
        $this->customerValidator = $customerValidator;
        $this->guestFactory = $guestFactory;
        $this->orchestrator = $orchestrator;
        $this->cipher = $cipher;
        $this->currencyGate = $currencyGate ?? new CurrencyGate();
        $this->consents = $consents ?? new ConsentResolver();
        $this->identityGate = $identityGate ?? new PopupCustomerIdentityGate();
        $this->submissions = $submissions;
        $this->addressResolver = $addressResolver ?? new PopupOrderAddressResolver();
    }

    /**
     * @param array<string, mixed> $shop      Cached shop configuration
     * @param array<string, mixed> $posted     Raw POST data
     * @return OrderOrchestrationResult
     */
    public function apply(
        array $shop,
        array $posted,
        ProductContext $product,
        int $productId,
        int $attributeId,
        int $quantity,
        Context $context,
        int $submissionId = 0,
        int $reuseCartId = 0
    ): OrderOrchestrationResult {
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

        $scheme = $this->findScheme($this->calculator->availableSchemes($shop, $product, $schemeType), $kopCode, $months, $filterId);
        if ($scheme === null || !hash_equals(ProductPopupSchemeList::key($scheme), $schemeKey)) {
            throw new UnavailableSchemeException('The selected financing scheme is unavailable.');
        }

        $calcResult = $this->calculator->calculateScheme($shop, $product->price, $scheme, $firstInstallment);

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

        if ($reuseCartId > 0) {
            $this->attachExistingCart($reuseCartId, $context);
        } else {
            $this->ensureCustomerAndCart($customerData, $productId, $attributeId, $quantity, $context);
            if ($submissionId > 0 && $this->submissions !== null) {
                $this->submissions->attachCart($submissionId, (int) $context->cart->id);
            }
        }

        $cart = $context->cart;
        $cartFingerprint = md5((int) $cart->id . ':' . $product->price . ':' . $schemeKey);

        $request = new ValidatedPaymentRequest($calcResult, $customerData, $accepted, $cartFingerprint, $acceptedConsents);

        return $this->orchestrator->orchestrate(
            (int) $context->shop->id,
            (int) $cart->id,
            $request,
            $shop,
            'product_popup'
        );
    }

    private function attachExistingCart(int $idCart, Context $context): void
    {
        $cart = new Cart($idCart);
        if (!Validate::isLoadedObject($cart) || (int) $cart->id_shop !== (int) $context->shop->id) {
            throw new \RuntimeException('The popup submission cart could not be recovered.');
        }

        $customer = new Customer((int) $cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            throw new \RuntimeException('The popup submission customer could not be recovered.');
        }

        $context->customer = $customer;
        $context->cart = $cart;
        $context->cookie->id_customer = (int) $customer->id;
        $context->cookie->customer_lastname = $customer->lastname;
        $context->cookie->customer_firstname = $customer->firstname;
        $context->cookie->logged = $this->identityGate->shouldUseAuthenticatedCustomer($customer) ? 1 : 0;
        $context->cookie->passwd = $customer->passwd;
        $context->cookie->email = $customer->email;
        $context->cookie->is_guest = (int) $customer->is_guest;
        $context->cookie->id_cart = (int) $cart->id;
        $context->cookie->write();
    }

    /** @param array<string, string> $customerData */
    private function ensureCustomerAndCart(array $customerData, int $productId, int $attributeId, int $quantity, Context $context): void
    {
        if ($this->identityGate->shouldUseAuthenticatedCustomer($context->customer)) {
            $addressId = $this->addressResolver->resolveForLoggedInCustomer(
                $context->customer,
                $customerData,
                $context,
                $context->cart instanceof Cart ? $context->cart : null
            );
            if ($addressId <= 0) {
                throw new \RuntimeException('The financing address could not be resolved.');
            }
            $cart = $this->createFreshCart($context, $addressId);
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

            $cart = $this->createFreshCart($context, (int) $result['address']->id);
        }

        $cart->updateQty($quantity, $productId, $attributeId > 0 ? $attributeId : null);

        $context->cart = $cart;
        $context->cookie->id_cart = (int) $cart->id;
        $context->cookie->write();
    }

    private function createFreshCart(Context $context, int $addressId): Cart
    {
        $cart = new Cart();
        $cart->id_shop_group = (int) $context->shop->id_shop_group;
        $cart->id_shop = (int) $context->shop->id;
        $cart->id_lang = (int) $context->language->id;
        $cart->id_currency = (int) $context->currency->id;
        $cart->id_customer = (int) $context->customer->id;
        $cart->id_guest = (int) $context->cookie->id_guest;
        $cart->secure_key = (string) $context->customer->secure_key;
        if ($addressId > 0) {
            $cart->id_address_delivery = $addressId;
            $cart->id_address_invoice = $addressId;
        }
        if (!$cart->add()) {
            throw new \RuntimeException('The cart could not be created for the popup order.');
        }

        return $cart;
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
