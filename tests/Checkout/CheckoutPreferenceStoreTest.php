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

    public function write(): void
    {
    }
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

// 8. Phase 9 product preselect writes cart_fingerprint.
$preselect = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Product/ProductPopupCheckoutPreselectionService.php');
assertCheckoutPreferenceStore(
    strpos($preselect, "'cart_fingerprint' => \$fingerprint") !== false
        || strpos($preselect, '"cart_fingerprint"') !== false,
    'Phase 9 product preselect preference must include cart_fingerprint'
);
assertCheckoutPreferenceStore(
    strpos($preselect, 'CartSnapshot') !== false && strpos($preselect, 'createForCheckout') !== false,
    'product preselect must compute fingerprint from authoritative checkout cart'
);

// Current checkoutcalculate also writes fingerprint.
$calculate = (string) file_get_contents(dirname(__DIR__, 2) . '/controllers/front/checkoutcalculate.php');
assertCheckoutPreferenceStore(
    strpos($calculate, "'cart_fingerprint' => \$fingerprint") !== false,
    'checkoutcalculate preference must include cart_fingerprint'
);

fwrite(STDOUT, "OK (Checkout preference cookie-safe persistence + fingerprint binding)\n");
