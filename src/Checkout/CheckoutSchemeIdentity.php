<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Checkout;

/**
 * Canonical Product ↔ Checkout financing identity.
 *
 * CartSchemeResolver treats filterId as metadata; business identity is
 * scheme_type + kop_code + months. Prefer exact filter_id when several
 * checkout candidates share that identity.
 */
final class CheckoutSchemeIdentity
{
    /**
     * @param array<string, mixed> $scheme Checkout scheme row
     * @param array<string, mixed> $preference Cookie-safe Product handoff
     */
    public static function matchesBusinessIdentity(array $scheme, array $preference): bool
    {
        return (string) ($scheme['scheme_type'] ?? '') === (string) ($preference['scheme_type'] ?? '')
            && (string) ($scheme['kop_code'] ?? '') === (string) ($preference['kop_code'] ?? '')
            && (int) ($scheme['months'] ?? 0) === (int) ($preference['months'] ?? 0);
    }

    public static function filterMatches(array $scheme, array $preference): bool
    {
        return (int) ($scheme['filter_id'] ?? -1) === (int) ($preference['filter_id'] ?? -1);
    }

    /**
     * @param list<array<string, mixed>> $schemes
     * @param array<string, mixed> $preference
     * @return array<string, mixed>|null
     */
    public static function resolve(array $schemes, array $preference): ?array
    {
        $candidates = [];
        foreach ($schemes as $scheme) {
            if (!self::matchesBusinessIdentity($scheme, $preference)) {
                continue;
            }
            $candidates[] = $scheme;
        }
        if ($candidates === []) {
            return null;
        }
        foreach ($candidates as $scheme) {
            if (self::filterMatches($scheme, $preference)) {
                return $scheme;
            }
        }

        return $candidates[0];
    }
}
