<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Checkout;

use PrestaShop\Module\Unipayment\Calculator\AmountDisplayFormatter;
use PrestaShop\Module\Unipayment\Calculator\AvailableScheme;
use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\CurrencyGate;
use PrestaShop\Module\Unipayment\Calculator\UnavailableSchemeException;
use PrestaShop\Module\Unipayment\Cart\CartContext;
use PrestaShop\Module\Unipayment\Cart\CartSchemeResolver;

/**
 * Live checkout recalculation for scheme + first installment (Woo checkout AJAX parity).
 */
final class CheckoutPaymentCalculator
{
    /** @var Calculator */
    private $calculator;
    /** @var CartSchemeResolver */
    private $cartResolver;
    /** @var CurrencyGate */
    private $currencyGate;
    /** @var AmountDisplayFormatter */
    private $amounts;

    public function __construct(
        Calculator $calculator,
        CartSchemeResolver $cartResolver,
        ?CurrencyGate $currencyGate = null,
        ?AmountDisplayFormatter $amounts = null
    ) {
        $this->calculator = $calculator;
        $this->cartResolver = $cartResolver;
        $this->currencyGate = $currencyGate ?? new CurrencyGate();
        $this->amounts = $amounts ?? new AmountDisplayFormatter();
    }

    /**
     * @param array<string, mixed> $shop
     * @return array<string, mixed>
     */
    public function calculate(array $shop, CartContext $cart, string $currencyIso, array $posted): array
    {
        if (!$this->currencyGate->supports($shop, $currencyIso)) {
            throw new UnavailableSchemeException('The selected financing scheme is unavailable.');
        }
        $selection = SchemeSelection::fromPosted($posted);
        $scheme = $this->findScheme(
            $this->cartResolver->unifiedSchemes($this->cartResolver->resolve($shop, $cart)),
            $selection
        );
        if ($scheme === null) {
            throw new UnavailableSchemeException('The selected financing scheme is unavailable.');
        }
        $locked = is_array($scheme->filter) && (int) ($scheme->filter['uni_parva'] ?? 0) === 1;
        if (!$locked && $selection->firstInstallment >= $cart->total) {
            throw new UnavailableSchemeException('The first installment is invalid.');
        }
        $result = $this->calculator->calculateScheme($shop, $cart->total, $scheme, $selection->firstInstallment);

        return [
            'scheme_key' => SchemeSelection::key($scheme->type, $scheme->months, $scheme->filterId),
            'scheme_type' => $scheme->type,
            'kop_code' => $scheme->kopCode,
            'months' => $scheme->months,
            'filter_id' => $scheme->filterId,
            'price' => $result->price,
            'first_installment' => $result->firstInstallment->amount,
            'first_installment_locked' => $result->firstInstallment->locked,
            'show_first_installment' => $result->firstInstallment->visible,
            'financed_amount' => $result->financedAmount,
            'monthly_installment' => $result->monthlyInstallment,
            'total_payable' => $result->totalPayable,
            'glp' => $result->glp,
            'gpr' => $result->gpr,
            'price_display' => $this->amounts->format($result->price, $shop),
            'financed_amount_display' => $this->amounts->format($result->financedAmount, $shop),
            'monthly_installment_display' => $this->amounts->format($result->monthlyInstallment, $shop),
            'total_payable_display' => $this->amounts->format($result->totalPayable, $shop),
            'glp_display' => number_format(abs($result->glp), 2, '.', ''),
            'gpr_display' => number_format(abs($result->gpr), 2, '.', ''),
        ];
    }

    /** @param AvailableScheme[] $schemes */
    private function findScheme(array $schemes, SchemeSelection $selection): ?AvailableScheme
    {
        foreach ($schemes as $scheme) {
            if (
                $scheme->type === $selection->schemeType
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
