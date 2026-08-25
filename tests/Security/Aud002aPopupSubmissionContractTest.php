<?php

declare(strict_types=1);

/**
 * AUD-002A static contracts for Phase 7 popup submission identity.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertAud002aContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$controller = (string) file_get_contents($root . '/controllers/front/productpopup.php');
$js = (string) file_get_contents($root . '/views/js/product-calculator.js');
$module = (string) file_get_contents($root . '/unipayment.php');
$guest = (string) file_get_contents($root . '/src/Product/GuestCustomerFactory.php');
$repo = (string) file_get_contents($root . '/src/Product/PopupSubmissionRepository.php');
$guard = (string) file_get_contents($root . '/src/Product/ProductPopupOperationGuard.php');
$hash = (string) file_get_contents($root . '/src/Product/PopupSubmissionSelectionHash.php');

assertAud002aContract(is_file($root . '/src/Product/PopupSubmissionRepository.php'), 'popup submission repository missing');
assertAud002aContract(strpos($module, 'PopupSubmissionRepository') !== false, 'module install must create popup submission table');
assertAud002aContract(strpos($repo, 'unipayment_popup_submission') !== false, 'table name must match approved schema');
assertAud002aContract(strpos($repo, 'uniq_popup_submission_token') !== false, 'token unique index required');
assertAud002aContract(strpos($repo, 'claimForProcessing') !== false, 'atomic claim required');
assertAud002aContract(strpos($repo, 'attachCart') !== false, 'id_cart must be attachable before later orchestrator');
assertAud002aContract(strpos($repo, 'ORDER_CREATED_TTL_SECONDS = 2592000') !== false, 'completed mapping must retain long TTL');
assertAud002aContract(strpos($repo, 'ISSUED_TTL_SECONDS = 1800') !== false, 'issued TTL 1800s');
assertAud002aContract(strpos($repo, 'bin2hex(random_bytes(32))') !== false, 'cryptographic token entropy');
assertAud002aContract(
    strpos($repo, 'identityMatches($existing, $idGuest, $idCustomer)') !== false,
    'preferredToken reuse must verify guest/customer identity'
);
assertAud002aContract(
    strpos($repo, 'public static function identityMatches') !== false,
    'shared identityMatches helper required'
);
assertAud002aContract(
    strpos($guard, 'PopupSubmissionRepository::identityMatches') !== false,
    'operation guard must use the same identity helper'
);
assertAud002aContract(
    strpos($repo, 'function claimForProcessing') !== false
        && strpos($repo, 'UPDATE `%s%s` SET `state`') !== false
        && strpos($repo, 'AND `state` =') !== false
        && strpos($repo, 'Affected_Rows()') !== false
        && strpos($repo, 'PopupSubmissionStates::ISSUED') !== false
        && strpos($repo, 'PopupSubmissionStates::PROCESSING') !== false,
    'claim must be a single UPDATE WHERE state=issued'
);
assertAud002aContract(strpos($repo, 'SELECT') === false || strpos($repo, 'claimForProcessing') !== false, 'claim method present');
$claimStart = strpos($repo, 'public function claimForProcessing');
$attachStart = strpos($repo, 'public function attachCart');
assertAud002aContract($claimStart !== false && $attachStart !== false && $attachStart > $claimStart, 'claim method precedes attachCart');
$claimBody = substr($repo, $claimStart, $attachStart - $claimStart);
assertAud002aContract(
    strpos($claimBody, 'getRow') === false
        && strpos($claimBody, '->update(') === false
        && strpos($claimBody, 'execute($sql)') !== false
        && strpos($claimBody, 'Affected_Rows()') !== false,
    'claim must not read-then-write'
);

assertAud002aContract(strpos($controller, 'issue_submission_token') !== false, 'controller must issue submission tokens');
assertAud002aContract(strpos($controller, 'ProductPopupOperationGuard') !== false, 'apply must gate on operation guard');
assertAud002aContract(strpos($controller, 'markIdentityAccepted') !== false, 'Phase 7 apply accepts identity without order');
assertAud002aContract(strpos($controller, 'OrderOrchestrator') === false, 'Phase 7 must not create orders');
assertAud002aContract(strpos($controller, 'SmartUcfSessionCoordinator') === false, 'Phase 7 must not run SmartUCF');
assertAud002aContract(strpos($controller, 'revertProcessingWithoutCart') !== false, 'validation failure without cart must revert');
assertAud002aContract(strpos($controller, 'selection_changed') !== false || strpos($guard, 'selection_changed') !== false, 'changed binding must be rejected');

assertAud002aContract(strpos($js, 'issue_submission_token') !== false, 'JS must request submission token');
assertAud002aContract(strpos($js, 'popup_submission_token') !== false, 'JS must send submission token on apply');
assertAud002aContract(strpos($js, 'body.step === "processing"') !== false, 'JS must handle processing without generic error');
assertAud002aContract(strpos($js, 'body.step === "identity_accepted"') !== false, 'JS must handle Phase 7 identity accepted');
assertAud002aContract(strpos($js, 'isCartSource') !== false && strpos($js, 'issue_submission_token') !== false, 'shared popup JS supports cart source and submission tokens');
assertAud002aContract(strpos($js, 'preselectOperationToken = ""') !== false, 'stale popup must clear preselect token');

assertAud002aContract(strpos($guest, 'customerExists') === false, 'AUD-001 must remain: no email customer lookup');
assertAud002aContract(strpos($guest, 'createGuestCustomer') !== false, 'AUD-001 fresh guest path remains');
assertAud002aContract((bool) preg_match('/json_encode\(\$canonical/', $hash), 'selection_hash must use structured JSON canonicalization');
assertAud002aContract(strpos($hash, 'FLOW_CART_POPUP') !== false, 'selection hash must isolate cart_popup flow');

assertAud002aContract(strpos($module, 'unipayment_checkout_lock') === false, 'no checkout lock table');
assertAud002aContract(strpos($module, 'unipayment_order_attempt') === false, 'no order attempt table');
assertAud002aContract(strpos($module, 'unipayment_financing_snapshot') === false, 'no financing snapshot table');

fwrite(STDOUT, "OK (AUD-002A Phase 7/8 static contract)\n");
