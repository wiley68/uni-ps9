<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertCheckoutCalcCtrl(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$ctrl = (string) file_get_contents($root . '/controllers/front/checkoutcalculate.php');

assertCheckoutCalcCtrl(strpos($ctrl, 'createForCheckout') !== false, 'authoritative checkout cart context');
assertCheckoutCalcCtrl(strpos($ctrl, 'CheckoutPaymentCalculator') !== false, 'server calculator');
assertCheckoutCalcCtrl(strpos($ctrl, 'CartSnapshotSigner') !== false, 'fingerprint signing');
assertCheckoutCalcCtrl(strpos($ctrl, 'cart_snapshot') !== false, 'stale snapshot rejection / refresh');
assertCheckoutCalcCtrl(strpos($ctrl, 'CheckoutPreferenceStore') !== false, 'updates preference on successful calculate');
assertCheckoutCalcCtrl(strpos($ctrl, 'monthly_installment') === false || strpos($ctrl, "Tools::getValue('monthly") === false, 'must not trust posted monthly installment');
assertCheckoutCalcCtrl(strpos($ctrl, 'OrderOrchestrator') === false, 'must not create orders');

fwrite(STDOUT, "OK (Phase 9 checkoutcalculate controller contract)\n");
