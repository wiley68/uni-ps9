<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertPaymentOption(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$module = (string) file_get_contents($root . '/unipayment.php');
$tpl = (string) file_get_contents($root . '/views/templates/hook/checkout_payment.tpl');
$calc = (string) file_get_contents($root . '/controllers/front/checkoutcalculate.php');
$validate = (string) file_get_contents($root . '/controllers/front/validatecheckout.php');

assertPaymentOption((bool) preg_match('/registerHook\s*\(\s*[\'"]paymentOptions[\'"]\s*\)/', $module), 'paymentOptions hook registered');
assertPaymentOption((bool) preg_match('/function\s+hookPaymentOptions\b/', $module), 'hookPaymentOptions handler');
assertPaymentOption(strpos($module, 'PaymentOption') !== false, 'uses Core PaymentOption');
assertPaymentOption(strpos($module, 'Купи на изплащане с УниКредит') !== false, 'call-to-action text');
assertPaymentOption(strpos($module, 'validatecheckout') !== false, 'action route validatecheckout');
assertPaymentOption(strpos($module, 'checkout_payment.tpl') !== false, 'additionalInformation form template');
assertPaymentOption(strpos($module, 'createForCheckout') !== false, 'uses checkout cart context');
assertPaymentOption(strpos($module, 'CheckoutPreferenceStore') !== false, 'loads checkout preference');
assertPaymentOption(strpos($module, 'cart_fingerprint') !== false || strpos($module, 'fingerprint') !== false, 'preference validated against fingerprint');
assertPaymentOption(strpos($module, "php_self === 'order'") !== false, 'checkout assets gated to order page');
assertPaymentOption(strpos($tpl, 'data-unipayment-checkout') !== false, 'checkout root selector');
assertPaymentOption(strpos($tpl, 'unipayment_cart_snapshot') !== false, 'signed cart snapshot field');
assertPaymentOption(strpos($calc, 'CheckoutPaymentCalculator') !== false, 'checkoutcalculate uses calculator');
assertPaymentOption(strpos($calc, 'cart_snapshot') !== false, 'checkoutcalculate validates/returns snapshot');
assertPaymentOption(strpos($validate, 'OrderOrchestrator') !== false, 'Phase 10 orchestrates durable checkout submission');
assertPaymentOption(strpos($validate, 'CheckoutSubmitLock') !== false, 'Phase 10 acquires checkout lock');
assertPaymentOption(strpos($validate, 'Phase10CheckoutOutcome') !== false, 'Phase 10 normalized outcomes');
assertPaymentOption(strpos($validate, 'SmartUcfSessionCoordinator') === false, 'Phase 10 must not run SmartUCF');
assertPaymentOption(strpos($validate, 'PostControlPanelLifecycleService') === false, 'Phase 10 must not run post-CP lifecycle');
assertPaymentOption(strpos($validate, 'CheckoutPaymentValidator') !== false, 'Phase 10 revalidates before order creation');
assertPaymentOption(strpos($validate, 'създаването на поръчка все още не е активирано') === false, 'Phase 9 boundary stop removed');

fwrite(STDOUT, "OK (Phase 10 PaymentOption + durable checkout contract)\n");
