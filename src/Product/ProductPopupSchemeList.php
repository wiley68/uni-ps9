<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Product;

use PrestaShop\Module\Unipayment\Calculator\AvailableScheme;
use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\ProductContext;
use PrestaShop\Module\Unipayment\Calculator\SchemePresentationCategory;

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
            return SchemePresentationCategory::sort(
                $this->calculator->availableSchemes($shop, $product, 'promo'),
                $shop
            );
        }
        if ($popupType !== 'standard') {
            return [];
        }

        return SchemePresentationCategory::sort(array_merge(
            $this->calculator->availableSchemes($shop, $product, 'standard'),
            $this->calculator->availableSchemes($shop, $product, 'promo')
        ), $shop);
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
}
