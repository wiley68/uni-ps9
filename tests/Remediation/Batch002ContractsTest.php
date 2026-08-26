<?php

declare(strict_types=1);

/**
 * PRE-AUDIT REMEDIATION BATCH 002 — live-realistic Купи handoff, journal auth, BO rows.
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

function assertRem002(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$module = (string) file_get_contents($root . '/unipayment.php');
$js = (string) file_get_contents($root . '/views/js/checkout-payment.js');
$tpl = (string) file_get_contents($root . '/views/templates/admin/configuration.tpl');
$details = (string) file_get_contents($root . '/src/Order/OrderLeasingDetailsPresenter.php');
$journal = (string) file_get_contents($root . '/src/SmartUcf/SmartUcfDiagnosticJournal.php');
$preselect = (string) file_get_contents($root . '/src/Product/ProductPopupCheckoutPreselectionService.php');
$classicPayment = (string) file_get_contents(
    '/var/www/presta9.avalonbg.com/themes/classic/templates/checkout/_partials/steps/payment.tpl'
);
$hbPayment = (string) file_get_contents(
    '/var/www/presta9.avalonbg.com/themes/hummingbird/templates/checkout/_partials/steps/payment.tpl'
);

final class Rem002Cookie
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

function rem002Cart(float $total, array $checkoutState = []): CartContext
{
    $line = new CartLine(new ProductContext(11, [1], $total), 0, 2, 100.0);

    return new CartContext([$line], $total, $checkoutState);
}

$snapshot = new CartSnapshot();
$store = new CheckoutPreferenceStore();

// 1–2 Preference survives shipping evolution via lines identity (not payable total).
$beforeShip = rem002Cart(100.0, [
    'id_cart' => 55,
    'carrier_id' => 0,
    'delivery_option' => [],
    'shipping_total' => '0.00',
    'cart_rules' => [],
]);
$afterShip = rem002Cart(110.0, [
    'id_cart' => 55,
    'carrier_id' => 3,
    'delivery_option' => ['1,' => '3,'],
    'shipping_total' => '10.00',
    'cart_rules' => [],
]);
$fpBefore = $snapshot->fingerprint($beforeShip, 'BGN');
$fpAfter = $snapshot->fingerprint($afterShip, 'BGN');
$linesBefore = $snapshot->linesFingerprint($beforeShip, 'BGN');
$linesAfter = $snapshot->linesFingerprint($afterShip, 'BGN');
assertRem002($fpBefore !== $fpAfter, '1: full fingerprint must change when shipping/total evolves');
assertRem002($linesBefore === $linesAfter, '1: lines fingerprint must ignore shipping total');

$cookie = new Rem002Cookie();
$store->save($cookie, [
    'scheme_type' => 'standard',
    'kop_code' => 'STD',
    'months' => 12,
    'filter_id' => 0,
    'scheme_key' => 'standard|STD|12|0',
    'first_installment' => 50,
    'cart_fingerprint' => $fpBefore,
    'lines_fingerprint' => $linesBefore,
    'flow' => 'product_preselect',
], 55, 0);
$loaded = $store->load($cookie, 55, 0, $fpAfter, $linesAfter);
assertRem002(is_array($loaded), '2: first checkout render rebinds after shipping evolution');
assertRem002(($loaded['cart_fingerprint'] ?? '') === $fpAfter, '2: rebound full fingerprint');
assertRem002((int) ($loaded['months'] ?? 0) === 12, '2: scheme months survive');
assertRem002((float) ($loaded['first_installment'] ?? 0) === 50.0, '7: first installment preserved');

// Guest customer identity evolution 0 → N
$cookieGuest = new Rem002Cookie();
$store->save($cookieGuest, [
    'scheme_type' => 'standard',
    'kop_code' => 'STD',
    'months' => 10,
    'filter_id' => 0,
    'first_installment' => 0,
    'cart_fingerprint' => $fpAfter,
    'lines_fingerprint' => $linesAfter,
    'flow' => 'product_preselect',
], 55, 0);
$guestLoaded = $store->load($cookieGuest, 55, 99, $fpAfter, $linesAfter);
assertRem002(is_array($guestLoaded), '2: guest customer_id 0 may bind to checkout customer');
assertRem002((int) ($guestLoaded['customer_id'] ?? -1) === 99, '2: customer identity rebound');

// 10 Material drift rejects
$drift = rem002Cart(110.0, $afterShip->checkoutState);
$drift = new CartContext(
    [new CartLine(new ProductContext(99, [1], 110.0), 0, 5, 110.0)],
    110.0,
    $afterShip->checkoutState
);
assertRem002(
    $store->load($cookie, 55, 0, $snapshot->fingerprint($drift, 'BGN'), $snapshot->linesFingerprint($drift, 'BGN')) === null,
    '10: material product/qty drift rejects preference'
);

// 3–5 Theme DOM contracts
assertRem002(
    strpos($classicPayment, 'name="payment-option"') !== false
        && strpos($classicPayment, 'data-module-name="{$option.module_name}"') !== false,
    '3: Classic payment radio uses name=payment-option + data-module-name'
);
assertRem002(
    strpos($hbPayment, 'name="payment-option"') !== false
        && strpos($hbPayment, 'data-module-name="{$option.module_name}"') !== false,
    '4: Hummingbird payment radio uses name=payment-option + data-module-name'
);
assertRem002(
    strpos($js, 'input[name="payment-option"][data-module-name="') !== false,
    '3/4: checkout JS targets theme payment radios'
);
assertRem002(
    strpos($js, 'paymentOption.click()') !== false
        && strpos($js, 'dispatchEvent') !== false,
    '5: selecting UniPayment triggers click/change lifecycle'
);
assertRem002(strpos($js, 'new MutationObserver') === false, '9: no MutationObserver reselect loop');
assertRem002(strpos($js, 'unipaymentCheckoutHandoff') !== false, 'handoff JS def consumed');
assertRem002(strpos($js, 'unipaymentPaymentPreselectAborted') !== false, '8: manual switch aborts');
assertRem002(strpos($js, 'applyHandoffScheme') !== false, '6: exact scheme applied after payment select');
assertRem002(strpos($module, 'Media::addJsDef') !== false, 'Media handoff exposed outside hidden form');
assertRem002(strpos($preselect, 'scheme_key') !== false, 'preference stores scheme_key');

// Journal authorization
assertRem002(strpos($module, 'isAuthorizedJournalDownload') !== false, 'journal auth helper');
assertRem002(strpos($module, 'UserTokenManager') !== false, '1: uses PS9 UserTokenManager');
assertRem002(
    (bool) preg_match('/function isAuthorizedJournalDownload[\s\S]*UserTokenManager[\s\S]*isTokenValid/s', $module),
    'primary journal auth uses UserTokenManager::isTokenValid'
);
assertRem002(strpos($tpl, 'name="_token"') !== false && strpos($tpl, 'name="token"') !== false, 'CSRF token+\_token posted');
assertRem002(strpos($module, 'ob_end_clean') !== false, '8: clears buffers before download headers');
assertRem002(strpos($journal, "'egn'") !== false, '6: journal still redacts EGN');
assertRem002(strpos($module, "'id_shop'") !== false, '5: journal shop scope');

// BO diagnostics removed
assertRem002(strpos($details, 'appendOperationalDiagnostics') === false, 'BO no appendOperationalDiagnostics');
assertRem002(strpos($details, 'Control Panel order ID') === false, 'BO no CP order ID');
assertRem002(strpos($details, 'SmartUCF state') === false, 'BO no SmartUCF state');
assertRem002(strpos($details, "'Процес'") === false && strpos($details, '"Процес"') === false, 'BO no Process row');
assertRem002(strpos($details, 'adminRowsFromSnapshot') !== false, 'BO keeps leasing business rows');
assertRem002(strpos($details, 'applyBankStatusLabel') !== false, 'BO keeps bank status');

fwrite(STDOUT, "OK (PRE-AUDIT REMEDIATION BATCH 002 contracts)\n");
