<?php

declare(strict_types=1);

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

$cookie = new FakeCheckoutCookie();
$store = new CheckoutPreferenceStore();
$store->save($cookie, $preference, 91001, 0);
$loaded = $store->load($cookie, 91001, 0);
assertCheckoutPreferenceStore(is_array($loaded), 'saved checkout preference must load for the same cart/customer');
assertCheckoutPreferenceStore((int) ($loaded['months'] ?? 0) === 12, 'months must survive cookie roundtrip');
assertCheckoutPreferenceStore($store->load($cookie, 91002, 0) === null, 'preference must be scoped to cart id');

$cookie2 = new FakeCheckoutCookie();
$store->save($cookie2, $preference, 91001, 0);
assertCheckoutPreferenceStore(
    $store->load($cookie2, 91001, 0, str_repeat('b', 64)) === null,
    'mismatched cart_fingerprint must invalidate preference'
);

$cookie3 = new FakeCheckoutCookie();
$store->save($cookie3, $preference, 91001, 0);
assertCheckoutPreferenceStore(
    is_array($store->load($cookie3, 91001, 0, str_repeat('a', 64))),
    'matching cart_fingerprint must keep preference'
);

$store->clear($cookie);
assertCheckoutPreferenceStore($store->load($cookie, 91001, 0) === null, 'clear must invalidate checkout preference');

fwrite(STDOUT, "OK (Checkout preference cookie-safe persistence)\n");
