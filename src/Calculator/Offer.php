<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Calculator;

final class Offer
{
    public $type;
    public $kopCode;
    public $months;
    public $monthlyInstallment;
    public $glp;
    public $gpr;
    public $financedAmount;
    public $coefficient;
    public $filterId;

    public function __construct(string $type, string $kopCode, int $months, float $monthlyInstallment, float $glp, float $gpr, float $financedAmount, float $coefficient, int $filterId = 0)
    {
        $this->type = $type;
        $this->kopCode = $kopCode;
        $this->months = $months;
        $this->monthlyInstallment = $monthlyInstallment;
        $this->glp = $glp;
        $this->gpr = $gpr;
        $this->financedAmount = $financedAmount;
        $this->coefficient = $coefficient;
        $this->filterId = $filterId;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'visible' => true,
            'kop_code' => $this->kopCode,
            'installment_count' => $this->months,
            'monthly_installment' => $this->monthlyInstallment,
            'glp' => $this->glp,
            'gpr' => $this->gpr,
            'total_amount' => $this->financedAmount,
            'kimb' => $this->coefficient,
            'filter_id' => $this->filterId,
        ];
    }
}
