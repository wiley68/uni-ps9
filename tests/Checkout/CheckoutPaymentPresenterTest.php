<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/Calculator/fixtures.php';

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\CurrencyGate;
use PrestaShop\Module\Unipayment\Calculator\ProductContext;
use PrestaShop\Module\Unipayment\Cart\CartContext;
use PrestaShop\Module\Unipayment\Cart\CartLine;
use PrestaShop\Module\Unipayment\Cart\CartSchemeResolver;
use PrestaShop\Module\Unipayment\Checkout\CartSnapshot;
use PrestaShop\Module\Unipayment\Checkout\CartSnapshotSigner;
use PrestaShop\Module\Unipayment\Checkout\CheckoutPaymentPresenter;
use PrestaShop\Module\Unipayment\Checkout\ConsentResolver;

function assertCheckoutPresenter(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}
$calculator = new Calculator('2026-08-17');
$presenter = new CheckoutPaymentPresenter($calculator, new CartSchemeResolver($calculator), new CurrencyGate(), new CartSnapshot(), new CartSnapshotSigner('test-key'), new ConsentResolver());
$cart = new CartContext([new CartLine(new ProductContext(42, [7], 1000), 0, 1, 1000)], 1000);
$shop = calculatorFixture(['uni_eur' => 0, 'consents' => [
    ['id' => 2, 'name' => 'Optional information', 'mandatory' => 0],
    ['id' => 1, 'name' => 'Mandatory terms', 'mandatory' => 1],
]]);

assertCheckoutPresenter($presenter->present(false, $shop, $cart, 'BGN') === null, 'disabled module exposed payment option');
assertCheckoutPresenter($presenter->present(true, $shop, $cart, 'EUR') === null, 'unsupported currency exposed payment option');
assertCheckoutPresenter($presenter->present(true, $shop, new CartContext([], 0), 'BGN') === null, 'cart without schemes exposed payment option');
$view = $presenter->present(true, $shop, $cart, 'BGN');
assertCheckoutPresenter(is_array($view) && count($view['schemes']) === 5, 'unified standard/promo schemes missing');
assertCheckoutPresenter(isset($view['schemes'][0]['description'], $view['cart_total_display']['primary']), 'checkout schemes must expose Woo display metadata');
assertCheckoutPresenter($view['consents'][0]['mandatory'] && !$view['consents'][1]['mandatory'], 'mandatory/optional consent distinction failed');
assertCheckoutPresenter(!empty($view['consents'][0]['has_checkbox']) && empty($view['consents'][1]['has_checkbox']), 'consent checkbox parity failed');
assertCheckoutPresenter(strpos($view['cart_snapshot'], '.') !== false, 'signed cart snapshot missing');
assertCheckoutPresenter(array_key_exists('process2', $view), 'process2 flag must be exposed');

$zeroInterestPromos = array_values(array_filter($view['schemes'], static function (array $scheme): bool {
    return !empty($scheme['zero_interest']);
}));
assertCheckoutPresenter($zeroInterestPromos !== [], 'fixture must expose at least one 0% promo scheme');
$maxZeroMonths = max(array_map(static function (array $scheme): int {
    return (int) $scheme['months'];
}, $zeroInterestPromos));
assertCheckoutPresenter(
    $view['default_scheme_key'] === 'p:' . $maxZeroMonths . ':0',
    'checkout default must prefer the longest available 0% promo scheme'
);

$preferredScheme = $view['schemes'][1];
$preferredView = $presenter->present(true, $shop, $cart, 'BGN', [
    'scheme_type' => $preferredScheme['scheme_type'],
    'kop_code' => $preferredScheme['kop_code'],
    'months' => $preferredScheme['months'],
    'filter_id' => $preferredScheme['filter_id'],
    'first_installment' => 100.0,
]);
assertCheckoutPresenter(is_array($preferredView) && $preferredView['preselect_payment'] === true, 'valid Product preference must enable Checkout UX preselection');
assertCheckoutPresenter($preferredView['default_scheme_key'] === $preferredScheme['key'], 'matching cart-wide scheme must be preselected');
assertCheckoutPresenter($preferredView['default_first_installment'] === 100.0, 'valid preferred first installment must reach Checkout UI');

$invalidPreferredView = $presenter->present(true, $shop, $cart, 'BGN', [
    'scheme_type' => 'standard',
    'kop_code' => 'STALE',
    'months' => 99,
    'filter_id' => 0,
    'first_installment' => 100.0,
]);
assertCheckoutPresenter(is_array($invalidPreferredView) && $invalidPreferredView['preselect_payment'] === false, 'invalid Product preference must not override cart-wide Checkout schemes');

fwrite(STDOUT, "OK (Phase 8 checkout payment presenter)\n");
