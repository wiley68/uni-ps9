<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Calculator;

/** Single EUR amount display for financing presentation. */
final class AmountDisplayFormatter
{
    private CurrencyDisplayLabel $labels;

    public function __construct(?CurrencyDisplayLabel $labels = null)
    {
        $this->labels = $labels ?? new CurrencyDisplayLabel();
    }

    /** @return array{primary:string} */
    public function format(float $amount): array
    {
        return ['primary' => number_format(abs($amount), 2, '.', '') . ' ' . $this->labels->forAmount()];
    }
}
