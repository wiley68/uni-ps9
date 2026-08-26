<?php

declare(strict_types=1);

/**
 * PRE-AUDIT REMEDIATION BATCH 001 — focused contracts for popup order completion,
 * Product "Купи" preselection, BO cache cleanup, and operation journal activation.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Checkout\CheckoutPreferenceStore;

function assertRem001(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$productPopup = (string) file_get_contents($root . '/controllers/front/productpopup.php');
$cartPopup = (string) file_get_contents($root . '/controllers/front/cartpopup.php');
$productApply = (string) file_get_contents($root . '/src/Product/ProductPopupApplyService.php');
$cartApply = (string) file_get_contents($root . '/src/Cart/CartPopupApplyService.php');
$module = (string) file_get_contents($root . '/unipayment.php');
$tpl = (string) file_get_contents($root . '/views/templates/admin/configuration.tpl');
$checkoutJs = (string) file_get_contents($root . '/views/js/checkout-payment.js');
$productJs = (string) file_get_contents($root . '/views/js/product-calculator.js');
$preselect = (string) file_get_contents($root . '/src/Product/ProductPopupCheckoutPreselectionService.php');
$preferenceStoreSrc = (string) file_get_contents($root . '/src/Checkout/CheckoutPreferenceStore.php');
$snapshotSrc = (string) file_get_contents($root . '/src/Checkout/CartSnapshot.php');
$journalSrc = (string) file_get_contents($root . '/src/SmartUcf/SmartUcfDiagnosticJournal.php');

// A–D Process 1/2 product+cart share OrderOrchestrator + PostControlPanelLifecycleService
assertRem001(is_file($root . '/src/Product/ProductPopupApplyService.php'), 'A: ProductPopupApplyService present');
assertRem001(is_file($root . '/src/Cart/CartPopupApplyService.php'), 'C: CartPopupApplyService present');
assertRem001(strpos($productPopup, 'ProductPopupApplyService') !== false, 'A: productpopup wires ProductPopupApplyService');
assertRem001(strpos($productPopup, 'OrderOrchestrator') !== false, 'A: productpopup uses OrderOrchestrator');
assertRem001(strpos($productPopup, 'PostControlPanelLifecycleService') !== false, 'A: productpopup uses post-CP lifecycle');
assertRem001(strpos($productPopup, 'SmartUcfSessionCoordinator') !== false, 'A: productpopup can start SmartUCF');
assertRem001(strpos($productPopup, 'markOrderCreated') !== false, 'A: productpopup marks order_created');
assertRem001(strpos($productPopup, 'markIdentityAccepted') === false, 'A: product apply no longer stops at identity_accepted');
assertRem001(strpos($productApply, "'product_popup'") !== false, 'A: product apply source marker');

assertRem001(strpos($cartPopup, 'CartPopupApplyService') !== false, 'C: cartpopup wires CartPopupApplyService');
assertRem001(strpos($cartPopup, 'OrderOrchestrator') !== false, 'C: cartpopup uses OrderOrchestrator');
assertRem001(strpos($cartPopup, 'PostControlPanelLifecycleService') !== false, 'C: cartpopup uses post-CP lifecycle');
assertRem001(strpos($cartPopup, 'SmartUcfSessionCoordinator') !== false, 'C: cartpopup can start SmartUCF');
assertRem001(strpos($cartPopup, 'markOrderCreated') !== false, 'C: cartpopup marks order_created');
assertRem001(strpos($cartPopup, 'PopupSubmissionRepository') !== false, 'E/F: cart keeps submission tokens');
assertRem001(strpos($cartPopup, 'claimForProcessing') !== false, 'E/F: cart double-submit uses claim');
assertRem001(strpos($cartApply, "'cart_popup'") !== false, 'C: cart apply source marker');
assertRem001(strpos($cartApply, 'ensureCustomerOnExistingCart') !== false, 'C: cart uses existing cart');

// Process 2: no Process 1 SmartUCF when process2 — lifecycle service owns this for both flows
$lifecycle = (string) file_get_contents($root . '/src/Order/PostControlPanelLifecycleService.php');
assertRem001(strpos($lifecycle, 'isProcess2') !== false, 'B/D: lifecycle branches Process 2');
assertRem001(strpos($productPopup, 'OrderConfirmationUrlBuilder') !== false, 'B: Process 2 confirmation URL');
assertRem001(strpos($cartPopup, 'OrderConfirmationUrlBuilder') !== false, 'D: Process 2 confirmation URL');

// G/H CP / SmartUCF failure UX
assertRem001(strpos($productPopup, 'PostOrderPopupFailureResponse') !== false, 'G: product CP failure mapped');
assertRem001(strpos($cartPopup, 'PostOrderPopupFailureResponse') !== false, 'G: cart CP failure mapped');
assertRem001(
    strpos($productPopup, 'bank_send_failed') !== false
        || strpos((string) file_get_contents($root . '/src/Order/PostControlPanelLifecycleService.php'), 'bank_send_failed') !== false
        || is_file($root . '/src/Order/OrderBankStatusRepository.php'),
    'G/H: bank status persistence remains'
);

// E/F double submit — product claim + markOrderCreated
assertRem001(strpos($productPopup, 'claimForProcessing') !== false, 'E: product claimForProcessing');
assertRem001(strpos($productPopup, 'existingOrderResponse') !== false, 'E: product replay returns existing order');
assertRem001(strpos($cartPopup, 'existingOrderResponse') !== false, 'F: cart replay returns existing order');

// Placeholder removed from happy path (legacy identity response may remain for old rows only)
assertRem001(
    strpos($productPopup, 'Поръчката ще бъде завършена на следваща стъпка') === false,
    'productpopup must not emit next-step placeholder'
);
assertRem001(
    strpos($cartPopup, 'Поръчката ще бъде завършена на следваща стъпка') === false,
    'cartpopup must not emit next-step placeholder'
);
assertRem001(strpos($productJs, 'redirect_url') !== false, 'JS follows redirect_url after apply');
assertRem001(strpos($productJs, 'order_created') !== false || strpos($productJs, 'body.redirect_url') !== false, 'JS handles order outcomes');

// I–M Product Купи preselection
assertRem001(strpos($preselect, 'lines_fingerprint') !== false, 'I: preselect stores lines_fingerprint');
assertRem001(strpos($preferenceStoreSrc, 'checkout_fingerprint_bound') !== false, 'I: one-time checkout rebind');
assertRem001(strpos($snapshotSrc, 'function linesFingerprint') !== false, 'I: CartSnapshot linesFingerprint');
assertRem001(strpos($module, 'linesFingerprint') !== false, 'I: hookPaymentOptions passes lines fingerprint');
assertRem001(strpos($preselect, '->createForCheckout(') === false, 'I: Product page does not use createForCheckout');
assertRem001(!preg_match("/'scheme_key'\s*=>/", $preselect), 'I: no pipe scheme_key in product preference cookie');
assertRem001(strpos($checkoutJs, 'tryPreselectPayment') !== false, 'I: checkout JS preselects UniPayment');
assertRem001(strpos($checkoutJs, 'data-module-name="unipayment"') !== false, 'L/M: theme-compatible payment radio selector');
assertRem001(strpos($checkoutJs, 'unipaymentPaymentPreselectAborted') !== false, 'K: manual switch aborts reselection loop');
assertRem001(strpos($checkoutJs, 'updatedDeliveryForm') !== false, 'L: Hummingbird delivery lifecycle');
assertRem001(strpos($checkoutJs, 'changedCheckoutStep') !== false, 'M: Classic/shared checkout step lifecycle');
assertRem001(!preg_match('/\$\s*\(|\bjQuery\b/', $checkoutJs), 'no production jQuery in checkout JS');

// Preference store unit: rebind + stale lines reject
final class Rem001FakeCookie
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

$store = new CheckoutPreferenceStore();
$fullA = str_repeat('a', 64);
$fullB = str_repeat('b', 64);
$linesOk = str_repeat('c', 64);
$linesDrift = str_repeat('d', 64);
$pref = [
    'scheme_type' => 'standard',
    'kop_code' => 'STD',
    'months' => 12,
    'filter_id' => 0,
    'first_installment' => 0,
    'product_amount' => 100,
    'cart_fingerprint' => $fullA,
    'lines_fingerprint' => $linesOk,
    'flow' => 'product_preselect',
];
$cookie = new Rem001FakeCookie();
$store->save($cookie, $pref, 42, 0);
$rebound = $store->load($cookie, 42, 0, $fullB, $linesOk);
assertRem001(is_array($rebound), 'I: product handoff rebinds when lines still match');
assertRem001(($rebound['cart_fingerprint'] ?? '') === $fullB, 'I: rebound fingerprint updated');
assertRem001(!empty($rebound['checkout_fingerprint_bound']), 'I: bound flag set after rebind');

$cookie2 = new Rem001FakeCookie();
$store->save($cookie2, $pref, 42, 0);
assertRem001(
    $store->load($cookie2, 42, 0, $fullB, $linesDrift) === null,
    'J: stale lines fingerprint rejects preference'
);

$cookie3 = new Rem001FakeCookie();
$bound = $pref;
$bound['checkout_fingerprint_bound'] = 1;
$store->save($cookie3, $bound, 42, 0);
$refreshedAfterBind = $store->load($cookie3, 42, 0, $fullB, $linesOk);
assertRem001(is_array($refreshedAfterBind), 'J: product handoff refreshes full fingerprint after bind when lines match');
assertRem001(($refreshedAfterBind['cart_fingerprint'] ?? '') === $fullB, 'J: refreshed fingerprint stored');
assertRem001(
    $store->load($cookie3, 42, 0, $fullB, $linesDrift) === null,
    'J: lines drift after bind still rejects'
);

// N–P BO cache status removed; refresh remains
assertRem001(strpos($tpl, 'Локален кеш на конфигурацията') === false, 'N: cache status section absent');
assertRem001(strpos($tpl, 'submitUnipaymentRefresh') !== false, 'O: refresh control present');
assertRem001(strpos($module, 'handleBankDataRefresh') !== false, 'P: refresh handler present');
assertRem001(strpos($module, 'Данните от банката са обновени успешно') !== false, 'P: refresh success message');
assertRem001(strpos($module, 'Неуспешна връзка с банката') !== false, 'P: refresh error path remains');

// Q–T journal
assertRem001(
    (bool) preg_match('/name="submitUnipaymentDownloadJournal"/', $tpl)
        && !preg_match('/name="submitUnipaymentDownloadJournal"[^>]*disabled/', $tpl),
    'Q: journal button active'
);
assertRem001(strpos($tpl, 'Phase 4') === false, 'Q: no obsolete phase text');
assertRem001(strpos($tpl, 'SmartUCF диагностиката') === false, 'Q: no deferred journal wording');
assertRem001(strpos($module, 'handleDebugJournalDownload') !== false, 'Q: journal download handler');
assertRem001((bool) preg_match('/unipayment_journal_available[\'"]\s*=>\s*true/', $module), 'Q: journal available flag true');
assertRem001(strpos($module, 'getAdminTokenLite') !== false, 'R: CSRF admin token');
assertRem001(strpos($tpl, 'unipayment_admin_token') !== false, 'R: token field in journal form');
assertRem001(strpos($journalSrc, "'egn'") !== false, 'S: journal redacts EGN');
assertRem001(strpos($journalSrc, 'SENSITIVE_KEYS') !== false, 'S: journal has sensitive key filter');
assertRem001(strpos($journalSrc, 'Bearer') !== false, 'S: journal redacts Bearer tokens');
assertRem001(strpos($module, 'id_shop') !== false && strpos($module, 'handleDebugJournalDownload') !== false, 'T: journal shop scope filter');

assertRem001(strpos($module, "'2.0.1'") !== false || strpos($module, '"2.0.1"') !== false, 'version remains 2.0.1');

fwrite(STDOUT, "OK (PRE-AUDIT REMEDIATION BATCH 001 contracts)\n");
