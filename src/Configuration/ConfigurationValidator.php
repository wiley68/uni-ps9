<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Configuration;

final class ConfigurationValidator
{
    public const ERROR_UNICID_REQUIRED = 'unicid_required';
    public const ERROR_UNICID_INVALID = 'unicid_invalid';
    public const ERROR_SECRET_REQUIRED = 'secret_required';
    public const ERROR_SECRET_TOO_LONG = 'secret_too_long';
    public const ERROR_BUTTON_ACTION_INVALID = 'button_action_invalid';
    public const ERROR_BUTTON_TOP_SPACING_INVALID = 'button_top_spacing_invalid';

    /**
     * @param mixed $buttonTopSpacing Raw form value (string|int|float|bool expected; validated below)
     *
     * @return string[]
     */
    public function validate(
        string $unicid,
        string $secret,
        bool $hasStoredSecret,
        string $productButtonAction = ConfigurationRepository::DEFAULT_PRODUCT_BUTTON_ACTION,
        mixed $buttonTopSpacing = ConfigurationRepository::DEFAULT_BUTTON_TOP_SPACING
    ): array {
        $errors = [];

        if ($unicid === '') {
            $errors[] = self::ERROR_UNICID_REQUIRED;
        } elseif (strlen($unicid) > 36 || !$this->isUuid($unicid)) {
            $errors[] = self::ERROR_UNICID_INVALID;
        }

        if ($secret === '' && !$hasStoredSecret) {
            $errors[] = self::ERROR_SECRET_REQUIRED;
        } elseif (strlen($secret) > 64) {
            $errors[] = self::ERROR_SECRET_TOO_LONG;
        }

        if (!in_array($productButtonAction, [
            ConfigurationRepository::BUTTON_ACTION_ADD_TO_CART,
            ConfigurationRepository::BUTTON_ACTION_BUY,
        ], true)) {
            $errors[] = self::ERROR_BUTTON_ACTION_INVALID;
        }

        if (
            !is_scalar($buttonTopSpacing)
            || preg_match('/^\d+$/', (string) $buttonTopSpacing) !== 1
            || (int) $buttonTopSpacing > ConfigurationRepository::MAX_BUTTON_TOP_SPACING
        ) {
            $errors[] = self::ERROR_BUTTON_TOP_SPACING_INVALID;
        }

        return $errors;
    }

    private function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value
        ) === 1;
    }
}
