<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Calculator;

final class SchemaFilterMatcher
{
    private $months;
    private $today;

    public function __construct(MonthResolver $months, ?string $today = null)
    {
        $this->months = $months;
        $this->today = $today ?? date('Y-m-d');
    }

    /** @param array<string, mixed> $filter */
    public function matches(array $filter, ProductContext $product): bool
    {
        $hasCategory = $this->months->hasValue($filter['category_id'] ?? null);
        $hasProduct = $this->months->hasValue($filter['product_id'] ?? null);
        if ($hasCategory && $hasProduct) {
            return false;
        }
        if ($hasCategory && !in_array((int) $filter['category_id'], $product->categoryIds, true)) {
            return false;
        }
        if ($hasProduct && (int) $filter['product_id'] !== $product->productId) {
            return false;
        }
        if ($this->months->hasValue($filter['uni_price_from'] ?? null)
            && $product->price < (float) $filter['uni_price_from']) {
            return false;
        }
        if ($this->months->hasValue($filter['uni_price_to'] ?? null)
            && $product->price > (float) $filter['uni_price_to']) {
            return false;
        }

        return $this->matchesDate($filter);
    }

    /** @param array<string, mixed> $filter */
    public function matchesDate(array $filter): bool
    {
        if ($this->months->hasValue($filter['uni_date_from'] ?? null)
            && $this->today < substr(trim((string) $filter['uni_date_from']), 0, 10)) {
            return false;
        }
        if ($this->months->hasValue($filter['uni_date_to'] ?? null)
            && $this->today > substr(trim((string) $filter['uni_date_to']), 0, 10)) {
            return false;
        }

        return true;
    }
}
