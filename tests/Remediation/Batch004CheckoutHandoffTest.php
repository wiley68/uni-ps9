<?php

declare(strict_types=1);

/**
 * Batch 004: Product "Купи" checkout consumption — data-driven handoff.
 *
 * Regression vs baseline 311df1c: preference must survive cookie round-trip and
 * resolve to the exact checkout scheme (canonical type+kop+months), including when
 * Product filter_id differs from checkout filter metadata.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

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
use PrestaShop\Module\Unipayment\Checkout\CheckoutPaymentPresenter;
use PrestaShop\Module\Unipayment\Checkout\CheckoutPreferenceStore;
use PrestaShop\Module\Unipayment\Checkout\CheckoutSchemeIdentity;
use PrestaShop\Module\Unipayment\Checkout\ConsentResolver;
use PrestaShop\Module\Unipayment\Checkout\SchemeSelection;

function assertBatch004(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class Batch004Cookie
{
    /** @var array<string, mixed> */
    public array $data = [];

    public function __get(string $name)
    {
        return $this->data[$name] ?? false;
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
$module = (string) file_get_contents($root . '/unipayment.php');
$calculator = new Calculator('2026-08-17');
$snapshot = new CartSnapshot();
$store = new CheckoutPreferenceStore();
$presenter = new CheckoutPaymentPresenter(
    $calculator,
    new CartSchemeResolver($calculator),
    new CurrencyGate(),
    $snapshot,
    new CartSnapshotSigner('batch004-key'),
    new ConsentResolver()
);
$shop = calculatorFixture(['uni_eur' => 0, 'uni_first_vnoska' => 1]);

$productCart = new CartContext(
    [new CartLine(new ProductContext(42, [7], 1000.0), 7, 2, 2000.0)],
    1000.0
);
$checkoutCart = new CartContext(
    [new CartLine(new ProductContext(42, [7], 1010.0), 7, 2, 2000.0)],
    1010.0,
    [
        'id_cart' => 88001,
        'carrier_id' => 3,
        'delivery_option' => ['1,' => '3,'],
        'shipping_total' => '10.00',
        'cart_rules' => [],
    ]
);
$linesFp = $snapshot->linesFingerprint($productCart, 'BGN');
assertBatch004(
    $linesFp === $snapshot->linesFingerprint($checkoutCart, 'BGN'),
    'lines fingerprint stable across shipping evolution'
);

$checkoutView = $presenter->present(true, $shop, $checkoutCart, 'BGN');
assertBatch004(is_array($checkoutView) && $checkoutView['schemes'] !== [], 'checkout exposes schemes');

$target = null;
foreach ($checkoutView['schemes'] as $scheme) {
    if ($scheme['scheme_type'] === 'standard' && (int) $scheme['months'] === 12) {
        $target = $scheme;
        break;
    }
}
assertBatch004($target !== null, 'fixture must expose standard 12m scheme');

// Representative Product preference (cookie-safe structured fields, no raw scheme_key).
// Intentionally different filter_id than checkout row to prove soft filter matching.
$productPreference = [
    'product_id' => 42,
    'product_attribute_id' => 7,
    'quantity' => 2,
    'scheme_type' => 'standard',
    'kop_code' => (string) $target['kop_code'],
    'months' => 12,
    'filter_id' => ((int) $target['filter_id']) + 99,
    'first_installment' => 100.0,
    'product_amount' => 1000.0,
    'lines_fingerprint' => $linesFp,
    'flow' => 'product_preselect',
    'scheme_key' => 'standard|' . rawurlencode((string) $target['kop_code']) . '|12|0',
];

$cookie = new Batch004Cookie();
$store->save($cookie, $productPreference, 88001, 0);
$raw = (string) $cookie->unipayment_checkout_preference;
assertBatch004($raw !== '' && $raw !== 'false', 'cookie round-trip writes preference');
assertBatch004(strpos($raw, '|') === false, 'cookie payload has no pipe characters');
$decoded = json_decode($raw, true);
assertBatch004(is_array($decoded), 'cookie JSON decodes');
assertBatch004(!array_key_exists('scheme_key', $decoded), 'scheme_key stripped on save');
assertBatch004((string) ($decoded['kop_code'] ?? '') === (string) $target['kop_code'], 'kop_code survives cookie');
assertBatch004((int) ($decoded['months'] ?? 0) === 12, 'months survive cookie');
assertBatch004((int) ($decoded['filter_id'] ?? -1) === ((int) $target['filter_id']) + 99, 'product filter_id survives');

// 1. Shipping evolution + one-time/full fingerprint refresh
$fp1 = $snapshot->fingerprint($checkoutCart, 'BGN');
$loaded = $store->load($cookie, 88001, 0, $fp1, $linesFp);
assertBatch004(is_array($loaded), '1: preference loads after shipping evolution');
assertBatch004(!empty($loaded['checkout_fingerprint_bound']), '1: full fingerprint bound');

$afterShip = new CartContext(
    $checkoutCart->lines,
    1025.0,
    [
        'id_cart' => 88001,
        'carrier_id' => 9,
        'delivery_option' => ['1,' => '9,'],
        'shipping_total' => '25.00',
        'cart_rules' => [],
    ]
);
$fp2 = $snapshot->fingerprint($afterShip, 'BGN');
assertBatch004($fp1 !== $fp2, '1: shipping change alters full fingerprint');
$loaded2 = $store->load($cookie, 88001, 0, $fp2, $snapshot->linesFingerprint($afterShip, 'BGN'));
assertBatch004(is_array($loaded2), '1: preference survives further shipping evolution after bind');
assertBatch004(($loaded2['cart_fingerprint'] ?? '') === $fp2, '1: fingerprint refreshed');

// 2. Guest customer evolution
$cookieGuest = new Batch004Cookie();
$store->save($cookieGuest, $productPreference, 88001, 0);
$guest = $store->load($cookieGuest, 88001, 77, $fp2, $linesFp);
assertBatch004(is_array($guest), '2: guest customer evolution survives');
assertBatch004((int) ($guest['customer_id'] ?? 0) === 77, '2: customer identity bound');

// 3. Same Product scheme available → matches (even with filter_id mismatch)
$view = $presenter->present(true, $shop, $afterShip, 'BGN', $loaded2);
assertBatch004(is_array($view), '3: presenter returns view');
assertBatch004($view['preselect_payment'] === true, '3: preselect_payment true');
assertBatch004($view['default_scheme_key'] === $target['key'], '3: default_scheme_key is exact checkout scheme');
assertBatch004((float) $view['default_first_installment'] === 100.0, '3: first installment retained');
assertBatch004(empty($view['preference_unresolved']), '3: preference resolved');

$resolved = CheckoutSchemeIdentity::resolve($view['schemes'], $loaded2);
assertBatch004($resolved !== null && $resolved['key'] === $target['key'], '3: canonical identity resolves target');
assertBatch004(
    SchemeSelection::key('standard', 12, (int) $target['filter_id']) === $target['key'],
    '3: expected key encoding'
);

// 4. Same months but DIFFERENT KOP must not match
$wrongKop = $loaded2;
$wrongKop['kop_code'] = 'OTHER-KOP';
$wrongView = $presenter->present(true, $shop, $afterShip, 'BGN', $wrongKop);
assertBatch004($wrongView['preselect_payment'] === false, '4: different KOP must not preselect');
assertBatch004(!empty($wrongView['preference_unresolved']), '4: different KOP marked unresolved');

// 5. Genuine removed scheme → unresolved (hook clears only then)
$missing = $loaded2;
$missing['months'] = 99;
$missingView = $presenter->present(true, $shop, $afterShip, 'BGN', $missing);
assertBatch004($missingView['preselect_payment'] === false, '5: removed scheme no preselection');
assertBatch004(!empty($missingView['preference_unresolved']), '5: removed scheme unresolved for safe clear');

// 6. Material product/qty drift → preference rejected
$drift = new CartContext(
    [new CartLine(new ProductContext(42, [7], 1025.0), 7, 9, 2000.0)],
    1025.0,
    $afterShip->checkoutState
);
assertBatch004(
    $store->load($cookie, 88001, 0, $snapshot->fingerprint($drift, 'BGN'), $snapshot->linesFingerprint($drift, 'BGN')) === null,
    '6: material qty drift rejects preference'
);

// 7. Valid match must NOT be cleared by hook policy
assertBatch004(
    strpos($module, "preference_unresolved") !== false
        && (bool) preg_match('/empty\(\$view\[[\'"]preference_unresolved[\'"]\]\)/', $module),
    '7: hook clears preference only when preference_unresolved'
);
assertBatch004(
    !preg_match('/if \(\$preference !== null && empty\(\$view\[[\'"]preselect_payment[\'"]\]\)\) \{\s*\$preferenceStore->clear/s', $module),
    '7: hook must not clear on every presenter mismatch'
);

// 8. Valid match → Media::addJsDef handoff emitted
assertBatch004(strpos($module, 'unipaymentCheckoutHandoff') !== false, '8: Media handoff key present');
assertBatch004(strpos($module, 'Media::addJsDef') !== false, '8: Media::addJsDef used for handoff');

// Soft filter: exact filter preferred when present
$exactPref = $loaded2;
$exactPref['filter_id'] = (int) $target['filter_id'];
$exact = CheckoutSchemeIdentity::resolve($view['schemes'], $exactPref);
assertBatch004($exact !== null && (int) $exact['filter_id'] === (int) $target['filter_id'], 'exact filter preferred');

fwrite(STDOUT, "OK (PRE-AUDIT REMEDIATION BATCH 004 Product Купи checkout consumption)\n");
