<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Calculator;

final class OfferFactory
{
    private $financial;

    public function __construct(FinancialCalculator $financial)
    {
        $this->financial = $financial;
    }

    /** @param array<string, mixed> $coefficient */
    public function create(string $type, string $kopCode, int $months, float $amount, array $coefficient, int $filterId = 0): ?Offer
    {
        $kimb = (float) ($coefficient['coeff'] ?? 0);
        if ($kimb <= 0 || $amount <= 0 || $months <= 0) {
            return null;
        }
        $monthly = round($amount * $kimb, 2);

        return new Offer(
            $type,
            $kopCode,
            $months,
            $monthly,
            round((float) ($coefficient['interestPercent'] ?? 0), 2),
            round($this->financial->calculateGpr($months, $monthly, $amount), 2),
            round($amount, 2),
            $kimb,
            $filterId
        );
    }
}
