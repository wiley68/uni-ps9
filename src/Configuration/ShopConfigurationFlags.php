<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Configuration;

final class ShopConfigurationFlags
{
    /**
     * SmartUCF test environment — CP field uni_env (0 = test), same as Woo mtuc_is_test_environment().
     *
     * @param array<string, mixed> $shop
     */
    public static function isTestEnvironment(array $shop): bool
    {
        return ((int) ($shop['uni_env'] ?? 1)) === 0;
    }

    /**
     * Whether shop uses Process 2 (email + CP only, no SmartUCF).
     *
     * @param array<string, mixed> $shop
     */
    public static function isProcess2(array $shop): bool
    {
        return ((int) ($shop['uni_proces'] ?? 0)) === 1;
    }

    /**
     * CP/legacy yes flag — accepts 1, "Yes", "true", etc. (Woo mtuc_is_yes_flag()).
     *
     * @param mixed $value
     */
    public static function isYesFlag($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((int) $value) === 1;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'yes', 'on', 'true'], true);
    }

    /**
     * @param array<string, mixed> $shop
     */
    public static function usesSmartUcfCertificate(array $shop): bool
    {
        return self::isYesFlag($shop['uni_sertificat'] ?? 0);
    }
}
