<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Calculator;

final class CalculationResult
{
    public $scheme;
    public $price;
    public $firstInstallment;
    public $financedAmount;
    public $monthlyInstallment;
    public $totalPayable;
    public $glp;
    public $gpr;

    public function __construct(AvailableScheme $scheme, float $price, FirstInstallmentState $firstInstallment, float $financedAmount, float $monthlyInstallment, float $totalPayable, float $glp, float $gpr)
    {
        $this->scheme = $scheme;
        $this->price = $price;
        $this->firstInstallment = $firstInstallment;
        $this->financedAmount = $financedAmount;
        $this->monthlyInstallment = $monthlyInstallment;
        $this->totalPayable = $totalPayable;
        $this->glp = $glp;
        $this->gpr = $gpr;
    }
}
