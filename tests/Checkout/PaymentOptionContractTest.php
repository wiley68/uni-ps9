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
assertPaymentOption(strpos($validate, 'CheckoutSubmitLock') !== false, 'acquires checkout lock');
assertPaymentOption(strpos($validate, 'PostControlPanelLifecycleService') !== false, 'Phase 11 post-CP lifecycle');
assertPaymentOption(strpos($validate, 'SmartUcfSessionCoordinator') !== false, 'Phase 11 SmartUCF coordinator');
assertPaymentOption(strpos($validate, 'CheckoutPaymentValidator') !== false, 'revalidates before order creation');

foreach (['unipayment_egn', 'unipayment_phone2'] as $legacyField) {
    assertPaymentOption(substr_count($tpl, 'name="' . $legacyField . '"') === 1, 'one legacy input per field');
    assertPaymentOption(str_contains($tpl, '<input type="hidden" name="' . $legacyField . '" value="">'), 'legacy input remains empty and hidden');
    assertPaymentOption(!str_contains($validate, $legacyField), 'obsolete POST key is never read');
}
assertPaymentOption((bool) preg_match('/\{if !\$unipayment_checkout\.process2\}\s*<input[^>]+unipayment_egn[^>]+>\s*<input[^>]+unipayment_phone2[^>]+>\s*\{\/if\}/', $tpl), 'both legacy inputs are confined to Process 1');

class ModuleFrontController {}

final class Tools
{
    public static array $posted = [];

    public static function getValue(string $key, mixed $default = false): mixed
    {
        return self::$posted[$key] ?? $default;
    }
}

require $root . '/controllers/front/validatecheckout.php';
$controller = new UnipaymentValidateCheckoutModuleFrontController();
$postedSelection = new ReflectionMethod($controller, 'postedSelection');
$expected = [
    'scheme_key' => '12:0',
    'kop_code' => 'STD',
    'first_installment' => '100',
    'cart_snapshot' => 'synthetic-signature',
    'consent' => [7],
];
Tools::$posted = [
    'unipayment_scheme_key' => '12:0',
    'unipayment_kop_code' => 'STD',
    'unipayment_first_installment' => '100',
    'unipayment_cart_snapshot' => 'synthetic-signature',
    'unipayment_consent' => [7],
];
assertPaymentOption($postedSelection->invoke($controller) === $expected, 'checkout maps all supported POST fields');
foreach (['legacy-marker', ['legacy-marker']] as $legacyValue) {
    Tools::$posted['unipayment_egn'] = $legacyValue;
    Tools::$posted['unipayment_phone2'] = $legacyValue;
    assertPaymentOption($postedSelection->invoke($controller) === $expected, 'scalar and array legacy POST keys are ignored');
}

fwrite(STDOUT, "OK (Phase 11 PaymentOption + post-CP lifecycle contract)\n");
