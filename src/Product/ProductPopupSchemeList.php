<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Product;

use PrestaShop\Module\Unipayment\Calculator\AvailableScheme;
use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\ProductContext;

final class ProductPopupSchemeList
{
    /** @var Calculator */
    private $calculator;

    public function __construct(Calculator $calculator)
    {
        $this->calculator = $calculator;
    }

    /** @param array<string, mixed> $shop @return AvailableScheme[] */
    public function schemes(array $shop, ProductContext $product, string $popupType): array
    {
        if ($popupType === 'promo') {
            return $this->sorted($this->calculator->availableSchemes($shop, $product, 'promo'));
        }
        if ($popupType !== 'standard') {
            return [];
        }

        return $this->sorted(array_merge(
            $this->calculator->availableSchemes($shop, $product, 'standard'),
            $this->calculator->availableSchemes($shop, $product, 'promo')
        ));
    }

    public static function key(AvailableScheme $scheme): string
    {
        return self::keyFromParts($scheme->type, $scheme->kopCode, $scheme->months, $scheme->filterId);
    }

    public static function keyFromParts(string $type, string $kopCode, int $months, int $filterId): string
    {
        return implode('|', [
            $type,
            rawurlencode($kopCode),
            (string) $months,
            (string) $filterId,
        ]);
    }

    /** @param array<string, mixed> $shop */
    public static function description(array $shop, AvailableScheme $scheme): string
    {
        if (is_array($scheme->filter)) {
            return trim((string) ($scheme->filter['uni_kop_desc'] ?? ''));
        }
        $settings = is_array($shop['kop']['by_default'] ?? null) ? $shop['kop']['by_default'] : [];

        return trim((string) ($settings[$scheme->type === 'promo' ? 'uni_kop_promo_desc' : 'uni_kop_default_desc'] ?? ''));
    }

    /** @param AvailableScheme[] $schemes @return AvailableScheme[] */
    private function sorted(array $schemes): array
    {
        usort($schemes, static function (AvailableScheme $left, AvailableScheme $right): int {
            if ($left->months !== $right->months) {
                return $left->months <=> $right->months;
            }
            if ($left->type !== $right->type) {
                return $left->type === 'standard' ? -1 : 1;
            }
            if ($left->filterId !== $right->filterId) {
                return $left->filterId <=> $right->filterId;
            }

            return strcmp($left->kopCode, $right->kopCode);
        });

        return $schemes;
    }
}
