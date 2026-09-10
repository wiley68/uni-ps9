<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Api;

/**
 * Canonical CP→module operation names (hyphenated wire values).
 */
final class ModuleApiOperation
{
    public const SHOP_CACHE = 'shop-cache';

    public const ORDER_BANK_STATUS = 'order-bank-status';

    public const SMARTUCF_DEBUG_LOG = 'smartucf-debug-log';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::SHOP_CACHE,
            self::ORDER_BANK_STATUS,
            self::SMARTUCF_DEBUG_LOG,
        ];
    }

    public static function isCanonical(string $operation): bool
    {
        return in_array($operation, self::all(), true);
    }
}
