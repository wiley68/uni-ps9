<?php

declare(strict_types=1);

/**
 * Regression: Product "Купи" preselect must not write pipe-delimited scheme_key into Cookie
 * (PrestaShopException Forbidden chars in cookie — baseline 39cf239).
 *
 * Product page uses create() + linesFingerprint only; full fingerprint binds at checkout.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Cart\CartContext;
use PrestaShop\Module\Unipayment\Cart\CartLine;
use PrestaShop\Module\Unipayment\Calculator\ProductContext;
use PrestaShop\Module\Unipayment\Checkout\CartSnapshot;
use PrestaShop\Module\Unipayment\Checkout\CheckoutPreferenceStore;

function assertKupiPreselect(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * Mimics PrestaShop\Cookie::__set pipe/section guard.
 */
final class CookieGuardCookie
{
    /** @var array<string, mixed> */
    public array $data = [];

    public function __get(string $name)
    {
        return $this->data[$name] ?? '';
    }

    /** @param mixed $value */
    public function __set(string $name, mixed $value): void
    {
        if (is_array($value)) {
            throw new Exception("Cookie value can't be an array.");
        }
        if (preg_match('/¤|\|/', $name . (string) $value)) {
            throw new Exception('Forbidden chars in cookie');
        }
        $this->data[$name] = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->data[$name]);
    }

    public function __unset(string $name): void
    {
        unset($this->data[$name]);
    }

    public function write(): void {}
}

$root = dirname(__DIR__, 2);
$preselectSrc = (string) file_get_contents($root . '/src/Product/ProductPopupCheckoutPreselectionService.php');
$storeSrc = (string) file_get_contents($root . '/src/Checkout/CheckoutPreferenceStore.php');

assertKupiPreselect(
    strpos($preselectSrc, '->createForCheckout(') === false,
    'Product Купи must not require createForCheckout on product page'
);
assertKupiPreselect(
    (bool) preg_match('/CartContextFactory\(\)\)->create\(\$cart\)/', $preselectSrc),
    'Product Купи must use CartContextFactory::create()'
);
assertKupiPreselect(
    strpos($preselectSrc, 'linesFingerprint') !== false,
    'Product Купи stores lines fingerprint'
);
assertKupiPreselect(
    !preg_match("/'scheme_key'\s*=>/", $preselectSrc),
    'Product Купи must not persist pipe-delimited scheme_key into cookie preference'
);
assertKupiPreselect(
    !preg_match("/'cart_fingerprint'\s*=>/", $preselectSrc),
    'Product Купи must not write full checkout fingerprint before checkout'
);
assertKupiPreselect(
    strpos($storeSrc, "unset(\$preference['scheme_key']") !== false
        || strpos($storeSrc, 'unset($preference[\'scheme_key\']') !== false,
    'CheckoutPreferenceStore must strip scheme_key before cookie write'
);

$store = new CheckoutPreferenceStore();
$cookie = new CookieGuardCookie();
$snapshot = new CartSnapshot();

// Cart without carrier/shipping (product-page state).
$productCart = new CartContext(
    [new CartLine(new ProductContext(42, [1], 199.99), 7, 2, 399.98)],
    199.99,
    []
);
$linesFp = $snapshot->linesFingerprint($productCart, 'BGN');

$preference = [
    'product_id' => 42,
    'product_attribute_id' => 7,
    'quantity' => 2,
    'scheme_type' => 'standard',
    'kop_code' => 'POS COM 50',
    'months' => 12,
    'filter_id' => 0,
    'first_installment' => 25,
    'product_amount' => 199.99,
    'lines_fingerprint' => $linesFp,
    'flow' => 'product_preselect',
    // Would have crashed Cookie on baseline 39cf239:
    'scheme_key' => 'standard|POS%20COM%2050|12|0',
];

$store->save($cookie, $preference, 9001, 0);
$raw = (string) $cookie->unipayment_checkout_preference;
assertKupiPreselect($raw !== '', 'preference cookie written without Forbidden chars');
assertKupiPreselect(strpos($raw, '|') === false, 'saved preference JSON must contain no pipe characters');
$decoded = json_decode($raw, true);
assertKupiPreselect(is_array($decoded), 'preference JSON decodes');
assertKupiPreselect(!array_key_exists('scheme_key', $decoded), 'scheme_key stripped before cookie write');
assertKupiPreselect(($decoded['lines_fingerprint'] ?? '') === $linesFp, 'lines fingerprint persisted');
assertKupiPreselect(!isset($decoded['cart_fingerprint']) || $decoded['cart_fingerprint'] === '', 'no full fingerprint on product handoff');

// First checkout render with shipping: full fingerprint binds once.
$checkoutCart = new CartContext(
    [new CartLine(new ProductContext(42, [1], 209.99), 7, 2, 399.98)],
    209.99,
    [
        'id_cart' => 9001,
        'carrier_id' => 3,
        'delivery_option' => ['1,' => '3,'],
        'shipping_total' => '10.00',
        'cart_rules' => [],
    ]
);
$fullFp = $snapshot->fingerprint($checkoutCart, 'BGN');
$linesAtCheckout = $snapshot->linesFingerprint($checkoutCart, 'BGN');
assertKupiPreselect($linesAtCheckout === $linesFp, 'lines fingerprint ignores shipping/total');

$bound = $store->load($cookie, 9001, 0, $fullFp, $linesAtCheckout);
assertKupiPreselect(is_array($bound), 'preference survives first checkout render / binds');
assertKupiPreselect(($bound['cart_fingerprint'] ?? '') === $fullFp, 'full fingerprint bound at checkout');
assertKupiPreselect(!empty($bound['checkout_fingerprint_bound']), 'checkout_fingerprint_bound set');
assertKupiPreselect((int) ($bound['months'] ?? 0) === 12, 'scheme months survive');
assertKupiPreselect((float) ($bound['first_installment'] ?? 0) === 25.0, 'first installment survives');

// Material line drift after binding invalidates.
$driftCart = new CartContext(
    [new CartLine(new ProductContext(42, [1], 209.99), 7, 9, 399.98)],
    209.99,
    $checkoutCart->checkoutState
);
assertKupiPreselect(
    $store->load(
        $cookie,
        9001,
        0,
        $snapshot->fingerprint($driftCart, 'BGN'),
        $snapshot->linesFingerprint($driftCart, 'BGN')
    ) === null,
    'material quantity drift after binding rejects preference'
);

// Direct pipe value must still be rejected by store guard.
$bad = new CookieGuardCookie();
$threw = false;
try {
    $store->save($bad, [
        'kop_code' => 'A|B',
        'flow' => 'product_preselect',
        'lines_fingerprint' => $linesFp,
    ], 1, 0);
} catch (InvalidArgumentException $exception) {
    $threw = true;
}
assertKupiPreselect($threw, 'cookie-unsafe kop_code must be rejected by store');

fwrite(STDOUT, "OK (Product Купи preselect cookie-safe handoff regression)\n");
