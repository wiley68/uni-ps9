<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Calculator;

/**
 * Woo-compatible installment button label (e.g. "12 x 97.49 евро").
 */
final class InstallmentLabelFormatter
{
    /** @var CurrencyDisplayLabel */
    private $labels;

    public function __construct(?CurrencyDisplayLabel $labels = null)
    {
        $this->labels = $labels ?? new CurrencyDisplayLabel();
    }

    public function format(int $months, float $monthlyInstallment, int $currencyMode): string
    {
        if ($currencyMode === 1 || $currencyMode === 2) {
            $secondary = $currencyMode === 1
                ? round($monthlyInstallment / 1.95583, 2)
                : round($monthlyInstallment * 1.95583, 2);
            $primaryIso = $currencyMode === 1 ? 'BGN' : 'EUR';
            $secondaryIso = $currencyMode === 1 ? 'EUR' : 'BGN';

            return sprintf(
                '%d x %s %s (%s %s)',
                $months,
                number_format($monthlyInstallment, 2, '.', ''),
                $this->labels->forButton($primaryIso, true),
                number_format($secondary, 2, '.', ''),
                $this->labels->forButton($secondaryIso, true)
            );
        }

        return sprintf(
            '%d x %s %s',
            $months,
            number_format($monthlyInstallment, 2, '.', ''),
            $this->labels->forAmount($currencyMode === 3 ? 'EUR' : 'BGN')
        );
    }
}
