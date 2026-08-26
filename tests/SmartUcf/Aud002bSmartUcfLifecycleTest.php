<?php

declare(strict_types=1);

/**
 * AUD-002B / AUD-008 — SmartUCF lifecycle classification + coordinator contracts.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCoordinationResult;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfFailureClassification;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfFailureClassifier;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfLifecycleStates;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfSessionException;

function assertAud002b(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$classifier = new SmartUcfFailureClassifier();

$preSend = $classifier->classify(new SmartUcfSessionException(
    'missing url',
    true,
    '',
    0,
    SmartUcfSessionException::KIND_PRE_SEND
));
assertAud002b($preSend->targetState() === SmartUcfLifecycleStates::FAILED, 'pre-send → failed');
assertAud002b($preSend->isRetryable() === true, 'pre-send retryable');
assertAud002b($preSend->errorClass() === SmartUcfFailureClassification::CLASS_PRE_SEND, 'pre-send class');

$timeout = $classifier->classify(new SmartUcfSessionException(
    'SmartUCF connection failed: Operation timed out',
    false,
    '',
    0,
    SmartUcfSessionException::KIND_TRANSPORT
));
assertAud002b($timeout->targetState() === SmartUcfLifecycleStates::OUTCOME_UNKNOWN, 'timeout → outcome_unknown');
assertAud002b($timeout->isRetryable() === false, 'timeout not retryable');

$http500 = $classifier->classify(new SmartUcfSessionException(
    'no session',
    false,
    '{"error":"server"}',
    503,
    SmartUcfSessionException::KIND_TRANSPORT
));
assertAud002b($http500->targetState() === SmartUcfLifecycleStates::OUTCOME_UNKNOWN, '5xx → outcome_unknown');

$remote = $classifier->classify(new SmartUcfSessionException(
    'no session',
    false,
    '{"errorText":"invalid"}',
    400,
    SmartUcfSessionException::KIND_REMOTE
));
assertAud002b($remote->targetState() === SmartUcfLifecycleStates::FAILED, '4xx remote → failed');
assertAud002b($remote->isRetryable() === false, 'remote reject not retryable');

$duplicate = $classifier->classify(new SmartUcfSessionException(
    'no session',
    false,
    'Duplicate order already exists',
    200,
    SmartUcfSessionException::KIND_DUPLICATE
));
assertAud002b($duplicate->targetState() === SmartUcfLifecycleStates::OUTCOME_UNKNOWN, 'duplicate → outcome_unknown');
assertAud002b($duplicate->isRetryable() === false, 'duplicate not retryable');
assertAud002b($duplicate->errorClass() === SmartUcfFailureClassification::CLASS_DUPLICATE_ORDER_NO, 'duplicate class');

$created = SmartUcfCoordinationResult::created('https://bank.example/s1', 's1');
assertAud002b($created->isCreated() && $created->redirectUrl() === 'https://bank.example/s1', 'created DTO');
$processing = SmartUcfCoordinationResult::processing('wait');
assertAud002b($processing->isProcessing(), 'processing DTO');
$unknown = SmartUcfCoordinationResult::outcomeUnknown('unknown msg');
assertAud002b($unknown->isOutcomeUnknown(), 'unknown DTO');
$failed = SmartUcfCoordinationResult::failed('fail', true, 'pre_send');
assertAud002b($failed->isFailed() && $failed->isRetryable(), 'failed DTO');
assertAud002b(SmartUcfCoordinationResult::process2()->isProcess2(), 'process2 DTO');

// Static contracts
$snapshotRepo = (string) file_get_contents($root . '/src/Order/FinancingSnapshotRepository.php');
assertAud002b(strpos($snapshotRepo, 'smartucf_state') !== false, 'snapshot schema includes smartucf_state');
assertAud002b(strpos($snapshotRepo, 'idx_unipayment_snapshot_smartucf_state') !== false, 'smartucf state index');
assertAud002b(strpos($snapshotRepo, 'smartucf_retryable') !== false, 'smartucf_retryable column');

$lifecycle = (string) file_get_contents($root . '/src/SmartUcf/SmartUcfLifecycleRepository.php');
assertAud002b(strpos($lifecycle, 'STALE_SUBMITTING_SECONDS = 45') !== false, 'stale submitting constant');
assertAud002b(strpos($lifecycle, 'claimForSubmitting') !== false, 'atomic claim method');
assertAud002b(strpos($lifecycle, 'markOutcomeUnknown') !== false, 'outcome_unknown persistence');

$coordinator = (string) file_get_contents($root . '/src/SmartUcf/SmartUcfSessionCoordinator.php');
assertAud002b(strpos($coordinator, 'markDefinitiveFailure') !== false, 'definitive failure path exists');
assertAud002b(strpos($coordinator, 'OUTCOME_UNKNOWN') !== false, 'coordinator handles outcome_unknown');
assertAud002b(strpos($coordinator, 'handleCreateFailure') !== false, 'create failure boundary helper');
assertAud002b(strpos($coordinator, 'markCreated failed after remote success') !== false, 'post-success markCreated handling');
// outcome_unknown must not call markDefinitiveFailure in that branch
$unknownBranch = substr($coordinator, (int) strpos($coordinator, 'OUTCOME_UNKNOWN'));
$unknownSection = substr($unknownBranch, 0, (int) strpos($unknownBranch, 'markFailed'));
assertAud002b(strpos($unknownSection, 'markDefinitiveFailure') === false, 'outcome_unknown must not mark definitive failure');

$lifecycleRepoSrc = (string) file_get_contents($root . '/src/SmartUcf/SmartUcfLifecycleRepository.php');
assertAud002b(strpos($lifecycleRepoSrc, 'Affected_Rows()') !== false, 'mark* checks affected rows');
assertAud002b(strpos($lifecycleRepoSrc, 'SmartUcfLifecyclePersistenceException') !== false, 'persistence exception on bad transition');

$client = (string) file_get_contents($root . '/src/SmartUcf/SmartUcfSessionClient.php');
assertAud002b(strpos($client, "orderNo' => (string) \$snapshot['order_reference']") === false, 'orderNo remains in payload builder not client');
$payload = (string) file_get_contents($root . '/src/SmartUcf/SmartUcfPayloadBuilder.php');
assertAud002b(strpos($payload, "orderNo' => (string) \$snapshot['order_reference']") !== false, 'orderNo = order_reference unchanged');
assertAud002b(strpos($client, 'KIND_TRANSPORT') !== false, 'client marks transport failures');
assertAud002b(strpos($client, 'KIND_PRE_SEND') !== false, 'client marks pre-send failures');

$popup = (string) file_get_contents($root . '/controllers/front/productpopup.php');
assertAud002b(strpos($popup, 'SmartUcfSessionCoordinator') !== false, 'product popup runs SmartUCF via shared lifecycle');
assertAud002b(strpos($popup, 'markOrderCreated') !== false, 'product popup completes order_created');
assertAud002b(strpos($popup, 'PostControlPanelLifecycleService') !== false, 'product popup uses post-CP lifecycle');

$checkout = (string) file_get_contents($root . '/controllers/front/validatecheckout.php');
assertAud002b(strpos($checkout, 'SmartUcfSessionCoordinator') !== false, 'checkout wires SmartUCF coordinator');
assertAud002b(strpos($checkout, 'PostControlPanelLifecycleService') !== false, 'checkout uses post-CP lifecycle');

$tpl = (string) file_get_contents($root . '/views/templates/front/checkout_validated.tpl');
assertAud002b(strpos($tpl, 'unipayment_smartucf_outcome_unknown') !== false, 'checkout template shows outcome_unknown');

fwrite(STDOUT, "OK (AUD-002B/AUD-008 classifier + contracts)\n");
