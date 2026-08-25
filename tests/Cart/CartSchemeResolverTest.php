<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/Calculator/fixtures.php';

use PrestaShop\Module\Unipayment\Calculator\AvailableScheme;
use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\ProductContext;
use PrestaShop\Module\Unipayment\Cart\CartContext;
use PrestaShop\Module\Unipayment\Cart\CartLine;
use PrestaShop\Module\Unipayment\Cart\CartSchemeResolver;

function assertCart(bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }
function cartLine(int $id, array $categories, float $cartTotal, int $quantity = 1, float $lineTotal = 0): CartLine {
    return new CartLine(new ProductContext($id, $categories, $cartTotal), 0, $quantity, $lineTotal ?: $cartTotal);
}

$calculator = new Calculator('2026-08-17');
$resolver = new CartSchemeResolver($calculator);
$default = calculatorFixture();

$single = $resolver->resolve($default, new CartContext([cartLine(1, [7], 1000)], 1000));
assertCart(count($single->standardSchemes) === 3, 'single-product cart did not preserve its schemes');

$same = $resolver->resolve($default, new CartContext([cartLine(1, [7], 1000, 1, 400), cartLine(2, [8], 1000, 1, 600)], 1000));
assertCart(count($same->standardSchemes) === 3 && $same->standardOffer->months === 12, 'same standard scheme was not common');
assertCart(count($same->promoSchemes) === 2 && $same->promoOffer !== null, 'same promo scheme was not common');

$quantity = $resolver->resolve($default, new CartContext([cartLine(1, [7], 600, 2, 600)], 600));
assertCart($quantity->standardOffer !== null && $quantity->promoOffer !== null, 'quantity > 1 did not affect eligibility through cart total');

$differentKop = calculatorFixture(['uni_typekop' => 1, 'kop' => ['by_schema' => ['filters' => [
    ['id' => 1, 'product_id' => 1, 'category_id' => null, 'uni_meseci' => '12', 'uni_promo' => 0, 'uni_kop' => 'CAT'],
    ['id' => 2, 'product_id' => 2, 'category_id' => null, 'uni_meseci' => '12', 'uni_promo' => 0, 'uni_kop' => 'PRODUCT'],
]]]]);
assertCart($resolver->resolve($differentKop, new CartContext([cartLine(1, [], 1000), cartLine(2, [], 1000)], 1000))->standardSchemes === [], 'different KOPs were accepted');

$differentMonths = calculatorFixture(['uni_typekop' => 1, 'kop' => ['by_schema' => ['filters' => [
    ['id' => 3, 'product_id' => 1, 'category_id' => null, 'uni_meseci' => '6', 'uni_promo' => 0, 'uni_kop' => 'CAT'],
    ['id' => 4, 'product_id' => 2, 'category_id' => null, 'uni_meseci' => '12', 'uni_promo' => 0, 'uni_kop' => 'CAT'],
]]]]);
assertCart($resolver->resolve($differentMonths, new CartContext([cartLine(1, [], 1000), cartLine(2, [], 1000)], 1000))->standardSchemes === [], 'different allowed months were accepted');

// Intentional Woo legacy parity: filter identity is metadata, not part of the common-scheme key.
$filterParity = calculatorFixture(['uni_typekop' => 1, 'kop' => ['by_schema' => ['filters' => [
    ['id' => 31, 'product_id' => 1, 'category_id' => null, 'uni_meseci' => '12', 'uni_promo' => 0, 'uni_parva' => 0, 'uni_kop' => 'CAT'],
    ['id' => 32, 'product_id' => 2, 'category_id' => null, 'uni_meseci' => '12', 'uni_promo' => 0, 'uni_parva' => 0, 'uni_kop' => 'CAT'],
]]]]);
$filterResult = $resolver->resolve($filterParity, new CartContext([cartLine(1, [], 1000), cartLine(2, [], 1000)], 1000));
assertCart(count($filterResult->standardSchemes) === 1 && $filterResult->standardSchemes[0]->filterId === 31, 'different filter metadata broke Woo intersection parity');

$firstInstallment = $calculator->calculateScheme($filterParity, 1000, $filterResult->standardSchemes[0]);
assertCart($firstInstallment->firstInstallment->amount === 0.0, 'cart first installment did not use Phase 5 calculation');

// Intentional Woo legacy parity: each product identity is tested with cart total as its price.
$totalPrice = calculatorFixture(['uni_typekop' => 1, 'kop' => ['by_schema' => ['filters' => [[
    'id' => 40, 'category_id' => 7, 'product_id' => null, 'uni_meseci' => '12', 'uni_price_from' => 900,
    'uni_price_to' => 1100, 'uni_promo' => 0, 'uni_parva' => 0, 'uni_kop' => 'CAT',
]]]]]);
$totalResult = $resolver->resolve($totalPrice, new CartContext([cartLine(1, [7], 1000, 2, 400), cartLine(2, [7], 1000, 1, 600)], 1000));
assertCart(count($totalResult->standardSchemes) === 1, 'line total was incorrectly used instead of cart total');

assertCart($resolver->lcm([6, 12]) === 12 && $resolver->lcm([6, 8]) === 24, 'LCM helper differs from Woo');
assertCart($resolver->resolve($default, new CartContext([cartLine(1, [], 99)], 99))->standardSchemes === [], 'minimum boundary failed');
assertCart($resolver->resolve($default, new CartContext([cartLine(1, [], 10000)], 10000))->standardSchemes !== [], 'maximum inclusive boundary failed');
assertCart($resolver->resolve($default, new CartContext([cartLine(1, [], 10000.01)], 10000.01))->standardSchemes === [], 'maximum boundary failed');
assertCart($resolver->resolve($differentKop, new CartContext([cartLine(1, [], 1000), cartLine(2, [], 1000)], 1000))->promoSchemes === [], 'incompatible cart unexpectedly has promo');

$promoFilters = calculatorFixture(['uni_typekop' => 1, 'kop' => ['by_schema' => ['filters' => [
    ['id' => 51, 'product_id' => 1, 'category_id' => null, 'uni_meseci' => '12', 'uni_promo' => 1, 'uni_kop' => 'ZERO'],
    ['id' => 52, 'product_id' => 2, 'category_id' => null, 'uni_meseci' => '12', 'uni_promo' => 1, 'uni_kop' => 'ZERO'],
]]]]);
assertCart(count($resolver->resolve($promoFilters, new CartContext([cartLine(1, [], 1000), cartLine(2, [], 1000)], 1000))->promoSchemes) === 1, 'common promo schema failed');

fwrite(STDOUT, "OK (Phase 8 cart scheme resolver)\n");
