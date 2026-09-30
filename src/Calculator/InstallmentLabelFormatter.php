<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Calculator;

/** EUR installment button label. */
final class InstallmentLabelFormatter
{
    private CurrencyDisplayLabel $labels;

    public function __construct(?CurrencyDisplayLabel $labels = null)
    {
        $this->labels = $labels ?? new CurrencyDisplayLabel();
    }

    public function format(int $months, float $monthlyInstallment): string
    {
        return sprintf('%d x %s %s', $months, number_format($monthlyInstallment, 2, '.', ''), $this->labels->forAmount());
    }
}
