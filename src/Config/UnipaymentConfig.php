<?php

/**
 * Copyright since 2024 Ilko Ivanov
 *
 * Централни константи на модула (един източник на истина).
 *
 * @author    Ilko Ivanov
 * @copyright Ilko Ivanov
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Config;

final class UnipaymentConfig
{
    /** Праг за сходимост при финансови изчисления (напр. IRR/анюитет). */
    public const FINANCIAL_PRECISION = 1.0e-8;

    /** Максимален брой итерации при числени методи. */
    public const FINANCIAL_MAX_ITERATIONS = 128;

    /**
     * Праг в процентни пунктове за показване на ГПР: при |стойност| ≤ това число се показва 0.00.
     */
    public const GPR_NEAR_ZERO_PERCENT = 0.05;

    /** Фиксиран курс EUR/BGN за ориентировъчни суми в двойна валутност. */
    public const EUR_BGN_RATE = 1.95583;

    /** Базов URL на услугата (live). */
    public const LIVE_URL = 'https://unicreditconsumerfinancing.info';

    /**
     * Периоди (брой месеци), за които се вика getCoeff и се пълнят kimb_X / glp_X в kop.json.
     * 15 не се подава от банката — не е в този списък; UI/конфиг могат да го ползват отделно.
     *
     * @var list<int>
     */
    public const KIMB_BANK_INSTALLMENT_COUNTS = [3, 4, 5, 6, 9, 10, 12, 18, 24, 30, 36];

    /**
     * Всички срокове за падащи менюта (продукт / checkout), вкл. 15 — само от конфигурацията на модула.
     *
     * @var list<int>
     */
    public const PRODUCT_INSTALLMENT_MONTHS = [3, 4, 5, 6, 9, 10, 12, 15, 18, 24, 30, 36];

    private function __construct()
    {
    }
}
