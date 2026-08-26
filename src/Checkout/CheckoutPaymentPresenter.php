<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Checkout;

use PrestaShop\Module\Unipayment\Calculator\AmountDisplayFormatter;
use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\CurrencyGate;
use PrestaShop\Module\Unipayment\Calculator\UnavailableSchemeException;
use PrestaShop\Module\Unipayment\Cart\CartContext;
use PrestaShop\Module\Unipayment\Cart\CartSchemeResolver;
use PrestaShop\Module\Unipayment\Product\ProductPopupSchemeList;

final class CheckoutPaymentPresenter
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
    /** @var ConsentResolver */
    private $consents;
    /** @var AmountDisplayFormatter */
    private $amounts;

    public function __construct(
        Calculator $calculator,
        CartSchemeResolver $cartResolver,
        CurrencyGate $currencyGate,
        CartSnapshot $snapshot,
        CartSnapshotSigner $signer,
        ConsentResolver $consents,
        ?AmountDisplayFormatter $amounts = null
    ) {
        $this->calculator = $calculator;
        $this->cartResolver = $cartResolver;
        $this->currencyGate = $currencyGate;
        $this->snapshot = $snapshot;
        $this->signer = $signer;
        $this->consents = $consents;
        $this->amounts = $amounts ?? new AmountDisplayFormatter();
    }

    /** @param array<string, mixed> $shop @return array<string, mixed>|null */
    public function present(bool $operational, array $shop, CartContext $cart, string $currencyIso, ?array $preference = null): ?array
    {
        if (!$operational || !$this->currencyGate->supports($shop, $currencyIso)) {
            return null;
        }
        $resolution = $this->cartResolver->resolve($shop, $cart);
        $schemes = [];
        $zeroInterestPromoKey = null;
        $zeroInterestPromoMonths = -1;
        // v2.0.1 parity: unifiedSchemes preserves audited aggregation.
        // Deferred v2.0.2 (Woo+PS8+PS9): standard list should also expose eligible promo schemes.
        foreach ($this->cartResolver->unifiedSchemes($resolution) as $scheme) {
            try {
                $result = $this->calculator->calculateScheme($shop, $cart->total, $scheme);
            } catch (UnavailableSchemeException $exception) {
                continue;
            }
            $key = SchemeSelection::key($scheme->type, $scheme->months, $scheme->filterId);
            $zeroInterest = $scheme->type === 'promo'
                && abs((float) ($scheme->coefficient['interestPercent'] ?? -1)) <= 0.00001;
            if ($zeroInterest && $scheme->months > $zeroInterestPromoMonths) {
                $zeroInterestPromoMonths = $scheme->months;
                $zeroInterestPromoKey = $key;
            }
            $schemes[] = [
                'key' => $key,
                'scheme_type' => $scheme->type,
                'kop_code' => $scheme->kopCode,
                'months' => $scheme->months,
                'filter_id' => $scheme->filterId,
                'description' => ProductPopupSchemeList::description($shop, $scheme),
                'zero_interest' => $zeroInterest,
                'first_installment' => $result->firstInstallment->amount,
                'first_installment_locked' => $result->firstInstallment->locked,
                'show_first_installment' => $result->firstInstallment->visible,
                'financed_amount' => $result->financedAmount,
                'monthly_installment' => $result->monthlyInstallment,
                'total_payable' => $result->totalPayable,
                'glp' => $result->glp,
                'gpr' => $result->gpr,
                'financed_amount_display' => $this->amounts->format($result->financedAmount, $shop),
                'monthly_installment_display' => $this->amounts->format($result->monthlyInstallment, $shop),
                'total_payable_display' => $this->amounts->format($result->totalPayable, $shop),
                'glp_display' => number_format(abs($result->glp), 2, '.', ''),
                'gpr_display' => number_format(abs($result->gpr), 2, '.', ''),
            ];
        }
        if ($schemes === []) {
            return null;
        }
        // Default: longest available 0% promo scheme. Fallback: CP preferred months, then first scheme.
        $defaultKey = $schemes[0]['key'];
        if ($zeroInterestPromoKey !== null) {
            $defaultKey = $zeroInterestPromoKey;
        } else {
            $preferred = $resolution->standardOffer ?? $resolution->promoOffer;
            if ($preferred !== null) {
                foreach ($schemes as $scheme) {
                    if ($scheme['months'] === $preferred->months && $scheme['kop_code'] === $preferred->kopCode) {
                        $defaultKey = $scheme['key'];
                        break;
                    }
                }
            }
        }
        $preferenceMatched = false;
        $preferredFirstInstallment = 0.0;
        $preferenceUnresolved = false;
        if ($preference !== null) {
            $resolved = CheckoutSchemeIdentity::resolve($schemes, $preference);
            if ($resolved !== null) {
                $defaultKey = (string) $resolved['key'];
                if (!empty($resolved['first_installment_locked'])) {
                    $preferredFirstInstallment = (float) $resolved['first_installment'];
                } else {
                    $requestedFirstInstallment = is_numeric($preference['first_installment'] ?? null)
                        ? max(0.0, (float) $preference['first_installment'])
                        : 0.0;
                    $preferredFirstInstallment = $requestedFirstInstallment < $cart->total ? $requestedFirstInstallment : 0.0;
                }
                $preferenceMatched = true;
            } else {
                $preferenceUnresolved = true;
            }
        }
        $fingerprint = $this->snapshot->fingerprint($cart, $currencyIso);
        $currencyMode = (int) ($shop['uni_eur'] ?? 0);

        return [
            'cart_total' => $cart->total,
            'cart_total_display' => $this->amounts->format($cart->total, $shop),
            'currency_iso' => strtoupper($currencyIso),
            'currency_mode' => $currencyMode,
            'currency_dual' => in_array($currencyMode, [1, 2], true),
            'schemes' => $schemes,
            'default_scheme_key' => $defaultKey,
            'default_first_installment' => $preferredFirstInstallment,
            'preselect_payment' => $preferenceMatched,
            'preference_unresolved' => $preferenceUnresolved,
            'cart_snapshot' => $this->signer->sign($fingerprint),
            'show_first_installment' => in_array($shop['uni_first_vnoska'] ?? 0, [1, '1', true, 'yes', 'on'], true),
            'process2' => (int) ($shop['uni_proces'] ?? 0) === 1,
            'consents' => $this->consents->normalize($shop),
            'has_schemes' => true,
        ];
    }
}
