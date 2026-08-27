<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Calculator;

/**
 * Presentation-only scheme category. Does not change AvailableScheme::type or identity.
 *
 * Ordering within the same month: standard → non-zero promotional → zero-interest promotional.
 */
final class SchemePresentationCategory
{
    public const STANDARD = 'standard';

    public const NONZERO_PROMO = 'nonzero_promo';

    public const ZERO_PROMO = 'zero_promo';

    /** @param array<string, mixed> $shop */
    public static function classify(AvailableScheme $scheme, array $shop): string
    {
        $zeroInterest = self::isZeroInterest($scheme);
        $inPromoFlow = $scheme->type === 'promo'
            || (is_array($scheme->filter) && (int) ($scheme->filter['uni_promo'] ?? 0) === 1);

        if ($inPromoFlow && $zeroInterest) {
            return self::ZERO_PROMO;
        }

        $defaultKop = self::defaultKop($shop);
        if ($defaultKop !== '' && $scheme->kopCode === $defaultKop) {
            return self::STANDARD;
        }

        if ($defaultKop !== '' && $scheme->kopCode !== $defaultKop && !$zeroInterest) {
            return self::NONZERO_PROMO;
        }

        // Conservative fallback when baseline provenance is unclear.
        return self::STANDARD;
    }

    /** Presentation rank used for sorting (lower first). */
    public static function rank(string $category): int
    {
        if ($category === self::STANDARD) {
            return 0;
        }
        if ($category === self::NONZERO_PROMO) {
            return 1;
        }
        if ($category === self::ZERO_PROMO) {
            return 2;
        }

        return 99;
    }

    /** @param array<string, mixed> $shop */
    public static function compare(AvailableScheme $left, AvailableScheme $right, array $shop): int
    {
        if ($left->months !== $right->months) {
            return $left->months <=> $right->months;
        }

        $leftRank = self::rank(self::classify($left, $shop));
        $rightRank = self::rank(self::classify($right, $shop));
        if ($leftRank !== $rightRank) {
            return $leftRank <=> $rightRank;
        }

        if ($left->filterId !== $right->filterId) {
            return $left->filterId <=> $right->filterId;
        }

        $kop = strcmp($left->kopCode, $right->kopCode);
        if ($kop !== 0) {
            return $kop;
        }

        return strcmp($left->type, $right->type);
    }

    /**
     * @param AvailableScheme[] $schemes
     * @param array<string, mixed> $shop
     *
     * @return AvailableScheme[]
     */
    public static function sort(array $schemes, array $shop): array
    {
        $sorted = array_values($schemes);
        usort($sorted, static function (AvailableScheme $left, AvailableScheme $right) use ($shop): int {
            return self::compare($left, $right, $shop);
        });

        return $sorted;
    }

    public static function isZeroInterest(AvailableScheme $scheme): bool
    {
        return array_key_exists('interestPercent', $scheme->coefficient)
            && abs((float) $scheme->coefficient['interestPercent']) <= 0.00001;
    }

    /** @param array<string, mixed> $shop */
    private static function defaultKop(array $shop): string
    {
        $byDefault = is_array($shop['kop']['by_default'] ?? null) ? $shop['kop']['by_default'] : [];

        return trim((string) ($byDefault['uni_kop_default'] ?? ''));
    }
}
