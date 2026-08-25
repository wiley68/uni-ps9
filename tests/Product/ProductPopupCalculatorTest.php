<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/Calculator/fixtures.php';

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\ProductContext;
use PrestaShop\Module\Unipayment\Calculator\UnavailableSchemeException;
use PrestaShop\Module\Unipayment\Product\ProductPopupCalculator;
use PrestaShop\Module\Unipayment\Product\ProductPopupPresenter;

function assertProductPopup(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$popup = new ProductPopupCalculator(new Calculator('2026-08-17'));
$product = new ProductContext(42, [7, 9], 1000.0);
$shop = calculatorFixture(['uni_eur' => 3]);
$standard = $popup->calculate($shop, $product, 'EUR', 'standard', 'standard', 'STD', 12, 0, 'standard|STD|12|0', 100.0);
assertProductPopup($standard['scheme_type'] === 'standard' && $standard['months'] === 12, 'Standard popup context was not preserved');
assertProductPopup($standard['scheme_key'] === 'standard|STD|12|0', 'popup calculation must return the canonical scheme key');
assertProductPopup($standard['kop_code'] === 'STD' && $standard['glp'] === 18.0, 'Standard 12-month selection must use the Standard KOP');
assertProductPopup($standard['first_installment'] === 100.0 && !$standard['first_installment_locked'], 'editable first installment was not calculated server-side');
assertProductPopup($standard['price_display']['primary'] === '1000.00 евро', 'Woo EUR popup formatting changed');
assertProductPopup($standard['monthly_installment_display']['primary'] === '85.50 евро', 'dependent values were not recalculated from financed amount');

$promo = $popup->calculate($shop, $product, 'EUR', 'promo', 'promo', 'PROMO', 12, 0, 'promo|PROMO|12|0', 0.0);
assertProductPopup($promo['scheme_type'] === 'promo' && $promo['glp_display'] === '0.00', 'Promo popup must remain isolated and zero-interest');
assertProductPopup($promo['kop_code'] === 'PROMO', 'Promo 12-month selection must use the Promo KOP');

$promoFromStandard = $popup->calculate($shop, $product, 'EUR', 'standard', 'promo', 'PROMO', 12, 0, 'promo|PROMO|12|0', 0.0);
assertProductPopup($promoFromStandard['kop_code'] === 'PROMO' && $promoFromStandard['glp'] === 0.0, 'Standard popup must recalculate a selected Promo scheme server-side');
assertProductPopup($promoFromStandard['monthly_installment'] !== $standard['monthly_installment'], 'switching Standard to Promo must replace server-side financial results');

try {
    $popup->calculate($shop, $product, 'EUR', 'promo', 'standard', 'STD', 12, 0, 'standard|STD|12|0', 0.0);
    assertProductPopup(false, 'Promo popup accepted a Standard scheme');
} catch (UnavailableSchemeException $exception) {
    assertProductPopup(true, 'Promo popup remained isolated to Promo schemes');
}

try {
    $popup->calculate($shop, $product, 'EUR', 'standard', 'promo', 'STD', 12, 0, 'promo|STD|12|0', 0.0);
    assertProductPopup(false, 'browser KOP identity was trusted');
} catch (UnavailableSchemeException $exception) {
    assertProductPopup(true, 'KOP identity was validated server-side');
}

$schemaShop = calculatorFixture([
    'uni_eur' => 0,
    'uni_typekop' => 1,
    'kop' => ['by_schema' => ['filters' => schemaFiltersFixture()]],
]);
$locked = $popup->calculate($schemaShop, $product, 'BGN', 'standard', 'standard', 'PRODUCT', 24, 11, 'standard|PRODUCT|24|11', 200.0);
assertProductPopup($locked['first_installment_locked'] && $locked['first_installment'] === 41.67, 'scheme-locked first installment must ignore browser input');

try {
    $popup->calculate($shop, $product, 'EUR', 'promo', 'promo', 'PROMO', 6, 0, 'promo|PROMO|6|0', 0.0);
    assertProductPopup(false, 'invalid Promo month was accepted');
} catch (UnavailableSchemeException $exception) {
    assertProductPopup(true, 'invalid selection rejected');
}

$visual = (new ProductPopupPresenter())->present([
    'uni_picture' => 'https://cdn.example.test/desktop.jpg',
    'uni_picturem' => 'https://cdn.example.test/mobile.jpg',
    'reklama_url' => 'https://example.test/info',
], 'buy');
assertProductPopup($visual['banner_url'] === 'https://cdn.example.test/desktop.jpg', 'CP uni_picture banner source missing');
assertProductPopup($visual['banner_url_mobile'] === 'https://cdn.example.test/mobile.jpg', 'CP uni_picturem banner source missing');
assertProductPopup($visual['button_action'] === 'buy' && $visual['secondary_label'] === 'Купи', 'Buy action presentation contract failed');
assertProductPopup((new ProductPopupPresenter())->present([], 'add_to_cart')['secondary_label'] === 'Добави в количката', 'Add-to-cart action label failed');
$customerPresentation = (new ProductPopupPresenter())->present([], 'add_to_cart', ['first_name' => 'Иван', 'email' => 'ivan@example.test', 'is_logged' => true]);
assertProductPopup($customerPresentation['customer']['first_name'] === 'Иван' && $customerPresentation['customer']['email'] === 'ivan@example.test' && $customerPresentation['customer']['is_logged'], 'Product Popup customer prefill model was not exposed');
$fallbackLink = (new ProductPopupPresenter())->present(['reklama_url' => '', 'uni_backurl' => 'https://example.test/fallback'], 'add_to_cart');
assertProductPopup($fallbackLink['banner_link'] === 'https://example.test/fallback', 'Woo uni_backurl fallback semantics failed');

$consentShop = [
    'consents' => [
        ['id' => 2, 'name' => 'Optional info', 'url' => 'https://example.test/info', 'mandatory' => 0],
        ['id' => 1, 'name' => '<b>Terms</b>', 'url' => 'https://example.test/terms', 'mandatory' => 1],
        ['name' => ''],
    ],
];
$consentView = (new ProductPopupPresenter())->present($consentShop, 'add_to_cart');
assertProductPopup(count($consentView['consents']) === 2, 'empty consent names must be skipped');
assertProductPopup($consentView['consents'][0]['id'] === 1 && $consentView['consents'][0]['has_checkbox'] === true, 'mandatory consents must sort first and render as checkboxes');
assertProductPopup($consentView['consents'][0]['name'] === 'Terms' && $consentView['consents'][1]['has_checkbox'] === false, 'optional consents must remain informational text without a checkbox');

fwrite(STDOUT, "OK (Product popup authoritative calculation and presentation)\n");
