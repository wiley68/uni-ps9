<?php

declare(strict_types=1);

/**
 * Characterization of proven PS9 behavior that v2.0.2 must preserve (STOP 1).
 *
 * Covers stable identity / eligibility / preference semantics — not presentation
 * ordering that v2.0.2 intentionally changes.
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
use PrestaShop\Module\Unipayment\Checkout\CheckoutSchemeIdentity;
use PrestaShop\Module\Unipayment\Checkout\ConsentResolver;
use PrestaShop\Module\Unipayment\Product\ProductPopupSchemeList;

function assertChar(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$calculator = new Calculator('2026-08-17');
$shop = calculatorFixture();
$product = new ProductContext(42, [7], 1000.0);

// Product: scheme identity + zero-interest promo identification
$popup = new ProductPopupSchemeList($calculator);
$stdSchemes = $popup->schemes($shop, $product, 'standard');
assertChar(count($stdSchemes) >= 3, 'standard popup exposes schemes');
$promoOnly = $popup->schemes($shop, $product, 'promo');
assertChar($promoOnly !== [], 'promo popup exposes schemes');
foreach ($promoOnly as $scheme) {
    assertChar(
        abs((float) ($scheme->coefficient['interestPercent'] ?? -1)) <= 0.00001,
        'promo schemes are zero-interest'
    );
}

$preferred = $calculator->resolvePreferredOffers($shop, $product);
assertChar(
    $preferred['standard'] !== null && $preferred['standard']->months === 12,
    'product preferred standard uses uni_shema_current'
);
assertChar(
    $preferred['promo'] !== null && in_array($preferred['promo']->months, [12, 24], true),
    'product preferred promo is an eligible 0% offer'
);

$key = ProductPopupSchemeList::keyFromParts('standard', 'STD', 12, 0);
assertChar($key === 'standard|STD|12|0', 'scheme identity includes type+kop+months+filterId');

// Cart: intersection identity ignores filter_id; LCM helper
$resolver = new CartSchemeResolver($calculator);
$filterParity = calculatorFixture(['uni_typekop' => 1, 'kop' => ['by_schema' => ['filters' => [
    ['id' => 31, 'product_id' => 1, 'category_id' => null, 'uni_meseci' => '12', 'uni_promo' => 0, 'uni_parva' => 0, 'uni_kop' => 'CAT'],
    ['id' => 32, 'product_id' => 2, 'category_id' => null, 'uni_meseci' => '12', 'uni_promo' => 0, 'uni_parva' => 0, 'uni_kop' => 'CAT'],
]]]]);
$filterResult = $resolver->resolve(
    $filterParity,
    new CartContext([
        new CartLine(new ProductContext(1, [], 1000), 0, 1, 1000),
        new CartLine(new ProductContext(2, [], 1000), 0, 1, 1000),
    ], 1000)
);
assertChar(count($filterResult->standardSchemes) === 1, 'cart intersection identity is type+kop+months');
assertChar($resolver->lcm([6, 12]) === 12, 'LCM behavior preserved');

// Checkout: longest 0% default + CheckoutSchemeIdentity exact preference + preference_unresolved
$checkout = new CheckoutPaymentPresenter(
    $calculator,
    $resolver,
    new CurrencyGate(),
    new CartSnapshot(),
    new CartSnapshotSigner('test-key'),
    new ConsentResolver()
);
$cart = new CartContext([new CartLine($product, 0, 1, 1000)], 1000);
$view = $checkout->present(true, $shop, $cart, 'BGN');
assertChar(is_array($view), 'checkout view present');
assertChar(array_key_exists('preference_unresolved', $view), 'PS9 preference_unresolved field present');
assertChar($view['preference_unresolved'] === false, 'no preference → preference_unresolved false');

$zeros = array_values(array_filter($view['schemes'], static function (array $s): bool {
    return !empty($s['zero_interest']);
}));
assertChar($zeros !== [], 'checkout exposes zero-interest schemes');
$maxZero = max(array_map(static function (array $s): int {
    return (int) $s['months'];
}, $zeros));
assertChar($view['default_scheme_key'] === 'p:' . $maxZero . ':0', 'checkout default is longest 0%');

$short = null;
foreach ($view['schemes'] as $scheme) {
    if ($scheme['scheme_type'] === 'standard' && (int) $scheme['months'] === 6) {
        $short = $scheme;
        break;
    }
}
assertChar($short !== null, 'fixture exposes shorter standard scheme');
$pref = $checkout->present(true, $shop, $cart, 'BGN', [
    'scheme_type' => $short['scheme_type'],
    'kop_code' => $short['kop_code'],
    'months' => $short['months'],
    'filter_id' => $short['filter_id'],
    'first_installment' => 50.0,
]);
assertChar($pref['default_scheme_key'] === $short['key'], 'exact preference preserved over longer 0%');
assertChar($pref['preselect_payment'] === true, 'preference enables preselect');
assertChar($pref['preference_unresolved'] === false, 'valid preference → preference_unresolved false');

$resolved = CheckoutSchemeIdentity::resolve($view['schemes'], [
    'scheme_type' => $short['scheme_type'],
    'kop_code' => $short['kop_code'],
    'months' => $short['months'],
    'filter_id' => $short['filter_id'],
]);
assertChar($resolved !== null && $resolved['key'] === $short['key'], 'CheckoutSchemeIdentity exact match');

$invalid = $checkout->present(true, $shop, $cart, 'BGN', [
    'scheme_type' => 'standard',
    'kop_code' => 'NO_SUCH_KOP',
    'months' => 99,
    'filter_id' => 999,
    'first_installment' => 0,
]);
assertChar($invalid['preference_unresolved'] === true, 'invalid preference → preference_unresolved true');
assertChar($invalid['preselect_payment'] === false, 'invalid preference does not preselect');
assertChar(
    $invalid['default_scheme_key'] === $view['default_scheme_key'],
    'invalid preference does not override automatic default'
);

fwrite(STDOUT, "OK (v2.0.2 characterization baseline)\n");
