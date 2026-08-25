<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/Calculator/fixtures.php';

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\ProductContext;
use PrestaShop\Module\Unipayment\Cart\CartCalculatorPresenter;
use PrestaShop\Module\Unipayment\Cart\CartContext;
use PrestaShop\Module\Unipayment\Cart\CartLine;
use PrestaShop\Module\Unipayment\Cart\CartSchemeResolver;

function assertCartPresenter(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}
$calculator = new Calculator('2026-08-17');
$presenter = new CartCalculatorPresenter(new CartSchemeResolver($calculator), $calculator);
$cart = new CartContext([new CartLine(new ProductContext(42, [7], 1000), 0, 2, 1000)], 1000);
$view = $presenter->present(calculatorFixture(['uni_eur' => 0]), $cart, 'BGN');
assertCartPresenter(is_array($view) && isset($view['offers']['standard'], $view['offers']['promo']), 'cart presentation offers missing');
assertCartPresenter($view['line_count'] === 1 && $view['cart_total'] === 1000.0, 'cart presentation context differs');
assertCartPresenter(isset($view['offers']['standard']['installment_label']), 'cart offers must expose installment_label');
assertCartPresenter(isset($view['offers']['standard']['preferred_scheme_key']), 'cart offers must expose preferred_scheme_key');
assertCartPresenter(isset($view['offers']['standard']['schemes'][0]['key']), 'cart scheme rows must expose popup scheme key');
assertCartPresenter((bool) preg_match('/^\d+ x \d+\.\d{2} лв\.$/u', $view['offers']['standard']['installment_label']), 'BGN cart button label must match Woo лв. format');
assertCartPresenter(array_key_exists('heading', $view), 'cart presentation must expose CP heading');
assertCartPresenter($presenter->present(calculatorFixture(['uni_eur' => 0]), $cart, 'EUR') === null, 'cart currency gate mismatch');
assertCartPresenter($presenter->present(calculatorFixture(['uni_eur' => 3]), $cart, 'EUR') !== null, 'EUR cart was rejected');

$eurView = $presenter->present(calculatorFixture(['uni_eur' => 3, 'uni_zaglavie' => 'Финансиране от УниКредит']), $cart, 'EUR');
assertCartPresenter(is_array($eurView) && $eurView['heading'] === 'Финансиране от УниКредит', 'CP heading must reach the cart presenter');
assertCartPresenter((bool) preg_match('/^\d+ x \d+\.\d{2} евро$/', $eurView['offers']['standard']['installment_label']), 'EUR cart button label must match Woo format');

fwrite(STDOUT, "OK (Phase 8 cart calculator presenter)\n");
