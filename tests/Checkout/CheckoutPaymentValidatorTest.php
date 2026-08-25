<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
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
use PrestaShop\Module\Unipayment\Checkout\CheckoutPaymentValidator;
use PrestaShop\Module\Unipayment\Checkout\CheckoutValidationException;
use PrestaShop\Module\Unipayment\Checkout\ConsentResolver;
use PrestaShop\Module\Unipayment\Checkout\CustomerFieldValidator;

function assertCheckout(bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }
function expectCheckoutFailure(callable $callback, string $message): void { try { $callback(); } catch (CheckoutValidationException $exception) { return; } assertCheckout(false, $message); }
function checkoutCart(float $total = 1000, int $quantity = 1, bool $second = false, array $checkoutState = []): CartContext {
    $lines = [new CartLine(new ProductContext(42, [7], $total), 3, $quantity, $second ? 400 : $total)];
    if ($second) $lines[] = new CartLine(new ProductContext(43, [7], $total), 0, 1, $total - 400);
    return new CartContext($lines, $total, $checkoutState);
}

$calculator = new Calculator('2026-08-17');
$snapshot = new CartSnapshot();
$signer = new CartSnapshotSigner('test-key');
$validator = new CheckoutPaymentValidator($calculator, new CartSchemeResolver($calculator), new CurrencyGate(), $snapshot, $signer, new CustomerFieldValidator(), new ConsentResolver());
$shop = calculatorFixture(['uni_eur' => 0]);
$cart = checkoutCart();
$customer = ['first_name' => 'Ivan', 'last_name' => 'Ivanov', 'address' => 'Sofia 1', 'phone' => '+359 888 123', 'email' => 'ivan@example.com'];
$token = $signer->sign($snapshot->fingerprint($cart, 'BGN'));
$base = ['scheme_key' => '12:0', 'kop_code' => 'STD', 'first_installment' => 100, 'cart_snapshot' => $token];

$standard = $validator->validate($shop, $cart, 'BGN', $base + ['monthly_installment' => 0.01, 'gpr' => 999], $customer);
assertCheckout($standard->calculation->monthlyInstallment === 85.5, 'server-side recalculation did not determine final values');
assertCheckout($standard->calculation->gpr !== 999.0, 'browser financial values were trusted');
$freeShipping = checkoutCart(1000, 1, false, ['carrier_id' => 1, 'shipping_total' => '0.00', 'cart_rules' => []]);
$freeToken = $signer->sign($snapshot->fingerprint($freeShipping, 'BGN'));
$freeResult = $validator->validate($shop, $freeShipping, 'BGN', array_replace($base, ['cart_snapshot' => $freeToken]), $customer);
assertCheckout($freeResult->calculation->price === 1000.0, 'free shipping changed the final checkout total');
$paidShipping = checkoutCart(1050, 1, false, ['carrier_id' => 2, 'shipping_total' => '50.00', 'cart_rules' => []]);
$paidToken = $signer->sign($snapshot->fingerprint($paidShipping, 'BGN'));
$paidResult = $validator->validate($shop, $paidShipping, 'BGN', array_replace($base, ['cart_snapshot' => $paidToken]), $customer);
assertCheckout($paidResult->calculation->price === 1050.0, 'paid shipping was excluded from financing calculation');
$discounted = checkoutCart(900, 1, false, ['carrier_id' => 1, 'shipping_total' => '0.00', 'cart_rules' => [['id_cart_rule' => 7, 'value_real' => '100.00', 'free_shipping' => 0]]]);
$discountToken = $signer->sign($snapshot->fingerprint($discounted, 'BGN'));
$discountResult = $validator->validate($shop, $discounted, 'BGN', array_replace($base, ['cart_snapshot' => $discountToken]), $customer);
assertCheckout($discountResult->calculation->price === 900.0, 'discount was excluded from financing calculation');
expectCheckoutFailure(function () use ($validator, $shop, $paidShipping, $freeToken, $base, $customer): void {
    $validator->validate($shop, $paidShipping, 'BGN', array_replace($base, ['cart_snapshot' => $freeToken]), $customer);
}, 'changed shipping method accepted');
$changedDiscount = checkoutCart(900, 1, false, ['carrier_id' => 1, 'shipping_total' => '0.00', 'cart_rules' => [['id_cart_rule' => 8, 'value_real' => '100.00', 'free_shipping' => 0]]]);
expectCheckoutFailure(function () use ($validator, $shop, $changedDiscount, $discountToken, $base, $customer): void {
    $validator->validate($shop, $changedDiscount, 'BGN', array_replace($base, ['cart_snapshot' => $discountToken]), $customer);
}, 'changed discount accepted');
$promo = $validator->validate($shop, $cart, 'BGN', array_replace($base, ['scheme_key' => 'p:12:0', 'kop_code' => 'PROMO', 'first_installment' => 0]), $customer);
assertCheckout($promo->calculation->scheme->type === 'promo' && $promo->calculation->glp === 0.0, 'valid promo selection failed');

expectCheckoutFailure(function () use ($validator, $shop, $cart, $base, $customer): void { $validator->validate($shop, $cart, 'BGN', array_replace($base, ['scheme_key' => '18:0']), $customer); }, 'invalid months accepted');
$changedConfiguration = calculatorFixture(['uni_eur' => 0, 'uni_meseci_12' => 0]);
expectCheckoutFailure(function () use ($validator, $changedConfiguration, $cart, $base, $customer): void { $validator->validate($changedConfiguration, $cart, 'BGN', $base, $customer); }, 'scheme invalidated by configuration change was accepted');
expectCheckoutFailure(function () use ($validator, $shop, $cart, $base, $customer): void { $validator->validate($shop, $cart, 'BGN', array_replace($base, ['kop_code' => 'BAD']), $customer); }, 'invalid KOP accepted');
expectCheckoutFailure(function () use ($validator, $shop, $cart, $base, $customer): void { $validator->validate($shop, $cart, 'BGN', array_replace($base, ['scheme_key' => '12:99']), $customer); }, 'invalid filter metadata accepted');
$schemaShop = calculatorFixture(['uni_eur' => 0, 'uni_typekop' => 1, 'kop' => ['by_schema' => ['filters' => [[
    'id' => 11, 'product_id' => 42, 'category_id' => null, 'uni_meseci' => '24', 'uni_promo' => 0, 'uni_parva' => 0, 'uni_kop' => 'PRODUCT',
]]]]]);
expectCheckoutFailure(function () use ($validator, $schemaShop, $cart, $token, $customer): void {
    $validator->validate($schemaShop, $cart, 'BGN', ['scheme_key' => '24', 'kop_code' => 'PRODUCT', 'first_installment' => 0, 'cart_snapshot' => $token], $customer);
}, 'missing schema filter metadata accepted');
expectCheckoutFailure(function () use ($validator, $shop, $base, $customer): void { $validator->validate($shop, checkoutCart(1100), 'BGN', $base, $customer); }, 'final total mismatch accepted');
expectCheckoutFailure(function () use ($validator, $shop, $base, $customer): void { $validator->validate($shop, checkoutCart(1000, 2), 'BGN', $base, $customer); }, 'changed quantity accepted');
expectCheckoutFailure(function () use ($validator, $shop, $base, $customer): void { $validator->validate($shop, checkoutCart(1000, 1, true), 'BGN', $base, $customer); }, 'added product accepted');
expectCheckoutFailure(function () use ($validator, $shop, $base, $customer): void { $validator->validate($shop, new CartContext([], 0), 'BGN', $base, $customer); }, 'removed product accepted');
expectCheckoutFailure(function () use ($validator, $shop, $cart, $base, $customer): void { $validator->validate($shop, $cart, 'BGN', array_replace($base, ['first_installment' => 1000]), $customer); }, 'invalid first installment accepted');

$consentShop = calculatorFixture(['uni_eur' => 0, 'consents' => [['id' => 7, 'name' => 'Terms', 'mandatory' => 1]]]);
expectCheckoutFailure(function () use ($validator, $consentShop, $cart, $base, $customer): void { $validator->validate($consentShop, $cart, 'BGN', $base, $customer); }, 'missing mandatory consent accepted');
$withConsent = $validator->validate($consentShop, $cart, 'BGN', $base + ['consent' => [7]], $customer);
assertCheckout($withConsent->acceptedConsentIds === [7], 'mandatory consent validation failed');

$process2 = calculatorFixture(['uni_eur' => 0, 'uni_proces' => 1]);
expectCheckoutFailure(function () use ($validator, $process2, $cart, $base, $customer): void { $validator->validate($process2, $cart, 'BGN', $base + ['egn' => 'bad', 'phone2' => 'x'], $customer); }, 'invalid customer field accepted');
$processRequest = $validator->validate($process2, $cart, 'BGN', $base + ['egn' => '1990010199', 'phone2' => '+359 2 123'], $customer);
assertCheckout($processRequest->customer['egn'] === '1990010199', 'valid Process 2 customer fields failed');

$lockedShop = calculatorFixture(['uni_eur' => 0, 'uni_typekop' => 1, 'kop' => ['by_schema' => ['filters' => [[
    'id' => 11, 'product_id' => 42, 'category_id' => null, 'uni_meseci' => '24', 'uni_promo' => 0, 'uni_parva' => 1, 'uni_kop' => 'PRODUCT',
]]]]]);
$lockedPosted = ['scheme_key' => '24:11', 'kop_code' => 'PRODUCT', 'first_installment' => 9999, 'cart_snapshot' => $token];
$locked = $validator->validate($lockedShop, $cart, 'BGN', $lockedPosted, $customer);
assertCheckout($locked->calculation->firstInstallment->locked && $locked->calculation->firstInstallment->amount === 41.67, 'locked first installment trusted browser value');

fwrite(STDOUT, "OK (Phase 9 checkout server-side validation)\n");
