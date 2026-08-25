<?php

declare(strict_types=1);

function calculatorFixture(array $overrides = []): array
{
    $shop = [
        'uni_status' => 1,
        'uni_minstojnost' => 100,
        'uni_maxstojnost' => 10000,
        'uni_first_vnoska' => 1,
        'uni_shema_current' => 12,
        'uni_typekop' => 0,
        'kop' => [
            'by_default' => [
                'uni_kop_default' => 'STD',
                'uni_kop_default_desc' => '',
                'uni_kop_promo' => 'PROMO',
                'uni_kop_promo_desc' => '0% лихва за компютри',
                'uni_promo_price' => 500,
                'uni_promo_meseci_znak' => 'eq',
                'uni_promo_meseci' => '12_24',
            ],
            'by_schema' => ['filters' => []],
        ],
        'coeff_list' => [
            ['onlineProductCode' => 'STD', 'installmentCount' => 6, 'coeff' => 0.18, 'interestPercent' => 20],
            ['onlineProductCode' => 'STD', 'installmentCount' => 12, 'coeff' => 0.095, 'interestPercent' => 18],
            ['onlineProductCode' => 'STD', 'installmentCount' => 24, 'coeff' => 0.055, 'interestPercent' => 17],
            ['onlineProductCode' => 'PROMO', 'installmentCount' => 12, 'coeff' => 0.083333, 'interestPercent' => 0],
            ['onlineProductCode' => 'PROMO', 'installmentCount' => 24, 'coeff' => 0.041667, 'interestPercent' => 0],
            ['onlineProductCode' => 'CAT', 'installmentCount' => 6, 'coeff' => 0.17, 'interestPercent' => 12],
            ['onlineProductCode' => 'CAT', 'installmentCount' => 12, 'coeff' => 0.09, 'interestPercent' => 10],
            ['onlineProductCode' => 'PRODUCT', 'installmentCount' => 24, 'coeff' => 0.05, 'interestPercent' => 9],
            ['onlineProductCode' => 'ZERO', 'installmentCount' => 12, 'coeff' => 0.083333, 'interestPercent' => 0],
        ],
    ];
    foreach ([6, 12, 24] as $months) {
        $shop['uni_meseci_' . $months] = 1;
    }

    return array_replace_recursive($shop, $overrides);
}

function schemaFiltersFixture(): array
{
    return [
        [
            'id' => 10, 'category_id' => 7, 'product_id' => null, 'uni_meseci' => '6_12',
            'uni_price_from' => 500, 'uni_price_to' => 1000, 'uni_promo' => 0, 'uni_parva' => 0,
            'uni_date_from' => '2026-08-01', 'uni_date_to' => '2026-08-31', 'uni_kop' => 'CAT',
        ],
        [
            'id' => 11, 'category_id' => null, 'product_id' => 42, 'uni_meseci' => '24',
            'uni_price_from' => null, 'uni_price_to' => null, 'uni_promo' => 0, 'uni_parva' => 1,
            'uni_date_from' => null, 'uni_date_to' => null, 'uni_kop' => 'PRODUCT',
        ],
        [
            'id' => 12, 'category_id' => 7, 'product_id' => null, 'uni_meseci' => '12',
            'uni_price_from' => 500, 'uni_price_to' => 1000, 'uni_promo' => 1, 'uni_parva' => 0,
            'uni_date_from' => '2026-08-17', 'uni_date_to' => '2026-08-17', 'uni_kop' => 'ZERO',
            'uni_kop_desc' => '0% schema promotion',
        ],
    ];
}
