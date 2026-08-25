<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Checkout;

use PrestaShop\Module\Unipayment\Calculator\AvailableScheme;
use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\CurrencyGate;
use PrestaShop\Module\Unipayment\Calculator\UnavailableSchemeException;
use PrestaShop\Module\Unipayment\Cart\CartContext;
use PrestaShop\Module\Unipayment\Cart\CartSchemeResolver;

final class CheckoutPaymentValidator
{
    /** @var Calculator */
    private $calculator;
    /** @var CartSchemeResolver */
    private $cartResolver;
    /** @var CurrencyGate */
    private $currencyGate;
    /** @var CartSnapshot */
    private $snapshot;
    /** @var CartSnapshotSigner */
    private $signer;
    /** @var CustomerFieldValidator */
    private $customers;
    /** @var ConsentResolver */
    private $consents;

    public function __construct(Calculator $calculator, CartSchemeResolver $cartResolver, CurrencyGate $currencyGate, CartSnapshot $snapshot, CartSnapshotSigner $signer, CustomerFieldValidator $customers, ConsentResolver $consents)
    {
        $this->calculator = $calculator;
        $this->cartResolver = $cartResolver;
        $this->currencyGate = $currencyGate;
        $this->snapshot = $snapshot;
        $this->signer = $signer;
        $this->customers = $customers;
        $this->consents = $consents;
    }

    /** @param array<string, mixed> $shop @param array<string, mixed> $posted @param array<string, mixed> $customer */
    public function validate(array $shop, CartContext $cart, string $currencyIso, array $posted, array $customer): ValidatedPaymentRequest
    {
        if (!$this->currencyGate->supports($shop, $currencyIso)) {
            throw new CheckoutValidationException('This payment method is unavailable for the selected currency.');
        }
        $fingerprint = $this->snapshot->fingerprint($cart, $currencyIso);
        if (!$this->signer->verify((string) ($posted['cart_snapshot'] ?? ''), $fingerprint)) {
            throw new CheckoutValidationException('The cart changed. Please review the financing options again.');
        }
        $resolution = $this->cartResolver->resolve($shop, $cart);
        $selection = SchemeSelection::fromPosted($posted);
        $scheme = $this->findScheme($this->cartResolver->unifiedSchemes($resolution), $selection);
        if ($scheme === null) {
            throw new CheckoutValidationException('The selected financing scheme is no longer available.');
        }
        $lockedFirstInstallment = is_array($scheme->filter) && (int) ($scheme->filter['uni_parva'] ?? 0) === 1;
        if (!$lockedFirstInstallment && $selection->firstInstallment >= $cart->total) {
            throw new CheckoutValidationException('The first installment is invalid.');
        }
        try {
            $calculation = $this->calculator->calculateScheme($shop, $cart->total, $scheme, $selection->firstInstallment);
        } catch (UnavailableSchemeException $exception) {
            throw new CheckoutValidationException('The selected financing scheme cannot be calculated.', 0, $exception);
        }
        $validatedCustomer = $this->customers->validate($shop, $customer, $posted);
        $accepted = $this->consents->validate($shop, $posted['consent'] ?? []);
        $acceptedConsents = array_values(array_filter($this->consents->normalize($shop), static function (array $consent) use ($accepted): bool {
            return in_array($consent['id'], $accepted, true);
        }));

        return new ValidatedPaymentRequest($calculation, $validatedCustomer, $accepted, $fingerprint, $acceptedConsents);
    }

    /** @param AvailableScheme[] $schemes */
    private function findScheme(array $schemes, SchemeSelection $selection): ?AvailableScheme
    {
        foreach ($schemes as $scheme) {
            if ($scheme->type === $selection->schemeType
                && $scheme->months === $selection->months
                && $scheme->filterId === $selection->filterId
                && hash_equals($scheme->kopCode, $selection->kopCode)
            ) {
                return $scheme;
            }
        }

        return null;
    }
}
