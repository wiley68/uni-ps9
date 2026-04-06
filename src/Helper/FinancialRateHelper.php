<?php

/**
 * Copyright since 2024 Ilko Ivanov
 *
 * Пресмятане на периодна лихва за ануитет (аналог на Excel RATE), метод на секущите.
 *
 * @author    Ilko Ivanov
 * @copyright Ilko Ivanov
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Helper;

use PrestaShop\Module\Unipayment\Config\UnipaymentConfig;

final class FinancialRateHelper
{
    private function __construct()
    {
    }

    /**
     * Периодна лихва за даден брой периоди, вноска, настояща стойност (и опционално бъдеща стойност).
     * Параметрите следват конвенцията на Excel RATE / бивш UNI_RATE в модула.
     *
     * @return float периодна лихва (не годишна)
     */
    public static function periodicRate(
        float $nper,
        float $pmt,
        float $pv,
        float $fv = 0.0,
        int $type = 0,
        float $guess = 0.1
    ): float {
        $rate = $guess;
        if (abs($rate) >= UnipaymentConfig::FINANCIAL_PRECISION) {
            $f = exp($nper * log(1 + $rate));
        }
        if (!isset($f)) {
            $f = exp($nper * log(1 + $rate));
        }
        $y0 = $pv + $pmt * $nper + $fv;
        $y1 = $pv * $f + $pmt * (1 / $rate + $type) * ($f - 1) + $fv;
        $i = $x0 = 0.0;
        $x1 = $rate;
        while ((abs($y0 - $y1) > UnipaymentConfig::FINANCIAL_PRECISION) && ($i < UnipaymentConfig::FINANCIAL_MAX_ITERATIONS)) {
            $rate = ($y1 * $x0 - $y0 * $x1) / ($y1 - $y0);
            $x0 = $x1;
            $x1 = $rate;
            if (abs($rate) < UnipaymentConfig::FINANCIAL_PRECISION) {
                $y = $pv * (1 + $nper * $rate) + $pmt * (1 + $rate * $type) * $nper + $fv;
            } else {
                $f = exp($nper * log(1 + $rate));
                $y = $pv * $f + $pmt * (1 / $rate + $type) * ($f - 1) + $fv;
            }
            $y0 = $y1;
            $y1 = $y;
            ++$i;
        }

        return $rate;
    }

    /**
     * Форматира ГПР (в процентни пунктове) за JSON/шаблон: при |стойност| ≤ {@see UnipaymentConfig::GPR_NEAR_ZERO_PERCENT} → "0.00".
     */
    public static function formatGprPercentForDisplay(float $percentPoints): string
    {
        $a = abs($percentPoints);
        if ($a <= UnipaymentConfig::GPR_NEAR_ZERO_PERCENT) {
            return '0.00';
        }

        return number_format($a, 2, '.', '');
    }
}
