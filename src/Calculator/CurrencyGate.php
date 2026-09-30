<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Calculator;

final class CurrencyGate
{
    public function supports(string $currencyIso): bool
    {
        return strtoupper(trim($currencyIso)) === 'EUR';
    }
}
