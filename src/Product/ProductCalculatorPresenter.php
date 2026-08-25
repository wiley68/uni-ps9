<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Product;

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\CurrencyGate;
use PrestaShop\Module\Unipayment\Calculator\InstallmentLabelFormatter;
use PrestaShop\Module\Unipayment\Calculator\ProductContext;
use PrestaShop\Module\Unipayment\Calculator\UnavailableSchemeException;

final class ProductCalculatorPresenter
{
    /** @var Calculator */
    private $calculator;

    /** @var CurrencyGate */
    private $currencyGate;

    /** @var ProductPopupSchemeList */
    private $popupSchemes;

    public function __construct(Calculator $calculator, ?CurrencyGate $currencyGate = null)
    {
        $this->calculator = $calculator;
        $this->currencyGate = $currencyGate ?? new CurrencyGate();
        $this->popupSchemes = new ProductPopupSchemeList($calculator);
    }

    /** @param array<string, mixed> $shop @return array<string, mixed>|null */
    public function present(array $shop, ProductContext $product, string $currencyIso): ?array
    {
        if (!$this->currencyGate->supports($shop, $currencyIso)) {
            return null;
        }

        $preferred = $this->calculator->resolvePreferredOffers($shop, $product);
        $offers = [];
        foreach (['standard', 'promo'] as $type) {
            if ($preferred[$type] === null) {
                continue;
            }
            $schemes = [];
            foreach ($this->popupSchemes->schemes($shop, $product, $type) as $scheme) {
                try {
                    $result = $this->calculator->calculateScheme($shop, $product->price, $scheme, 0.0);
                } catch (UnavailableSchemeException $exception) {
                    continue;
                }
                $schemes[] = [
                    'key' => ProductPopupSchemeList::key($scheme),
                    'scheme_type' => $scheme->type,
                    'months' => $scheme->months,
                    'filter_id' => $scheme->filterId,
                    'kop_code' => $scheme->kopCode,
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
            if ($schemes === []) {
                continue;
            }
            $offers[$type] = [
                'type' => $type,
                'months' => $preferred[$type]->months,
                'preferred_scheme_key' => ProductPopupSchemeList::keyFromParts(
                    $preferred[$type]->type,
                    $preferred[$type]->kopCode,
                    $preferred[$type]->months,
                    $preferred[$type]->filterId
                ),
                'monthly_installment' => $preferred[$type]->monthlyInstallment,
                'installment_label' => (new InstallmentLabelFormatter())->format(
                    $preferred[$type]->months,
                    $preferred[$type]->monthlyInstallment,
                    (int) ($shop['uni_eur'] ?? 0)
                ),
                'schemes' => $schemes,
            ];
        }

        if ($offers === []) {
            return null;
        }

        return [
            'product_id' => $product->productId,
            'price' => $product->price,
            'currency_iso' => strtoupper($currencyIso),
            'show_installment' => $this->flag($shop['uni_vnoska'] ?? 0),
            'button_type' => $this->flag($shop['uni_vnoska'] ?? 0) ? 'standard' : 'image',
            'show_first_installment' => $this->flag($shop['uni_first_vnoska'] ?? 0),
            'dark_button' => $this->flag($shop['uni_type_button'] ?? 0),
            'design' => $this->flag($shop['uni_type_button'] ?? 0) ? 'alternative' : 'standard',
            'buttons_in_row' => (int) ($shop['uni_button_row'] ?? 1) === 1,
            'button_width' => $this->dimension($shop['uni_button_width'] ?? 290, 290, 100, 600),
            'button_height' => $this->dimension($shop['uni_button_height'] ?? 56, 56, 30, 120),
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
    private function dimension($value, int $fallback, int $minimum, int $maximum): int
    {
        $dimension = (int) $value;

        return $dimension >= $minimum && $dimension <= $maximum ? $dimension : $fallback;
    }
}
