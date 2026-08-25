<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Product;

use PrestaShop\Module\Unipayment\Calculator\AmountDisplayFormatter;
use PrestaShop\Module\Unipayment\Calculator\AvailableScheme;
use PrestaShop\Module\Unipayment\Calculator\CalculationResult;
use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\CurrencyGate;
use PrestaShop\Module\Unipayment\Calculator\ProductContext;
use PrestaShop\Module\Unipayment\Calculator\UnavailableSchemeException;

final class ProductPopupCalculator
{
    /** @var Calculator */
    private $calculator;
    /** @var CurrencyGate */
    private $currencyGate;
    /** @var AmountDisplayFormatter */
    private $amounts;

    public function __construct(
        Calculator $calculator,
        ?CurrencyGate $currencyGate = null,
        ?AmountDisplayFormatter $amounts = null
    ) {
        $this->calculator = $calculator;
        $this->currencyGate = $currencyGate ?? new CurrencyGate();
        $this->amounts = $amounts ?? new AmountDisplayFormatter();
    }

    /** @param array<string, mixed> $shop @return array<string, mixed> */
    public function calculate(
        array $shop,
        ProductContext $product,
        string $currencyIso,
        string $popupType,
        string $schemeType,
        string $kopCode,
        int $months,
        int $filterId,
        string $schemeKey,
        float $firstInstallment
    ): array {
        $allowedTypes = $popupType === 'standard' ? ['standard', 'promo'] : ($popupType === 'promo' ? ['promo'] : []);
        if (!$this->currencyGate->supports($shop, $currencyIso) || !in_array($schemeType, $allowedTypes, true)) {
            throw new UnavailableSchemeException('The selected financing scheme is unavailable.');
        }

        $scheme = $this->findScheme($this->calculator->availableSchemes($shop, $product, $schemeType), $kopCode, $months, $filterId);
        if ($scheme === null || !hash_equals(ProductPopupSchemeList::key($scheme), $schemeKey)) {
            throw new UnavailableSchemeException('The selected financing scheme is unavailable.');
        }

        $result = $this->calculator->calculateScheme($shop, $product->price, $scheme, $firstInstallment);

        return $this->present($shop, $result);
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

    /** @param array<string, mixed> $shop @return array<string, mixed> */
    private function present(array $shop, CalculationResult $result): array
    {
        $scheme = $result->scheme;

        return [
            'scheme_key' => ProductPopupSchemeList::key($scheme),
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
            'price_display' => $this->amountDisplay($result->price, $shop),
            'financed_amount_display' => $this->amountDisplay($result->financedAmount, $shop),
            'monthly_installment_display' => $this->amountDisplay($result->monthlyInstallment, $shop),
            'total_payable_display' => $this->amountDisplay($result->totalPayable, $shop),
            'glp_display' => number_format(abs($result->glp), 2, '.', ''),
            'gpr_display' => number_format(abs($result->gpr), 2, '.', ''),
        ];
    }

    /** @param array<string, mixed> $shop @return array{primary:string,secondary:string,dual:bool} */
    private function amountDisplay(float $amount, array $shop): array
    {
        return $this->amounts->format($amount, $shop);
    }
}
