<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/Calculator/fixtures.php';

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\ProductContext;
use PrestaShop\Module\Unipayment\Cart\CartContext;
use PrestaShop\Module\Unipayment\Cart\CartLine;
use PrestaShop\Module\Unipayment\Cart\CartSchemeResolver;
use PrestaShop\Module\Unipayment\Checkout\CheckoutPaymentCalculator;
use PrestaShop\Module\Unipayment\Calculator\UnavailableSchemeException;

function assertCheckoutCalc(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$calculator = new Calculator('2026-08-17');
$calc = new CheckoutPaymentCalculator($calculator, new CartSchemeResolver($calculator));
$shop = calculatorFixture(['uni_eur' => 0]);
$cart = new CartContext([new CartLine(new ProductContext(42, [7], 1000.0), 0, 1, 1000.0)], 1000.0);

$result = $calc->calculate($shop, $cart, 'BGN', [
    'scheme_key' => '12:0',
    'kop_code' => 'STD',
    'first_installment' => 0,
]);
assertCheckoutCalc(($result['price'] ?? null) === 1000.0, 'eligible cart calculation uses cart total');
assertCheckoutCalc(isset($result['monthly_installment'], $result['price_display']['primary']), 'display fields present');

$promo = $calc->calculate($shop, $cart, 'BGN', [
    'scheme_key' => 'p:12:0',
    'kop_code' => 'PROMO',
    'first_installment' => 0,
]);
assertCheckoutCalc(($promo['scheme_type'] ?? '') === 'promo', 'promo scheme selection');

$shipping = new CartContext(
    [new CartLine(new ProductContext(42, [7], 1050.0), 0, 1, 1000.0)],
    1050.0,
    ['carrier_id' => 2, 'shipping_total' => '50.00', 'cart_rules' => []]
);
$shipped = $calc->calculate($shop, $shipping, 'BGN', [
    'scheme_key' => '12:0',
    'kop_code' => 'STD',
    'first_installment' => 0,
]);
assertCheckoutCalc(($shipped['price'] ?? null) === 1050.0, 'shipping included in financed amount');

$voucher = new CartContext(
    [new CartLine(new ProductContext(42, [7], 900.0), 0, 1, 1000.0)],
    900.0,
    ['cart_rules' => [['id_cart_rule' => 1, 'value_real' => '100.00', 'free_shipping' => 0]]]
);
$reduced = $calc->calculate($shop, $voucher, 'BGN', [
    'scheme_key' => '12:0',
    'kop_code' => 'STD',
    'first_installment' => 0,
]);
assertCheckoutCalc(($reduced['price'] ?? null) === 900.0, 'voucher reduction reflected');

$thrown = false;
try {
    $calc->calculate($shop, $cart, 'EUR', ['scheme_key' => '12:0', 'kop_code' => 'STD', 'first_installment' => 0]);
} catch (UnavailableSchemeException $e) {
    $thrown = true;
}
assertCheckoutCalc($thrown, 'unsupported currency rejected');

$thrown = false;
try {
    $calc->calculate($shop, $cart, 'BGN', ['scheme_key' => '99:0', 'kop_code' => 'STD', 'first_installment' => 0]);
} catch (UnavailableSchemeException $e) {
    $thrown = true;
}
assertCheckoutCalc($thrown, 'invalid months rejected');

$thrown = false;
try {
    $calc->calculate($shop, $cart, 'BGN', ['scheme_key' => '12:0', 'kop_code' => 'BAD', 'first_installment' => 0]);
} catch (UnavailableSchemeException $e) {
    $thrown = true;
}
assertCheckoutCalc($thrown, 'invalid KOP rejected');

$thrown = false;
try {
    $calc->calculate($shop, $cart, 'BGN', ['scheme_key' => '12:0', 'kop_code' => 'STD', 'first_installment' => 1000]);
} catch (UnavailableSchemeException $e) {
    $thrown = true;
}
assertCheckoutCalc($thrown, 'invalid first installment rejected');

$empty = new CartContext([], 0);
$thrown = false;
try {
    $calc->calculate($shop, $empty, 'BGN', ['scheme_key' => '12:0', 'kop_code' => 'STD', 'first_installment' => 0]);
} catch (UnavailableSchemeException $e) {
    $thrown = true;
}
assertCheckoutCalc($thrown, 'no-offer cart rejected');

fwrite(STDOUT, "OK (Phase 9 checkout payment calculator)\n");
