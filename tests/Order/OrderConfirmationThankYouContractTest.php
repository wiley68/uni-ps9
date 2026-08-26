<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertThankYou(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$popup = (string) file_get_contents($root . '/controllers/front/productpopup.php');
$checkout = (string) file_get_contents($root . '/controllers/front/validatecheckout.php');
$module = (string) file_get_contents($root . '/unipayment.php');
$javascript = (string) file_get_contents($root . '/views/js/product-calculator.js');
$template = (string) file_get_contents($root . '/views/templates/hook/order_confirmation_leasing.tpl');
$css = (string) file_get_contents($root . '/views/css/order-confirmation.css');
$urlBuilder = (string) file_get_contents($root . '/src/Order/OrderConfirmationUrlBuilder.php');
$presenter = (string) file_get_contents($root . '/src/Order/OrderLeasingDetailsPresenter.php');

assertThankYou(strpos($urlBuilder, "'order-confirmation'") !== false, 'thank-you URL must use native order-confirmation');
assertThankYou(strpos($urlBuilder, "'id_module'") !== false && strpos($urlBuilder, "'key'") !== false, 'confirmation URL must include module id and order key');
assertThankYou(strpos($popup, 'PostControlPanelLifecycleService') === false, 'product popup stays pre-order identity (no post-order thank-you orchestration)');
assertThankYou(strpos($checkout, 'OrderConfirmationUrlBuilder') !== false, 'checkout Process 2 must redirect to thank-you');
assertThankYou(strpos($javascript, 'body.redirect_url') !== false && strpos($javascript, 'window.location.assign(body.redirect_url)') !== false, 'popup JS must follow server thank-you URL when provided');
assertThankYou(strpos($module, 'hookDisplayPaymentReturn') !== false, 'native payment return hook missing');
assertThankYou(strpos($module, "'displayPaymentReturn'") !== false, 'displayPaymentReturn must be registered');
assertThankYou(strpos($module, 'php_self === \'order-confirmation\'') !== false, 'thank-you CSS must load on order-confirmation');
assertThankYou(strpos($template, "s='УниКредит лизинг'") !== false, 'thank-you heading must match Woo UniCredit leasing');
assertThankYou(strpos($template, 'unipayment_leasing_rows') !== false, 'thank-you must render leasing rows');
assertThankYou(strpos($css, 'unipayment-thankyou-credit-details') !== false, 'thank-you CSS class missing');
assertThankYou(strpos($presenter, 'SENT_PROCESS2') !== false, 'thank-you must be limited to Process 2 orders');
assertThankYou(strpos($presenter, 'thankYouRows') !== false, 'Process 2 thank-you presenter method missing');
assertThankYou(strpos($presenter, 'customerRowsFromSnapshot') !== false, 'thank-you must use customer audience (no EGN)');

fwrite(STDOUT, "OK (Process 2 thank-you / order-confirmation parity)\n");
