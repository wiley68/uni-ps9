<?php

declare(strict_types=1);

/**
 * Phase 7 productpopup controller actions and deferred order boundary.
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
assertPopupCtrl(strpos($popup, 'error(501') === false, 'Phase 7 must not 501 implemented actions');
assertPopupCtrl(strpos($popup, 'Невалидно действие') !== false, 'unknown action is controlled 400');
assertPopupCtrl(strpos($popup, 'unset($customer[\'egn\'])') !== false, 'EGN stripped from JSON');
assertPopupCtrl(strpos($popup, 'OrderOrchestrator') === false, 'no order orchestration');
assertPopupCtrl(strpos($popup, 'ControlPanelOrder') === false, 'no CP order creation');
assertPopupCtrl(strpos($popup, 'SmartUcfSessionCoordinator') === false, 'no SmartUCF outbound');
assertPopupCtrl(strpos($popup, 'FinancingSnapshot') === false, 'no financing snapshot');
assertPopupCtrl(strpos($popup, 'LeasingMail') === false && strpos($popup, 'sendMail') === false, 'no emails');
assertPopupCtrl(strpos($popup, 'markIdentityAccepted') !== false, 'apply terminates at identity_accepted');
assertPopupCtrl(strpos($js, 'showIdentityAccepted') !== false, 'JS renders identity Step 3 without fabricating an order');
assertPopupCtrl(strpos($js, 'data-identity-accepted-title') !== false, 'JS uses identity accepted copy');
assertPopupCtrl(strpos($tpl, 'data-identity-accepted-title') !== false, 'template exposes identity accepted copy');
assertPopupCtrl(strpos($js, 'createPreselectOperationToken') !== false, 'client preselect token remains');
assertPopupCtrl(
    strpos($js, 'action === "preselect"') !== false,
    'client operation token is only attached to preselect'
);

fwrite(STDOUT, "OK (Phase 7 productpopup controller contract)\n");
