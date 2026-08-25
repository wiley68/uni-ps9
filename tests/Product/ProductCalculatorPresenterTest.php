<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/Calculator/fixtures.php';

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\ProductContext;
use PrestaShop\Module\Unipayment\Product\ProductCalculatorPresenter;

function assertProductPresenter(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$presenter = new ProductCalculatorPresenter(new Calculator('2026-08-17'));
$product = new ProductContext(42, [7, 9], 1000.0);
$view = $presenter->present(calculatorFixture(['uni_eur' => 0]), $product, 'BGN');

assertProductPresenter(is_array($view), 'BGN product calculator must be available');
assertProductPresenter(isset($view['offers']['standard'], $view['offers']['promo']), 'standard and promo buttons must be present');
assertProductPresenter($view['design'] === 'standard' && $view['dark_button'] === false, 'standard design must be the safe default');
assertProductPresenter($view['button_type'] === 'image' && $view['show_installment'] === false, 'image-oriented button must hide installment information');
assertProductPresenter($view['buttons_in_row'] === true, 'one-row layout must be the safe default');
assertProductPresenter($view['button_width'] === 290 && $view['button_height'] === 56, 'Woo dimensions must be the safe defaults');
assertProductPresenter($view['offers']['standard']['months'] === 12, 'preferred standard month must come from the domain');
assertProductPresenter(count($view['offers']['standard']['schemes']) === 5, 'Standard popup must expose Standard plus eligible Promo schemes');
assertProductPresenter(count($view['offers']['promo']['schemes']) === 2, 'Promo popup must remain limited to Promo schemes');
assertProductPresenter($view['offers']['standard']['preferred_scheme_key'] === 'standard|STD|12|0', 'initial Standard selection must remain the preferred Standard button offer');
assertProductPresenter($view['offers']['standard']['schemes'][1]['key'] === 'standard|STD|12|0', 'Standard identity must include type, KOP, months and filter metadata');
assertProductPresenter($view['offers']['standard']['schemes'][2]['key'] === 'promo|PROMO|12|0', 'same-month Promo identity must remain distinct from Standard');
assertProductPresenter($view['offers']['standard']['schemes'][2]['description'] === '0% лихва за компютри', 'default Promo label must come from CP uni_kop_promo_desc');
assertProductPresenter($view['offers']['promo']['schemes'][0]['glp'] === 0.0, 'promo scheme must remain zero-interest');

$sameMonthShop = calculatorFixture();
$sameMonthShop['uni_shema_current'] = 6;
$sameMonthShop['kop']['by_default'] = [
    'uni_kop_default' => 'POS COM 50',
    'uni_kop_default_desc' => '',
    'uni_kop_promo' => 'POS COM 0%V1',
    'uni_kop_promo_desc' => '0% лихва за компютри',
    'uni_promo_price' => 0,
    'uni_promo_meseci_znak' => 'eq',
    'uni_promo_meseci' => '6',
];
$sameMonthShop['coeff_list'] = [
    ['onlineProductCode' => 'POS COM 50', 'installmentCount' => 6, 'coeff' => 0.18, 'interestPercent' => 20],
    ['onlineProductCode' => 'POS COM 0%V1', 'installmentCount' => 6, 'coeff' => 0.166667, 'interestPercent' => 0],
];
$sameMonthView = $presenter->present($sameMonthShop, $product, 'BGN');
assertProductPresenter(is_array($sameMonthView) && count($sameMonthView['offers']['standard']['schemes']) === 2, 'same-month Standard and Promo schemes must both remain in the Standard popup');
assertProductPresenter($sameMonthView['offers']['standard']['schemes'][0]['key'] === 'standard|POS%20COM%2050|6|0', 'same-month Standard identity must include its KOP');
assertProductPresenter($sameMonthView['offers']['standard']['schemes'][1]['key'] === 'promo|POS%20COM%200%25V1|6|0', 'same-month Promo identity must include its distinct KOP');
assertProductPresenter($sameMonthView['offers']['standard']['preferred_scheme_key'] === 'standard|POS%20COM%2050|6|0', 'same-month Promo option must not replace the preferred Standard selection');
assertProductPresenter($sameMonthView['offers']['standard']['schemes'][1]['description'] === $sameMonthShop['kop']['by_default']['uni_kop_promo_desc'], 'same-month Promo option label must use the CP description');
assertProductPresenter($presenter->present(calculatorFixture(['uni_eur' => 0]), $product, 'EUR') === null, 'mismatched currency must hide calculator');
assertProductPresenter($presenter->present(calculatorFixture(['uni_eur' => 3]), $product, 'EUR') !== null, 'EUR-only configuration must support EUR');
assertProductPresenter($presenter->present(calculatorFixture(['uni_status' => 0]), $product, 'BGN') === null, 'inactive shop must hide calculator');

$eurLabelView = $presenter->present(
    calculatorFixture(['uni_eur' => 3]),
    new ProductContext(42, [7, 9], 1026.21),
    'EUR'
);
assertProductPresenter(is_array($eurLabelView), 'EUR label fixture must be available');
assertProductPresenter($eurLabelView['offers']['standard']['installment_label'] === '12 x 97.49 евро', 'EUR button label must use Woo евро suffix');
assertProductPresenter(strpos($eurLabelView['offers']['standard']['installment_label'], '€') === false, 'EUR button label must not contain a currency symbol');
assertProductPresenter(strpos($eurLabelView['offers']['standard']['installment_label'], 'евро') !== false, 'EUR button label must contain the EUR text suffix');
assertProductPresenter((bool) preg_match('/^12 x \d+\.\d{2} евро$/', $eurLabelView['offers']['standard']['installment_label']), 'button label must contain months, dot separator and exactly two decimals');
assertProductPresenter((bool) preg_match('/^12 x \d+\.\d{2} евро$/', $eurLabelView['offers']['promo']['installment_label']), 'promo label must use the same English EUR contract');

$bgnLabelView = $presenter->present(calculatorFixture(['uni_eur' => 0]), $product, 'BGN');
assertProductPresenter(is_array($bgnLabelView) && (bool) preg_match('/ лв\.$/u', $bgnLabelView['offers']['standard']['installment_label']), 'BGN-only button label must use the Woo лв. suffix');

$visualView = $presenter->present(calculatorFixture([
    'uni_vnoska' => 1,
    'uni_type_button' => 1,
    'uni_button_row' => 0,
    'uni_button_width' => 420,
    'uni_button_height' => 72,
    'uni_zaglavie' => 'Финансиране от УниКредит',
]), $product, 'BGN');
assertProductPresenter(is_array($visualView), 'visual configuration must preserve an available calculator');
assertProductPresenter($visualView['design'] === 'alternative' && $visualView['dark_button'] === true, 'alternative design must use the red Woo variant');
assertProductPresenter($visualView['button_type'] === 'standard' && $visualView['show_installment'] === true, 'standard button type must expose installment information');
assertProductPresenter($visualView['buttons_in_row'] === false, 'two-row setting must produce the stacked layout');
assertProductPresenter($visualView['button_width'] === 420 && $visualView['button_height'] === 72, 'valid CP dimensions must reach the AJAX presenter result');
assertProductPresenter($visualView['heading'] === 'Финансиране от УниКредит', 'CP heading must reach the presenter result');

$invalidDimensions = $presenter->present(calculatorFixture([
    'uni_button_width' => 99,
    'uni_button_height' => 121,
]), $product, 'BGN');
assertProductPresenter(is_array($invalidDimensions), 'invalid visual configuration must not disable financing');
assertProductPresenter($invalidDimensions['button_width'] === 290 && $invalidDimensions['button_height'] === 56, 'out-of-contract dimensions must use Woo defaults');

$standardOnly = $presenter->present(calculatorFixture([
    'kop' => ['by_default' => ['uni_kop_promo' => '']],
]), $product, 'BGN');
assertProductPresenter(is_array($standardOnly) && isset($standardOnly['offers']['standard']) && !isset($standardOnly['offers']['promo']), 'standard-only product must expose only its available button');
assertProductPresenter(count($standardOnly['offers']['standard']['schemes']) === 3, 'standard-only popup must not add unavailable Promo schemes');

$promoOnly = $presenter->present(calculatorFixture([
    'kop' => ['by_default' => ['uni_kop_default' => '']],
]), $product, 'BGN');
assertProductPresenter(is_array($promoOnly) && isset($promoOnly['offers']['promo']) && !isset($promoOnly['offers']['standard']), 'promo-only product must expose only its available button');
assertProductPresenter(count($promoOnly['offers']['promo']['schemes']) === 2, 'promo-only popup must preserve available Promo schemes');

$schema = calculatorFixture([
    'uni_typekop' => 1,
    'kop' => ['by_schema' => ['filters' => schemaFiltersFixture()]],
]);
$schemaView = $presenter->present($schema, $product, 'BGN');
assertProductPresenter(is_array($schemaView), 'matching schema calculator must be available');
assertProductPresenter(count($schemaView['offers']['standard']['schemes']) === 4, 'schema Standard popup must include Standard and Promo schemes');
assertProductPresenter($schemaView['offers']['standard']['schemes'][2]['description'] === '0% schema promotion', 'schema Promo label must come from filter uni_kop_desc');

fwrite(STDOUT, "OK (Phase 6 product calculator presenter)\n");
