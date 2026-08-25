<?php

declare(strict_types=1);

/**
 * Cart amount / qty / voucher / tax semantics contract (PS8 oracle).
 *
 * Authoritative amount: Cart::getOrderTotal(true, Cart::BOTH)
 * = tax-inclusive products + shipping − cart rules/vouchers (payable total).
 * Line total_wt is stored but financing uses cart total for every line's ProductContext price.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/Calculator/fixtures.php';

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\ProductContext;
use PrestaShop\Module\Unipayment\Cart\CartContext;
use PrestaShop\Module\Unipayment\Cart\CartLine;
use PrestaShop\Module\Unipayment\Cart\CartPopupCalculator;
use PrestaShop\Module\Unipayment\Cart\CartSchemeResolver;
use PrestaShop\Module\Unipayment\Product\ProductPopupSchemeList;

function assertCartAmount(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$factorySource = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Cart/CartContextFactory.php');
assertCartAmount(strpos($factorySource, 'getOrderTotal(true, \\Cart::BOTH)') !== false, 'factory must use tax-inclusive BOTH payable total');
assertCartAmount(strpos($factorySource, 'total_wt') !== false, 'factory must capture line total_wt for diagnostics');
assertCartAmount(
    (bool) preg_match('/new ProductContext\(\$productId,.*\$total\)/s', $factorySource),
    'each line ProductContext price must be cart payable total, not line total'
);

$calculator = new Calculator('2026-08-17');
$resolver = new CartSchemeResolver($calculator);
$popup = new CartPopupCalculator($calculator, $resolver);
$shop = calculatorFixture(['uni_eur' => 0]);

// Vector: unit 100 × qty 1 → cart total 100 (single-line cart, no shipping in fixture)
$cart100 = new CartContext([new CartLine(new ProductContext(1, [7], 100.0), 0, 1, 100.0)], 100.0);
$res100 = $resolver->resolve($shop, $cart100);
assertCartAmount($res100->standardOffer !== null, 'qty1 cart total 100 must remain eligible');
$scheme = $res100->standardSchemes[0];
$key = ProductPopupSchemeList::key($scheme);
$calc100 = $popup->calculate($shop, $cart100, 'BGN', 'standard', $scheme->type, $scheme->kopCode, $scheme->months, $scheme->filterId, $key, 0.0);
assertCartAmount(($calc100['price'] ?? null) === 100.0, 'qty1 financing price must be cart total 100');

// Vector: unit 100 × qty 3 → cart total 300 (not unit 100)
$cart300 = new CartContext([new CartLine(new ProductContext(1, [7], 300.0), 0, 3, 300.0)], 300.0);
$res300 = $resolver->resolve($shop, $cart300);
assertCartAmount($res300->standardOffer !== null, 'qty3 cart total 300 must remain eligible');
$scheme300 = $res300->standardSchemes[0];
$key300 = ProductPopupSchemeList::key($scheme300);
$calc300 = $popup->calculate($shop, $cart300, 'BGN', 'standard', $scheme300->type, $scheme300->kopCode, $scheme300->months, $scheme300->filterId, $key300, 0.0);
assertCartAmount(($calc300['price'] ?? null) === 300.0, 'qty3 financing price must be cart total 300, not unit 100');
assertCartAmount(($calc300['monthly_installment'] ?? 0) > ($calc100['monthly_installment'] ?? 0), 'qty3 installment must exceed qty1');

// Line total must NOT drive scheme price filters when cart total differs
$misaligned = new CartContext([new CartLine(new ProductContext(1, [7], 1000.0), 0, 2, 400.0)], 1000.0);
$misalignedResult = $resolver->resolve($shop, $misaligned);
assertCartAmount(count($misalignedResult->standardSchemes) === 3, 'scheme evaluation must use cart total 1000, not line 400');

// Filtered scheme intersection (filter_id metadata only)
$filterShop = calculatorFixture(['uni_typekop' => 1, 'kop' => ['by_schema' => ['filters' => [
    ['id' => 31, 'product_id' => 1, 'category_id' => null, 'uni_meseci' => '12', 'uni_promo' => 0, 'uni_parva' => 0, 'uni_kop' => 'CAT'],
    ['id' => 32, 'product_id' => 2, 'category_id' => null, 'uni_meseci' => '12', 'uni_promo' => 0, 'uni_parva' => 0, 'uni_kop' => 'CAT'],
]]]]);
$filterCart = new CartContext([
    new CartLine(new ProductContext(1, [], 1000.0), 0, 1, 400.0),
    new CartLine(new ProductContext(2, [], 1000.0), 5, 1, 600.0),
], 1000.0);
$filterResult = $resolver->resolve($filterShop, $filterCart);
assertCartAmount(count($filterResult->standardSchemes) === 1 && $filterResult->standardSchemes[0]->filterId === 31, 'filter metadata intersection parity');

// Multiple combinations of same product: cart-wide total still applies
$combos = new CartContext([
    new CartLine(new ProductContext(9, [7], 500.0), 1, 1, 200.0),
    new CartLine(new ProductContext(9, [7], 500.0), 2, 1, 300.0),
], 500.0);
assertCartAmount($resolver->resolve($shop, $combos)->standardOffer !== null, 'same product different combinations remain cart-wide');

fwrite(STDOUT, "OK (Phase 8 cart amount semantics + Woo/PS8 vectors)\n");
