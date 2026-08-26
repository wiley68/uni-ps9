<?php

declare(strict_types=1);

/**
 * Cartpopup controller contract: Phase 7 reuse, no order creation.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertCartPopupCtrl(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$ctrl = (string) file_get_contents($root . '/controllers/front/cartpopup.php');
$calc = (string) file_get_contents($root . '/controllers/front/cartcalculator.php');

assertCartPopupCtrl(strpos($ctrl, "popup_action', 'calculate'") !== false, 'default action calculate');
assertCartPopupCtrl(strpos($ctrl, 'issue_submission_token') !== false, 'issue token action');
assertCartPopupCtrl(strpos($ctrl, 'validate_step2') !== false, 'validate_step2 action');
assertCartPopupCtrl(strpos($ctrl, "'apply'") !== false, 'apply action');
assertCartPopupCtrl(strpos($ctrl, 'preselect') === false, 'cart must not re-add products via preselect');
assertCartPopupCtrl(strpos($ctrl, 'CartContextFactory') !== false, 'authoritative cart context');
assertCartPopupCtrl(strpos($ctrl, 'getOrderTotal') === false, 'controller must not trust POST totals; factory owns payable total');
assertCartPopupCtrl(strpos($ctrl, 'CartPopupApplyService') !== false, 'cart apply uses CartPopupApplyService');
assertCartPopupCtrl(strpos($ctrl, 'ProductPopupCustomerValidator') !== false, 'reuse customer validator');
assertCartPopupCtrl(strpos($ctrl, 'OrderOrchestrator') !== false, 'cart apply creates durable orders');
assertCartPopupCtrl(
    strpos($ctrl, 'PopupSubmissionPostOrderBinder') !== false
        || strpos($ctrl, 'markOrderCreated') !== false,
    'cart apply marks order_created'
);
assertCartPopupCtrl(strpos($calc, 'CartContextFactory') !== false, 'cartcalculator uses factory');
assertCartPopupCtrl(strpos($calc, "'calculator' => null") !== false, 'config/unavailable returns null calculator');

fwrite(STDOUT, "OK (cartpopup/cartcalculator controller contract)\n");
