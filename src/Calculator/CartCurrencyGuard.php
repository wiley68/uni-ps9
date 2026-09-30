<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Calculator;

/** Resolves the EUR transaction currency without changing cart or context. */
final class CartCurrencyGuard
{
    public function supportedIso(\Cart $cart, \Context $context): string
    {
        $idCurrency = (int) $cart->id_currency;
        if ($idCurrency <= 0 || !$context->currency instanceof \Currency) {
            return '';
        }
        $cartCurrency = new \Currency($idCurrency);
        if (!\Validate::isLoadedObject($cartCurrency)
            || !\Validate::isLoadedObject($context->currency)
            || (int) $context->currency->id !== $idCurrency
        ) {
            return '';
        }
        $cartIso = strtoupper(trim((string) $cartCurrency->iso_code));
        $contextIso = strtoupper(trim((string) $context->currency->iso_code));

        return $cartIso === $contextIso && (new CurrencyGate())->supports($cartIso) ? $cartIso : '';
    }
}
