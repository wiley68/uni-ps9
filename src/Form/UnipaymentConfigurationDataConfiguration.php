<?php

/**
 * Copyright since 2024 Ilko Ivanov
 *
 * @author    Ilko Ivanov
 * @copyright Ilko Ivanov
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Form;

use PrestaShop\PrestaShop\Core\Configuration\DataConfigurationInterface;
use Configuration;

/**
 * Четене/запис на UNIPAYMENT_* в таблицата {@see Configuration} (PrestaShop DataConfiguration).
 *
 * @implements DataConfigurationInterface
 */
final class UnipaymentConfigurationDataConfiguration implements DataConfigurationInterface
{
    public const UNIPAYMENT_STATUS = 'UNIPAYMENT_STATUS';
    public const UNIPAYMENT_UNICID = 'UNIPAYMENT_UNICID';
    public const UNIPAYMENT_REKLAMA = 'UNIPAYMENT_REKLAMA';
    public const UNIPAYMENT_CART = 'UNIPAYMENT_CART';
    public const UNIPAYMENT_DEBUG = 'UNIPAYMENT_DEBUG';
    public const UNIPAYMENT_GAP = 'UNIPAYMENT_GAP';

    /**
     * @return array<string, mixed>
     */
    public function getConfiguration(): array
    {
        $return = [];
        $gap = Configuration::get(static::UNIPAYMENT_GAP);

        $return['UNIPAYMENT_STATUS'] = Configuration::get(static::UNIPAYMENT_STATUS);
        $return['UNIPAYMENT_UNICID'] = Configuration::get(static::UNIPAYMENT_UNICID);
        $return['UNIPAYMENT_REKLAMA'] = Configuration::get(static::UNIPAYMENT_REKLAMA);
        $return['UNIPAYMENT_CART'] = Configuration::get(static::UNIPAYMENT_CART);
        $return['UNIPAYMENT_DEBUG'] = Configuration::get(static::UNIPAYMENT_DEBUG);
        $return['UNIPAYMENT_GAP'] = ($gap === false || $gap === '' || $gap === null) ? 0 : (float) $gap;

        return $return;
    }

    /**
     * @param array<string, mixed> $configuration
     *
     * @return array<int, string> празно при успех, иначе съобщения за грешки
     */
    public function updateConfiguration(array $configuration): array
    {
        $errors = [];

        if ($this->validateConfiguration($configuration)) {
            $gap = $configuration['UNIPAYMENT_GAP'] ?? 0;
            if ($gap === '' || $gap === null) {
                $gap = 0;
            }

            Configuration::updateValue(static::UNIPAYMENT_STATUS, $configuration['UNIPAYMENT_STATUS']);
            Configuration::updateValue(static::UNIPAYMENT_UNICID, $configuration['UNIPAYMENT_UNICID']);
            Configuration::updateValue(static::UNIPAYMENT_REKLAMA, $configuration['UNIPAYMENT_REKLAMA']);
            Configuration::updateValue(static::UNIPAYMENT_CART, $configuration['UNIPAYMENT_CART']);
            Configuration::updateValue(static::UNIPAYMENT_DEBUG, $configuration['UNIPAYMENT_DEBUG']);
            Configuration::updateValue(static::UNIPAYMENT_GAP, (float) $gap);
        }

        /* Errors are returned here. */
        return $errors;
    }

    /**
     * Ensure the parameters passed are valid.
     *
     * @return bool Returns true if no exception are thrown
     */
    public function validateConfiguration(array $configuration): bool
    {
        return
            isset($configuration['UNIPAYMENT_STATUS']);
    }
}
