<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Configuration;

use PrestaShop\PrestaShop\Adapter\SymfonyContainer;

/**
 * Reads BO configuration submit fields from the real request sources.
 *
 * Tools::getValue() only reads $_POST/$_GET (except in kernel test env). In the
 * PS9 Symfony admin, the authoritative ParameterBag may be the only place some
 * fields are reliably visible — so we check $_POST first, then the request stack.
 */
final class AdminConfigurationRequestReader
{
    /** @return array<string, mixed> */
    public function allPost(): array
    {
        $fromSuperglobal = is_array($_POST) ? $_POST : [];
        $fromRequest = $this->symfonyRequestParameters();

        return $fromRequest + $fromSuperglobal;
    }

    public function isConfigurationSubmit(): bool
    {
        return $this->hasSubmitKey('submitUnipaymentConfiguration');
    }

    public function isBankRefreshSubmit(): bool
    {
        // POST-only: Tools::isSubmit() also checks GET and can false-trigger refresh.
        return array_key_exists('submitUnipaymentRefresh', $_POST)
            || array_key_exists('submitUnipaymentRefresh', $this->symfonyRequestParameters());
    }

    public function getUnicid(): string
    {
        return trim((string) $this->getField('UNIPAYMENT_UNICID', ''));
    }

    public function getSecret(): string
    {
        return trim((string) $this->getField('UNIPAYMENT_SECRET', ''));
    }

    public function hasSecretField(): bool
    {
        return array_key_exists('UNIPAYMENT_SECRET', $_POST)
            || array_key_exists('UNIPAYMENT_SECRET', $this->symfonyRequestParameters());
    }

    public function hasUnicidField(): bool
    {
        return array_key_exists('UNIPAYMENT_UNICID', $_POST)
            || array_key_exists('UNIPAYMENT_UNICID', $this->symfonyRequestParameters());
    }

    public function secretInPost(): bool
    {
        return array_key_exists('UNIPAYMENT_SECRET', $_POST);
    }

    public function secretInRequestSuperglobal(): bool
    {
        return array_key_exists('UNIPAYMENT_SECRET', $_REQUEST);
    }

    public function secretInSymfonyRequest(): bool
    {
        return array_key_exists('UNIPAYMENT_SECRET', $this->symfonyRequestParameters());
    }

    public function secretViaToolsGetValueLength(): int
    {
        return strlen(trim((string) \Tools::getValue('UNIPAYMENT_SECRET', '')));
    }

    /** @param mixed $default */
    public function getField(string $key, $default = null)
    {
        if (array_key_exists($key, $_POST)) {
            return $_POST[$key];
        }

        $symfony = $this->symfonyRequestParameters();
        if (array_key_exists($key, $symfony)) {
            return $symfony[$key];
        }

        return $default;
    }

    private function hasSubmitKey(string $key): bool
    {
        if (array_key_exists($key, $_POST)) {
            return true;
        }

        return array_key_exists($key, $this->symfonyRequestParameters());
    }

    /** @return array<string, mixed> */
    private function symfonyRequestParameters(): array
    {
        try {
            $container = SymfonyContainer::getInstance();
            if ($container === null || !$container->has('request_stack')) {
                return [];
            }
            $stack = $container->get('request_stack');
            $request = $stack->getCurrentRequest();
            if ($request === null) {
                return [];
            }

            return $request->request->all();
        } catch (\Throwable $exception) {
            return [];
        }
    }
}
