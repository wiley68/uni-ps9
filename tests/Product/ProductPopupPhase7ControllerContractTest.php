<?php

declare(strict_types=1);

/**
 * Product popup controller: full durable order path (remediation batch 001).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertPopupCtrl(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$popup = (string) file_get_contents($root . '/controllers/front/productpopup.php');
$js = (string) file_get_contents($root . '/views/js/product-calculator.js');
$tpl = (string) file_get_contents($root . '/views/templates/hook/product_calculator.tpl');

assertPopupCtrl(strpos($popup, 'hash_equals(Tools::getToken(false)') !== false, 'CSRF required');
assertPopupCtrl(strpos($popup, 'ProductPopupCalculator') !== false, 'authoritative calculate remains');
assertPopupCtrl(strpos($popup, "issue_submission_token") !== false, 'issue_submission_token action');
assertPopupCtrl(strpos($popup, "validate_step2") !== false, 'validate_step2 action');
assertPopupCtrl(strpos($popup, "preselect") !== false, 'preselect action');
assertPopupCtrl(strpos($popup, "apply") !== false, 'apply action');
assertPopupCtrl(strpos($popup, 'error(501') === false, 'must not 501 implemented actions');
assertPopupCtrl(strpos($popup, 'Невалидно действие') !== false, 'unknown action is controlled 400');
assertPopupCtrl(strpos($popup, 'unset($customer[\'egn\'])') !== false, 'EGN stripped from JSON');
assertPopupCtrl(strpos($popup, 'OrderOrchestrator') !== false, 'order orchestration enabled');
assertPopupCtrl(strpos($popup, 'ControlPanelOrder') !== false, 'CP order creation enabled');
assertPopupCtrl(strpos($popup, 'SmartUcfSessionCoordinator') !== false, 'SmartUCF outbound enabled');
assertPopupCtrl(strpos($popup, 'FinancingSnapshot') !== false, 'financing snapshot enabled');
assertPopupCtrl(strpos($popup, 'PostControlPanelLifecycleService') !== false, 'post-CP lifecycle enabled');
assertPopupCtrl(strpos($popup, 'markOrderCreated') !== false, 'apply terminates at order_created');
assertPopupCtrl(strpos($popup, 'markIdentityAccepted') === false, 'apply must not stop at identity_accepted');
assertPopupCtrl(strpos($js, 'body.redirect_url') !== false, 'JS follows trusted/confirmation redirect');
assertPopupCtrl(strpos($js, 'showOrderConfirmation') !== false, 'JS can show order confirmation');
assertPopupCtrl(strpos($js, 'createPreselectOperationToken') !== false, 'client preselect token remains');
assertPopupCtrl(
    strpos($js, 'action === "preselect"') !== false,
    'client operation token is only attached to preselect'
);
assertPopupCtrl(strpos($tpl, 'data-unipayment-submit') !== false, 'template exposes submit');

fwrite(STDOUT, "OK (productpopup full-order controller contract)\n");
