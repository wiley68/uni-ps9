<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/fixtures.php';

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\ProductContext;
use PrestaShop\Module\Unipayment\Calculator\UnavailableSchemeException;

function assertCalculator(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$calculator = new Calculator('2026-08-17');
$product = new ProductContext(42, [7, 9], 1000.0);

$default = calculatorFixture();
assertCalculator(!$calculator->isAvailableForAmount(calculatorFixture(['uni_status' => 0]), 1000), 'inactive shop was accepted');
assertCalculator($calculator->isAvailableForAmount($default, 100.0), 'minimum amount boundary was excluded');
assertCalculator($calculator->isAvailableForAmount($default, 10000.0), 'maximum amount boundary was excluded');
assertCalculator(!$calculator->isAvailableForAmount($default, 10000.01), 'amount above maximum was accepted');
$offers = $calculator->resolvePreferredOffers($default, $product);
assertCalculator($offers['standard'] !== null && $offers['standard']->kopCode === 'STD', 'default standard KOP failed');
assertCalculator($offers['standard']->months === 12, 'uni_shema_current was not preferred');
assertCalculator($offers['promo'] !== null && $offers['promo']->kopCode === 'PROMO', 'default promo KOP failed');
assertCalculator($offers['promo']->months === 12, 'default promo preferred month failed');

$belowPromo = new ProductContext(42, [7], 499.99);
assertCalculator($calculator->resolvePreferredOffers($default, $belowPromo)['promo'] === null, 'promo price boundary failed');
$atPromo = new ProductContext(42, [7], 500.0);
assertCalculator($calculator->resolvePreferredOffers($default, $atPromo)['promo'] !== null, 'inclusive promo price boundary failed');

$schema = calculatorFixture([
    'uni_typekop' => 1,
    'kop' => ['by_schema' => ['filters' => schemaFiltersFixture()]],
]);
$schemes = $calculator->availableSchemes($schema, $product, 'standard');
assertCalculator(count($schemes) === 3, 'schema product/category matching or months intersection failed');
assertCalculator($schemes[0]->filterId === 10 && $schemes[2]->filterId === 11, 'schema filter identity failed');
$promoSchemes = $calculator->availableSchemes($schema, $product, 'promo');
assertCalculator(count($promoSchemes) === 1 && $promoSchemes[0]->filterId === 12, 'schema zero-interest promo failed');

$outsideDateCalculator = new Calculator('2026-09-01');
assertCalculator($outsideDateCalculator->availableSchemes($schema, new ProductContext(50, [7], 1000), 'standard') === [], 'expired date filter was accepted');
$invalidTarget = $schema;
$invalidTarget['kop']['by_schema']['filters'] = [[
    'id' => 20, 'category_id' => 7, 'product_id' => 42, 'uni_meseci' => '12',
    'uni_promo' => 0, 'uni_parva' => 0, 'uni_kop' => 'CAT',
]];
assertCalculator($calculator->availableSchemes($invalidTarget, $product, 'standard') === [], 'filter with product and category was accepted');

$outsideCategory = new ProductContext(50, [8], 1000.0);
assertCalculator($calculator->availableSchemes($schema, $outsideCategory, 'standard') === [], 'category/product mismatch was accepted');
$belowBoundary = new ProductContext(50, [7], 499.99);
assertCalculator($calculator->availableSchemes($schema, $belowBoundary, 'standard') === [], 'price-from boundary failed');
$boundary = new ProductContext(50, [7], 500.0);
assertCalculator(count($calculator->availableSchemes($schema, $boundary, 'standard')) === 2, 'inclusive price-from/date boundary failed');

$locked = $calculator->calculate($schema, $product, 24, 'standard', 0, 11);
assertCalculator($locked->firstInstallment->locked && $locked->firstInstallment->amount === 41.67, 'filter first installment failed');
assertCalculator($locked->financedAmount === 958.33, 'locked financed amount failed');
$userFirst = $calculator->calculate($default, $product, 12, 'standard', 200);
assertCalculator(!$userFirst->firstInstallment->locked && $userFirst->firstInstallment->amount === 200.0, 'user first installment failed');

$disabledMonth = calculatorFixture(['uni_meseci_12' => 0]);
try {
    $calculator->calculate($disabledMonth, $product, 12, 'standard');
    assertCalculator(false, 'disabled month was accepted');
} catch (UnavailableSchemeException $exception) {
    assertCalculator(true, 'disabled month rejected');
}

$invalidCoeff = calculatorFixture(['coeff_list' => [['onlineProductCode' => 'STD', 'installmentCount' => 12, 'coeff' => 0]]]);
assertCalculator($calculator->resolvePreferredOffers($invalidCoeff, $product)['standard'] === null, 'invalid coefficient produced an offer');

$nonZeroPromo = calculatorFixture(['coeff_list' => [
    ['onlineProductCode' => 'STD', 'installmentCount' => 12, 'coeff' => 0.095, 'interestPercent' => 18],
    ['onlineProductCode' => 'PROMO', 'installmentCount' => 12, 'coeff' => 0.09, 'interestPercent' => 1],
]]);
assertCalculator($calculator->resolvePreferredOffers($nonZeroPromo, $product)['promo'] === null, 'non-zero-interest promo was accepted');

$greaterOrEqualPromo = calculatorFixture(['kop' => ['by_default' => ['uni_promo_meseci_znak' => 'greateq', 'uni_promo_meseci' => '12']]]);
$greaterOrEqualMonths = array_map(static function ($scheme): int { return $scheme->months; }, $calculator->availableSchemes($greaterOrEqualPromo, $product, 'promo'));
assertCalculator($greaterOrEqualMonths === [12, 24], 'greateq promo month rule failed');

$fallback = calculatorFixture(['uni_shema_current' => 18]);
assertCalculator($calculator->resolvePreferredOffers($fallback, $product)['promo']->months === 24, 'promo fallback did not select highest month');

$invalidPreferredPromo = calculatorFixture([
    'coeff_list' => [
        ['onlineProductCode' => 'STD', 'installmentCount' => 12, 'coeff' => 0.095, 'interestPercent' => 18],
        ['onlineProductCode' => 'PROMO', 'installmentCount' => 12, 'coeff' => 0, 'interestPercent' => 0],
        ['onlineProductCode' => 'PROMO', 'installmentCount' => 24, 'coeff' => 0.041667, 'interestPercent' => 0],
    ],
]);
assertCalculator($calculator->resolvePreferredOffers($invalidPreferredPromo, $product)['promo'] === null, 'invalid preferred promo coeff incorrectly fell back');

fwrite(STDOUT, "OK (Phase 5 calculator domain critical scenarios)\n");
