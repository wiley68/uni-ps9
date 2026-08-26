<?php

declare(strict_types=1);

/**
 * CheckoutPreferenceStore: cookie-safe persistence + Phase 9 fingerprint binding.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Checkout\CheckoutPreferenceStore;

function assertCheckoutPreferenceStore(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function checkoutPreferenceCookieGuard(string $cookieName, string $value): void
{
    if (preg_match('/¤|\|/', $cookieName . $value)) {
        throw new Exception('Forbidden chars in cookie');
    }
}

final class FakeCheckoutCookie
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

/** @param array<string, mixed> $fields */
function seedPreference(FakeCheckoutCookie $cookie, array $fields, int $cartId, int $customerId, ?int $createdAt = null): void
{
    $fields['cart_id'] = $cartId;
    $fields['customer_id'] = $customerId;
    $fields['created_at'] = $createdAt ?? time();
    $cookie->unipayment_checkout_preference = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

$preference = [
    'scheme_type' => 'standard',
    'kop_code' => 'STD',
    'months' => 12,
    'filter_id' => 0,
    'first_installment' => 100.0,
    'product_amount' => 999.99,
    'cart_fingerprint' => str_repeat('a', 64),
    'flow' => 'product_preselect',
];

$safePayload = json_encode($preference, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
checkoutPreferenceCookieGuard('unipayment_checkout_preference', $safePayload);
assertCheckoutPreferenceStore(strpos($safePayload, '|') === false, 'checkout preference payload must remain cookie-safe without nested calculation');

$unsafePreference = $preference + [
    'calculation' => [
        'scheme_key' => 'standard|POS%20COM%2050|12|5',
        'price_display' => ['primary' => '999.99 евро'],
    ],
];
$unsafePayload = json_encode($unsafePreference, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
assertCheckoutPreferenceStore(strpos($unsafePayload, '|') !== false, 'nested calculation fixture must contain forbidden pipe characters');

try {
    checkoutPreferenceCookieGuard('unipayment_checkout_preference', $unsafePayload);
    assertCheckoutPreferenceStore(false, 'nested calculation payload must not pass PrestaShop cookie guard');
} catch (Exception $exception) {
    assertCheckoutPreferenceStore(
        $exception->getMessage() === 'Forbidden chars in cookie',
        'PrestaShop cookie guard must reject nested calculation payloads'
    );
}

$store = new CheckoutPreferenceStore();
$fp = str_repeat('a', 64);

// 9. Round-trip without expected fingerprint (generic load).
$cookie = new FakeCheckoutCookie();
$store->save($cookie, $preference, 91001, 0);
$loaded = $store->load($cookie, 91001, 0);
assertCheckoutPreferenceStore(is_array($loaded), 'saved checkout preference must load for the same cart/customer');
assertCheckoutPreferenceStore((int) ($loaded['months'] ?? 0) === 12, 'months must survive cookie roundtrip');
assertCheckoutPreferenceStore(($loaded['cart_fingerprint'] ?? '') === $fp, 'Phase 9 preference round-trip keeps fingerprint');

// 7. Wrong cart.
assertCheckoutPreferenceStore($store->load($cookie, 91002, 0) === null, 'preference must be scoped to cart id');

// 6. Wrong customer.
$cookieCustomer = new FakeCheckoutCookie();
$store->save($cookieCustomer, $preference, 91001, 17);
assertCheckoutPreferenceStore($store->load($cookieCustomer, 91001, 0) === null, 'wrong customer must reject preference');

// 5. Expired preference.
$cookieExpired = new FakeCheckoutCookie();
seedPreference($cookieExpired, $preference, 91001, 0, time() - 1801);
assertCheckoutPreferenceStore($store->load($cookieExpired, 91001, 0) === null, 'expired preference must be rejected');

// 1. expected + matching stored → accept.
$cookieMatch = new FakeCheckoutCookie();
$store->save($cookieMatch, $preference, 91001, 0);
assertCheckoutPreferenceStore(
    is_array($store->load($cookieMatch, 91001, 0, $fp)),
    'expected fingerprint + matching stored fingerprint must be accepted'
);

// 2. expected + different stored → reject/clear.
$cookieMismatch = new FakeCheckoutCookie();
$store->save($cookieMismatch, $preference, 91001, 0);
assertCheckoutPreferenceStore(
    $store->load($cookieMismatch, 91001, 0, str_repeat('b', 64)) === null,
    'expected fingerprint + different stored fingerprint must be cleared'
);
assertCheckoutPreferenceStore(
    ($cookieMismatch->unipayment_checkout_preference ?? '') === '',
    'mismatched fingerprint must clear cookie'
);

// 3. expected + missing fingerprint key → reject/clear (legacy Phase 7/8 cookie).
$cookieLegacy = new FakeCheckoutCookie();
$legacy = $preference;
unset($legacy['cart_fingerprint']);
seedPreference($cookieLegacy, $legacy, 91001, 0);
assertCheckoutPreferenceStore(
    $store->load($cookieLegacy, 91001, 0, $fp) === null,
    'expected fingerprint + missing fingerprint key must be cleared'
);
assertCheckoutPreferenceStore(
    ($cookieLegacy->unipayment_checkout_preference ?? '') === '',
    'legacy cookie without fingerprint must be cleared when expected is set'
);

// expected = null + stored missing → generic load may remain.
$cookieLegacyGeneric = new FakeCheckoutCookie();
seedPreference($cookieLegacyGeneric, $legacy, 91001, 0);
assertCheckoutPreferenceStore(
    is_array($store->load($cookieLegacyGeneric, 91001, 0, null)),
    'expected null + missing fingerprint may keep generic load semantics'
);

// 4. expected + empty fingerprint → reject/clear.
$cookieEmpty = new FakeCheckoutCookie();
$emptyFp = $preference;
$emptyFp['cart_fingerprint'] = '';
seedPreference($cookieEmpty, $emptyFp, 91001, 0);
assertCheckoutPreferenceStore(
    $store->load($cookieEmpty, 91001, 0, $fp) === null,
    'expected fingerprint + empty stored fingerprint must be cleared'
);

$cookieWhitespace = new FakeCheckoutCookie();
$wsFp = $preference;
$wsFp['cart_fingerprint'] = '   ';
seedPreference($cookieWhitespace, $wsFp, 91001, 0);
assertCheckoutPreferenceStore(
    $store->load($cookieWhitespace, 91001, 0, $fp) === null,
    'expected fingerprint + whitespace-only stored fingerprint must be cleared'
);

$store->clear($cookie);
assertCheckoutPreferenceStore($store->load($cookie, 91001, 0) === null, 'clear must invalidate checkout preference');

// 8. Product Купи stores lines_fingerprint only; full fingerprint binds at checkout.
$preselect = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Product/ProductPopupCheckoutPreselectionService.php');
assertCheckoutPreferenceStore(
    !preg_match("/'cart_fingerprint'\s*=>/", $preselect),
    'Product Купи must not write full cart_fingerprint before checkout'
);
assertCheckoutPreferenceStore(
    strpos($preselect, 'lines_fingerprint') !== false,
    'product preselect preference must include lines_fingerprint for Купи handoff rebind'
);
assertCheckoutPreferenceStore(
    strpos($preselect, 'CartSnapshot') !== false
        && strpos($preselect, 'linesFingerprint') !== false
        && strpos($preselect, 'CartContextFactory') !== false
        && (bool) preg_match('/->create\(\$cart\)/', $preselect)
        && strpos($preselect, '->createForCheckout(') === false,
    'product preselect must compute lines fingerprint from product-page cart (not checkout-state factory)'
);
assertCheckoutPreferenceStore(
    !preg_match("/'scheme_key'\s*=>/", $preselect),
    'product preselect must not store pipe-delimited scheme_key in cookie preference'
);

// Product "Купи" one-time rebind when carrier evolves but lines identity matches.
$fpFull = str_repeat('a', 64);
$fpFull2 = str_repeat('b', 64);
$fpLines = str_repeat('c', 64);
$handoff = $preference;
$handoff['cart_fingerprint'] = $fpFull;
$handoff['lines_fingerprint'] = $fpLines;
$handoff['flow'] = 'product_preselect';
$cookieHandoff = new FakeCheckoutCookie();
$store->save($cookieHandoff, $handoff, 91001, 0);
$rebound = $store->load($cookieHandoff, 91001, 0, $fpFull2, $fpLines);
assertCheckoutPreferenceStore(is_array($rebound), 'product handoff may rebind full fingerprint once');
assertCheckoutPreferenceStore(($rebound['cart_fingerprint'] ?? '') === $fpFull2, 'rebind updates cart_fingerprint');
assertCheckoutPreferenceStore(!empty($rebound['checkout_fingerprint_bound']), 'rebind sets checkout_fingerprint_bound');
$fpFull3 = str_repeat('e', 64);
$refreshed = $store->load($cookieHandoff, 91001, 0, $fpFull3, $fpLines);
assertCheckoutPreferenceStore(is_array($refreshed), 'product handoff refreshes full fingerprint on shipping evolution while lines match');
assertCheckoutPreferenceStore(($refreshed['cart_fingerprint'] ?? '') === $fpFull3, 'refreshed fingerprint stored');
assertCheckoutPreferenceStore(
    $store->load($cookieHandoff, 91001, 0, str_repeat('f', 64), str_repeat('d', 64)) === null,
    'material lines drift after bind must reject'
);

// Current checkoutcalculate also writes fingerprint.
$calculate = (string) file_get_contents(dirname(__DIR__, 2) . '/controllers/front/checkoutcalculate.php');
assertCheckoutPreferenceStore(
    strpos($calculate, "'cart_fingerprint' => \$fingerprint") !== false,
    'checkoutcalculate preference must include cart_fingerprint'
);

// Empty cart_fingerprint (Product-page handoff) binds on first checkout expected fingerprint.
$cookieEmptyFp = new FakeCheckoutCookie();
$emptyHandoff = $preference;
unset($emptyHandoff['cart_fingerprint']);
$emptyHandoff['lines_fingerprint'] = $fpLines;
$emptyHandoff['flow'] = 'product_preselect';
$store->save($cookieEmptyFp, $emptyHandoff, 91001, 0);
$boundFromEmpty = $store->load($cookieEmptyFp, 91001, 0, $fpFull2, $fpLines);
assertCheckoutPreferenceStore(is_array($boundFromEmpty), 'empty product-page cart_fingerprint may bind at checkout');
assertCheckoutPreferenceStore(($boundFromEmpty['cart_fingerprint'] ?? '') === $fpFull2, 'empty handoff binds expected full fingerprint');

fwrite(STDOUT, "OK (Checkout preference cookie-safe persistence + fingerprint binding)\n");
