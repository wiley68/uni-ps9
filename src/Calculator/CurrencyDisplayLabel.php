<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Calculator;

/** Financing amounts are displayed in EUR only. */
final class CurrencyDisplayLabel
{
    private const DOMAIN = 'Modules.Unipayment.Shop';

    public function forAmount(): string
    {
        return $this->trans('евро');
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
