<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Calculator;

final class FirstInstallmentResolver
{
    private $months;

    public function __construct(MonthResolver $months)
    {
        $this->months = $months;
    }

    /** @param array<string, mixed> $shop @param array<string, mixed>|null $filter */
    public function resolve(array $shop, float $price, int $months, float $requested, ?array $filter): FirstInstallmentState
    {
        $visible = $this->months->isEnabledFlag($shop['uni_first_vnoska'] ?? 0);
        if ($filter !== null && (int) ($filter['uni_parva'] ?? 0) === 1 && $months > 0) {
            return new FirstInstallmentState(round($price / $months, 2), true, true);
        }
        if ($visible) {
            return new FirstInstallmentState(max(0.0, min(round($requested, 2), $price)), false, true);
        }

        return new FirstInstallmentState(0.0, false, false);
    }
}
