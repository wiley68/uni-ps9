<?php

declare(strict_types=1);

/**
 * Cart ↔ product popup flow isolation + cart_total drift binding.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

final class Context
{
    /** @var object */
    public $cookie;
    /** @var object|null */
    public $customer;
    /** @var object */
    public $shop;
}

final class Customer
{
    public int $id = 0;
    public bool $logged = false;

    public function isLogged(): bool
    {
        return $this->logged;
    }
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Product\PopupSubmissionBindingFactory;
use PrestaShop\Module\Unipayment\Product\PopupSubmissionSelectionHash;

function assertFlow(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$ctx = new Context();
$ctx->cookie = (object) ['id_guest' => 11];
$ctx->customer = null;
$ctx->shop = (object) ['id' => 1];
$factory = new PopupSubmissionBindingFactory();

$product = $factory->fromSelection([
    'id_product' => 42,
    'id_product_attribute' => 0,
    'quantity' => 1,
    'scheme_type' => 'standard',
    'kop_code' => 'CAT',
    'months' => 12,
    'filter_id' => 1,
    'scheme_key' => 'standard|CAT|12|1',
    'first_installment' => 0,
], $ctx);

$cart = $factory->fromCartSelection([
    'id_cart' => 9001,
    'cart_total' => 100.0,
    'scheme_type' => 'standard',
    'kop_code' => 'CAT',
    'months' => 12,
    'filter_id' => 1,
    'scheme_key' => 'standard|CAT|12|1',
    'first_installment' => 0,
], $ctx);

assertFlow($product['hash'] !== $cart['hash'], 'product and cart flows must produce distinct selection hashes');
assertFlow($product['binding']['flow'] === PopupSubmissionSelectionHash::FLOW_PRODUCT_POPUP, 'product flow marker');
assertFlow($cart['binding']['flow'] === PopupSubmissionSelectionHash::FLOW_CART_POPUP, 'cart flow marker');

$cartDrift = $factory->fromCartSelection([
    'id_cart' => 9001,
    'cart_total' => 200.0,
    'scheme_type' => 'standard',
    'kop_code' => 'CAT',
    'months' => 12,
    'filter_id' => 1,
    'scheme_key' => 'standard|CAT|12|1',
    'first_installment' => 0,
], $ctx);
assertFlow($cart['hash'] !== $cartDrift['hash'], 'qty/cart_total drift must change cart selection hash');

$cartRemoved = $factory->fromCartSelection([
    'id_cart' => 9002,
    'cart_total' => 100.0,
    'scheme_type' => 'standard',
    'kop_code' => 'CAT',
    'months' => 12,
    'filter_id' => 1,
    'scheme_key' => 'standard|CAT|12|1',
    'first_installment' => 0,
], $ctx);
assertFlow($cart['hash'] !== $cartRemoved['hash'], 'different id_cart must change selection hash');

$sameReuse = $factory->fromCartSelection([
    'id_cart' => 9001,
    'cart_total' => 100.0,
    'scheme_type' => 'standard',
    'kop_code' => 'CAT',
    'months' => 12,
    'filter_id' => 1,
    'scheme_key' => 'standard|CAT|12|1',
    'first_installment' => 0,
], $ctx);
assertFlow($sameReuse['hash'] === $cart['hash'], 'identical cart identity must reuse hash');

$otherGuest = new Context();
$otherGuest->cookie = (object) ['id_guest' => 99];
$otherGuest->customer = null;
$otherGuest->shop = (object) ['id' => 1];
$crossSession = $factory->fromCartSelection([
    'id_cart' => 9001,
    'cart_total' => 100.0,
    'scheme_type' => 'standard',
    'kop_code' => 'CAT',
    'months' => 12,
    'filter_id' => 1,
    'scheme_key' => 'standard|CAT|12|1',
    'first_installment' => 0,
], $otherGuest);
assertFlow($crossSession['hash'] !== $cart['hash'], 'cross-guest session must change hash');

$customer = new Customer();
$customer->id = 55;
$customer->logged = true;
$logged = new Context();
$logged->cookie = (object) ['id_guest' => 11];
$logged->customer = $customer;
$logged->shop = (object) ['id' => 1];
$crossCustomer = $factory->fromCartSelection([
    'id_cart' => 9001,
    'cart_total' => 100.0,
    'scheme_type' => 'standard',
    'kop_code' => 'CAT',
    'months' => 12,
    'filter_id' => 1,
    'scheme_key' => 'standard|CAT|12|1',
    'first_installment' => 0,
], $logged);
assertFlow($crossCustomer['hash'] !== $cart['hash'], 'cross-customer must change hash');

fwrite(STDOUT, "OK (Phase 8 cart flow isolation + drift binding)\n");
