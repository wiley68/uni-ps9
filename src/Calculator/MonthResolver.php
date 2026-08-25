<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Calculator;

final class MonthResolver
{
    public const MIN = 3;
    public const MAX = 36;

    public function isValid(int $months): bool
    {
        return $months >= self::MIN && $months <= self::MAX;
    }

    /** @return int[] */
    public function parse(string $raw): array
    {
        $result = [];
        foreach (explode('_', str_replace(',', '_', $raw)) as $part) {
            $months = (int) trim($part);
            if ($this->isValid($months)) {
                $result[] = $months;
            }
        }

        return array_values(array_unique($result));
    }

    /** @param array<string, mixed> $shop @return int[] */
    public function enabledMonths(array $shop): array
    {
        $enabled = [];
        for ($months = self::MIN; $months <= self::MAX; ++$months) {
            if ($this->isEnabledFlag($shop['uni_meseci_' . $months] ?? 0)) {
                $enabled[] = $months;
            }
        }

        return $enabled;
    }

    /** @param array<string, mixed> $filter @param array<string, mixed> $shop @return int[] */
    public function allowedForFilter(array $filter, array $shop): array
    {
        $shopMonths = $this->enabledMonths($shop);
        if (!$this->hasValue($filter['uni_meseci'] ?? null)) {
            return $shopMonths;
        }

        return array_values(array_intersect($shopMonths, $this->parse((string) $filter['uni_meseci'])));
    }

    /** @param array<string, mixed> $byDefault @return int[] */
    public function defaultPromoMonths(array $byDefault, float $price, array $candidateMonths): array
    {
        $minimumPrice = (float) ($byDefault['uni_promo_price'] ?? 0);
        if ($minimumPrice > 0 && $price < $minimumPrice) {
            return [];
        }

        $operator = strtolower(trim((string) ($byDefault['uni_promo_meseci_znak'] ?? '')));
        $raw = trim((string) ($byDefault['uni_promo_meseci'] ?? ''));
        if ($operator === 'eq') {
            return array_values(array_intersect($candidateMonths, $this->parse($raw)));
        }
        if ($operator === 'greateq') {
            $parts = $this->parse($raw);
            $minimum = (int) $raw;
            if (!$this->isValid($minimum)) {
                $minimum = $parts[0] ?? 0;
            }
            if (!$this->isValid($minimum)) {
                return [];
            }

            return array_values(array_filter($candidateMonths, static function (int $months) use ($minimum): bool {
                return $months >= $minimum;
            }));
        }

        return [];
    }

    public function isEnabledFlag($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower(trim((string) $value)), ['yes', 'on', '1', 'true'], true);
    }

    public function hasValue($value): bool
    {
        return $value !== null && trim((string) $value) !== '';
    }
}
