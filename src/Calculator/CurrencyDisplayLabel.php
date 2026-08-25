<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Calculator;

/**
 * Display currency suffixes aligned with Woo (лв. / евро / лева).
 * Bulgarian source strings; ISO codes stay EUR/BGN for business logic.
 */
final class CurrencyDisplayLabel
{
    private const DOMAIN = 'Modules.Unipayment.Shop';

    /** Popup / amount display suffixes (Woo mtuc_get_currency_display_config). */
    public function forAmount(string $iso): string
    {
        $iso = strtoupper(trim($iso));
        if ($iso === 'EUR') {
            return $this->trans('евро');
        }
        if ($iso === 'BGN') {
            return $this->trans('лв.');
        }

        return $iso;
    }

    /** Button dual-currency suffixes (Woo mtuc_format_installment_price_text uses лева/евро). */
    public function forButton(string $iso, bool $dual): string
    {
        $iso = strtoupper(trim($iso));
        if (!$dual) {
            return $this->forAmount($iso);
        }
        if ($iso === 'EUR') {
            return $this->trans('евро');
        }
        if ($iso === 'BGN') {
            return $this->trans('лева');
        }

        return $iso;
    }

    /** @deprecated Use forAmount(); kept for call-site compatibility. */
    public function forIso(string $iso): string
    {
        return $this->forAmount($iso);
    }

    private function trans(string $message): string
    {
        $translator = $this->translator();
        if ($translator === null) {
            return $message;
        }

        return (string) $translator->trans($message, [], self::DOMAIN);
    }

    /**
     * @return object|null Translator with a trans() method, or null outside PS context
     */
    private function translator()
    {
        if (!class_exists(\Context::class)) {
            return null;
        }

        $context = \Context::getContext();
        if ($context === null || !method_exists($context, 'getTranslator')) {
            return null;
        }

        $translator = $context->getTranslator();
        if ($translator === null || !method_exists($translator, 'trans')) {
            return null;
        }

        return $translator;
    }
}
