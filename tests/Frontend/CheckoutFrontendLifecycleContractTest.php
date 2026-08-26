<?php

declare(strict_types=1);

/**
 * Checkout FO lifecycle / no-jQuery / race-protection contract.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertCheckoutUi(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$js = (string) file_get_contents($root . '/views/js/checkout-payment.js');
$css = (string) file_get_contents($root . '/views/css/checkout-payment.css');
$module = (string) file_get_contents($root . '/unipayment.php');

assertCheckoutUi(!preg_match('/\$\s*\(|\bjQuery\b/', $js), 'checkout JS must not depend on jQuery');
assertCheckoutUi(strpos($js, 'AbortController') !== false, 'AbortController for calculate');
assertCheckoutUi(strpos($js, 'calculateSequence') !== false, 'sequence guard for stale calculate');
assertCheckoutUi(strpos($js, 'isConnected') !== false, 'connected root check');
assertCheckoutUi(strpos($js, 'updatedDeliveryForm') !== false, 'Hummingbird delivery update reinit');
assertCheckoutUi(strpos($js, 'changedCheckoutStep') !== false, 'checkout step change reinit');
assertCheckoutUi(strpos($js, 'updatedCart') !== false, 'cart/voucher update reinit');
assertCheckoutUi(strpos($js, 'dataset.unipaymentReady') !== false, 'idempotent setup');
assertCheckoutUi(strpos($js, 'tryPreselectPayment') !== false, 'Product Купи payment preselect helper');
assertCheckoutUi(strpos($js, 'unipaymentCheckoutHandoff') !== false, 'Media handoff consumed by checkout JS');
assertCheckoutUi(strpos($js, 'unipaymentPaymentPreselectAborted') !== false, 'manual payment switch must abort reselection');
assertCheckoutUi(strpos($js, 'new MutationObserver') === false, 'no MutationObserver payment loops');
assertCheckoutUi(strpos($js, 'submitState') !== false, 'checkout submit state machine');
assertCheckoutUi(strpos($js, 'click_accepted') !== false, 'first-click accepted before form submit');
assertCheckoutUi(strpos($js, 'acceptFirstClick') !== false, 'immediate confirmation click guard');
$acceptBody = '';
if (preg_match('/function acceptFirstClick\(\)\s*\{([\s\S]*?)\n        \}\n\n        if \(select\)/', $js, $acceptMatch)) {
    $acceptBody = $acceptMatch[1];
}
assertCheckoutUi($acceptBody !== '' && strpos($acceptBody, '.disabled') === false, 'acceptFirstClick must not disable submitter before native submit');
assertCheckoutUi(
    (bool) preg_match(
        '/function markSubmitting\(\)\s*\{[\s\S]*?\.disabled\s*=\s*true/',
        $js
    ),
    'confirmation button disabled only on submit'
);
assertCheckoutUi(
    strpos($js, 'data-module-name') !== false && strpos($js, 'unipayment') !== false,
    'only validate when UniPayment selected'
);
assertCheckoutUi(strpos($css, '.unipayment-checkout') !== false, 'namespaced CSS');
assertCheckoutUi(strpos($module, 'checkout-payment.js') !== false, 'checkout assets registered');

$eventsMap = '/var/www/presta9.avalonbg.com/themes/hummingbird/src/js/constants/events-map.ts';
if (is_file($eventsMap)) {
    $map = (string) file_get_contents($eventsMap);
    assertCheckoutUi(strpos($map, "updatedDeliveryForm: 'updatedDeliveryForm'") !== false, 'Hummingbird exposes updatedDeliveryForm');
}

fwrite(STDOUT, "OK (Phase 9 checkout UI lifecycle)\n");
