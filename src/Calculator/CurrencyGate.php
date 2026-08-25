<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Calculator;

final class CurrencyGate
{
    /** @param array<string, mixed> $shop */
    public function supports(array $shop, string $currencyIso): bool
    {
        $iso = strtoupper(trim($currencyIso));
        $expected = in_array((int) ($shop['uni_eur'] ?? 0), [2, 3], true) ? 'EUR' : 'BGN';

        return in_array($iso, ['BGN', 'EUR'], true) && $iso === $expected;
    }
}
