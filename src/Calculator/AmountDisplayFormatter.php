<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Calculator;

/**
 * Dual-currency amount display used by product/cart/checkout (Woo uni_eur parity).
 */
final class AmountDisplayFormatter
{
    /** @var CurrencyDisplayLabel */
    private $labels;

    public function __construct(?CurrencyDisplayLabel $labels = null)
    {
        $this->labels = $labels ?? new CurrencyDisplayLabel();
    }

    /** @param array<string, mixed> $shop @return array{primary:string,secondary:string,dual:bool} */
    public function format(float $amount, array $shop): array
    {
        $mode = (int) ($shop['uni_eur'] ?? 0);
        $primaryCurrency = in_array($mode, [2, 3], true) ? 'EUR' : 'BGN';
        $primary = number_format(abs($amount), 2, '.', '') . ' ' . $this->labels->forAmount($primaryCurrency);
        if (!in_array($mode, [1, 2], true)) {
            return ['primary' => $primary, 'secondary' => '', 'dual' => false];
        }
        $secondary = $mode === 1 ? round($amount / 1.95583, 2) : round($amount * 1.95583, 2);
        $secondaryCurrency = $mode === 1 ? 'EUR' : 'BGN';

        return [
            'primary' => $primary,
            'secondary' => number_format(abs($secondary), 2, '.', '') . ' ' . $this->labels->forAmount($secondaryCurrency),
            'dual' => true,
        ];
    }
}
