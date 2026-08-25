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
use PrestaShop\Module\Unipayment\Cart\CartPopupCalculator;
use PrestaShop\Module\Unipayment\Cart\CartSchemeResolver;
use PrestaShop\Module\Unipayment\Product\ProductPopupSchemeList;

function assertCartPopupCalc(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$calculator = new Calculator('2026-08-17');
$resolver = new CartSchemeResolver($calculator);
$popup = new CartPopupCalculator($calculator, $resolver);
$shop = calculatorFixture(['uni_eur' => 0]);
$cart = new CartContext([new CartLine(new ProductContext(42, [7], 1000), 0, 1, 1000)], 1000.0);

$schemes = $popup->commonSchemes($shop, $cart, 'standard');
assertCartPopupCalc($schemes !== [], 'expected common standard schemes for cart');

$scheme = $schemes[0];
$key = ProductPopupSchemeList::key($scheme);
$result = $popup->calculate(
    $shop,
    $cart,
    'BGN',
    'standard',
    $scheme->type,
    $scheme->kopCode,
    $scheme->months,
    $scheme->filterId,
    $key,
    0.0
);

assertCartPopupCalc(($result['price'] ?? null) === 1000.0, 'cart popup calculation must use cart total');
assertCartPopupCalc(($result['scheme_key'] ?? '') === $key, 'cart popup must echo scheme_key');
assertCartPopupCalc(isset($result['monthly_installment'], $result['price_display']['primary']), 'cart popup display fields missing');

$thrown = false;
try {
    $popup->calculate($shop, $cart, 'BGN', 'standard', $scheme->type, $scheme->kopCode, $scheme->months, $scheme->filterId, 'bad-key', 0.0);
} catch (Throwable $exception) {
    $thrown = true;
}
assertCartPopupCalc($thrown, 'invalid scheme_key must be rejected');

fwrite(STDOUT, "OK (Cart popup calculator)\n");
