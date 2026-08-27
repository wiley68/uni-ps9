<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Cart;

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\CurrencyGate;
use PrestaShop\Module\Unipayment\Calculator\InstallmentLabelFormatter;
use PrestaShop\Module\Unipayment\Calculator\UnavailableSchemeException;
use PrestaShop\Module\Unipayment\Product\ProductPopupSchemeList;

final class CartCalculatorPresenter
{
    /** @var CartSchemeResolver */
    private $resolver;

    /** @var Calculator */
    private $calculator;

    /** @var CurrencyGate */
    private $currencyGate;

    public function __construct(CartSchemeResolver $resolver, Calculator $calculator, ?CurrencyGate $currencyGate = null)
    {
        $this->resolver = $resolver;
        $this->calculator = $calculator;
        $this->currencyGate = $currencyGate ?? new CurrencyGate();
    }

    /** @param array<string, mixed> $shop @return array<string, mixed>|null */
    public function present(array $shop, CartContext $cart, string $currencyIso): ?array
    {
        if (!$this->currencyGate->supports($shop, $currencyIso)) {
            return null;
        }
        $resolution = $this->resolver->resolve($shop, $cart);
        $offers = [];
        foreach (['standard' => $resolution->standardSchemes, 'promo' => $resolution->promoSchemes] as $buttonType => $schemes) {
            $preferred = $buttonType === 'standard' ? $resolution->standardOffer : $resolution->promoOffer;
            if ($preferred === null || $schemes === []) {
                continue;
            }
            $rows = [];
            foreach ($schemes as $scheme) {
                if ($scheme->firstInstallmentAmbiguous) {
                    continue;
                }
                try {
                    $result = $this->calculator->calculateScheme($shop, $cart->total, $scheme);
                } catch (UnavailableSchemeException $exception) {
                    continue;
                }
                $rows[] = [
                    'key' => ProductPopupSchemeList::key($scheme),
                    'scheme_key' => ProductPopupSchemeList::key($scheme),
                    'months' => $scheme->months,
                    'filter_id' => $scheme->filterId,
                    'kop_code' => $scheme->kopCode,
                    'scheme_type' => $scheme->type,
                    'description' => ProductPopupSchemeList::description($shop, $scheme),
                    'first_installment' => $result->firstInstallment->amount,
                    'first_installment_locked' => $result->firstInstallment->locked,
                    'financed_amount' => $result->financedAmount,
                    'monthly_installment' => $result->monthlyInstallment,
                    'total_due' => $result->totalPayable,
                    'glp' => $result->glp,
                    'gpr' => $result->gpr,
                ];
            }
            if ($rows !== []) {
                $offers[$buttonType] = [
                    'type' => $buttonType,
                    'months' => $preferred->months,
                    'preferred_scheme_key' => ProductPopupSchemeList::keyFromParts(
                        $preferred->type,
                        $preferred->kopCode,
                        $preferred->months,
                        $preferred->filterId
                    ),
                    'monthly_installment' => $preferred->monthlyInstallment,
                    'installment_label' => (new InstallmentLabelFormatter())->format(
                        $preferred->months,
                        $preferred->monthlyInstallment,
                        (int) ($shop['uni_eur'] ?? 0)
                    ),
                    'schemes' => $rows,
                ];
            }
        }
        if ($offers === []) {
            return null;
        }

        return [
            'cart_total' => $cart->total,
            'line_count' => count($cart->lines),
            'currency_iso' => strtoupper($currencyIso),
            'show_installment' => $this->flag($shop['uni_vnoska'] ?? 0),
            'show_first_installment' => $this->flag($shop['uni_first_vnoska'] ?? 0),
            'dark_button' => $this->flag($shop['uni_type_button'] ?? 0),
            'buttons_in_row' => (int) ($shop['uni_button_row'] ?? 1) === 1,
            'button_width' => $this->dimension($shop['uni_button_width'] ?? 290, 290),
            'button_height' => $this->dimension($shop['uni_button_height'] ?? 56, 56),
            'heading' => trim((string) ($shop['uni_zaglavie'] ?? '')),
            'offers' => $offers,
        ];
    }

    /** @param mixed $value */
    private function flag($value): bool
    {
        return in_array($value, [1, '1', true, 'yes', 'on'], true);
    }

    /** @param mixed $value */
    private function dimension($value, int $fallback): int
    {
        $dimension = (int) $value;

        return $dimension >= 40 && $dimension <= 800 ? $dimension : $fallback;
    }
}
