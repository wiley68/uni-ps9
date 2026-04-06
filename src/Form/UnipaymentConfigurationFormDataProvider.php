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
use PrestaShop\PrestaShop\Core\Form\FormDataProviderInterface;

/**
 * Данни за Symfony формата на модула чрез {@see UnipaymentConfigurationDataConfiguration}.
 *
 * @implements FormDataProviderInterface
 */
class UnipaymentConfigurationFormDataProvider implements FormDataProviderInterface
{
    public function __construct(
        private DataConfigurationInterface $unipaymentConfigurationDataConfiguration,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->unipaymentConfigurationDataConfiguration->getConfiguration();
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<int, string>
     */
    public function setData(array $data): array
    {
        return $this->unipaymentConfigurationDataConfiguration->updateConfiguration($data);
    }
}
