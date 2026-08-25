<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Calculator;

final class FinancialCalculator
{
    public function calculateGpr(int $months, float $monthlyInstallment, float $price): float
    {
        if ($months <= 0 || $price <= 0 || $monthlyInstallment <= 0) {
            return 0.0;
        }
        $periodRate = $this->financialRate($months, -$monthlyInstallment, $price);
        $annualRate = ($periodRate * $months) / ($months / 12);

        return abs((pow(1 + $annualRate / 12, 12) - 1) * 100);
    }

    public function financialRate(float $periods, float $payment, float $presentValue): float
    {
        $rate = 0.1;
        $y = $this->rateValue($periods, $payment, $presentValue, $rate);
        $y0 = $presentValue + $payment * $periods;
        $y1 = $y;
        $x0 = 0.0;
        $x1 = $rate;
        $iterations = 0;
        while (abs($y0 - $y1) > 1.0e-8 && $iterations < 128) {
            $difference = $y1 - $y0;
            if (abs($difference) < 1.0e-12) {
                break;
            }
            $rate = ($y1 * $x0 - $y0 * $x1) / $difference;
            $x0 = $x1;
            $x1 = $rate;
            $y = $this->rateValue($periods, $payment, $presentValue, $rate);
            $y0 = $y1;
            $y1 = $y;
            ++$iterations;
        }

        return $rate;
    }

    private function rateValue(float $periods, float $payment, float $presentValue, float $rate): float
    {
        if (abs($rate) < 1.0e-8) {
            return $presentValue * (1 + $periods * $rate) + $payment * $periods;
        }
        $factor = exp($periods * log(1 + $rate));

        return $presentValue * $factor + $payment * (1 / $rate) * ($factor - 1);
    }
}
