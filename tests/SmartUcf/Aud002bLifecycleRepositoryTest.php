<?php

declare(strict_types=1);

/**
 * AUD-002B SmartUCF lifecycle repository atomic claim + stale handling (DB).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
$psRoot = dirname(__DIR__, 4);
$config = $psRoot . '/config/config.inc.php';
if (!is_file($config)) {
    fwrite(STDOUT, "SKIP (AUD-002B lifecycle repo; PS config missing)\n");
    exit(0);
}

require $root . '/vendor/autoload.php';
require dirname(__DIR__) . '/Support/TestSuiteGuard.php';

use PrestaShop\Module\Unipayment\Tests\Support\TestSuiteGuard;

TestSuiteGuard::skipUnlessRuntimeIntegration('AUD-002B lifecycle repository');

require $config;

use PrestaShop\Module\Unipayment\Order\FinancingSnapshotRepository;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfFailureClassification;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfLifecycleRepository;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfLifecycleStates;

function assertAud002bRepo(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/** @internal IDE/runtime helper for PrestaShop Db methods used in this test */
interface Aud002bDbConnection
{
    public function execute(string $sql): bool;

    /**
     * @param array<string, mixed> $data
     */
    public function insert(
        string $table,
        array $data,
        bool $null_values = false,
        bool $use_cache = true,
        int $type = 1,
        bool $add_prefix = true
    ): bool;
}

/** @return Aud002bDbConnection */
function aud002bDb()
{
    /** @var Aud002bDbConnection */
    return Db::getInstance();
}

$snapshots = new FinancingSnapshotRepository();
assertAud002bRepo($snapshots->install(), 'snapshot table install');

$db = aud002bDb();
$attemptId = 900000 + random_int(1, 99999);
$orderId = $attemptId;
$now = gmdate('Y-m-d H:i:s');

$db->execute('DELETE FROM `' . _DB_PREFIX_ . FinancingSnapshotRepository::TABLE . '` WHERE `id_attempt`=' . (int) $attemptId);

$inserted = $db->insert(FinancingSnapshotRepository::TABLE, [
    'id_attempt' => $attemptId,
    'id_order' => $orderId,
    'order_reference' => 'AUD002BTEST',
    'cart_fingerprint' => str_repeat('a', 32),
    'scheme_type' => 'standard',
    'scheme_key' => 'standard|X|12|0',
    'kop_code' => 'X',
    'months' => 12,
    'filter_id' => 0,
    'first_installment' => 0,
    'financed_amount' => 100,
    'monthly_installment' => 10,
    'total_payable' => 120,
    'glp' => 0,
    'gpr' => 0,
    'coefficient' => 1,
    'order_total' => 100,
    'currency_iso' => 'EUR',
    'id_currency' => 1,
    'module_version' => '2.0.0',
    'submission_source' => 'product_popup',
    'customer_json' => '{}',
    'address_json' => '{}',
    'lines_json' => '[]',
    'consents_json' => '[]',
    'lifecycle_status' => 'cp_created',
    'leasing_email_sent' => 0,
    'smartucf_state' => SmartUcfLifecycleStates::NOT_STARTED,
    'smartucf_retryable' => 0,
    'created_at' => $now,
    'updated_at' => $now,
]);
assertAud002bRepo((bool) $inserted, 'fixture snapshot insert');

$lifecycle = new SmartUcfLifecycleRepository();
$winner = $lifecycle->claimForSubmitting($attemptId);
$loser = $lifecycle->claimForSubmitting($attemptId);
assertAud002bRepo(is_array($winner) && (string) $winner['smartucf_state'] === SmartUcfLifecycleStates::SUBMITTING, 'first claim wins');
assertAud002bRepo($loser === null, 'second claim loses');

$lifecycle->markCreated($attemptId, 'sess-1', 'https://bank.example/sess-1', 200);
$created = $lifecycle->findByAttempt($attemptId);
assertAud002bRepo((string) $created['smartucf_state'] === SmartUcfLifecycleStates::CREATED, 'created state');
assertAud002bRepo((string) $created['smartucf_session_id'] === 'sess-1', 'session id persisted');
assertAud002bRepo((string) $created['smartucf_redirect_url'] === 'https://bank.example/sess-1', 'redirect persisted');
assertAud002bRepo($lifecycle->claimForSubmitting($attemptId) === null, 'created cannot be reclaimed');

$markCreatedTwiceFailed = false;
try {
    $lifecycle->markCreated($attemptId, 'sess-2', 'https://bank.example/sess-2', 200);
} catch (\PrestaShop\Module\Unipayment\SmartUcf\SmartUcfLifecyclePersistenceException $e) {
    $markCreatedTwiceFailed = true;
}
assertAud002bRepo($markCreatedTwiceFailed, 'markCreated with affected_rows!=1 must throw');
$stillCreated = $lifecycle->findByAttempt($attemptId);
assertAud002bRepo((string) $stillCreated['smartucf_session_id'] === 'sess-1', 'failed markCreated must not overwrite created');

// markCreated from non-submitting (not_started) → throw
$attemptZero = $attemptId + 10;
$db->execute('DELETE FROM `' . _DB_PREFIX_ . FinancingSnapshotRepository::TABLE . '` WHERE `id_attempt`=' . (int) $attemptZero);
$db->insert(FinancingSnapshotRepository::TABLE, [
    'id_attempt' => $attemptZero,
    'id_order' => $attemptZero,
    'order_reference' => 'AUD002BZERO',
    'cart_fingerprint' => str_repeat('d', 32),
    'scheme_type' => 'standard',
    'scheme_key' => 'standard|W|12|0',
    'kop_code' => 'W',
    'months' => 12,
    'filter_id' => 0,
    'first_installment' => 0,
    'financed_amount' => 100,
    'monthly_installment' => 10,
    'total_payable' => 120,
    'glp' => 0,
    'gpr' => 0,
    'coefficient' => 1,
    'order_total' => 100,
    'currency_iso' => 'EUR',
    'id_currency' => 1,
    'module_version' => '2.0.0',
    'submission_source' => 'product_popup',
    'customer_json' => '{}',
    'address_json' => '{}',
    'lines_json' => '[]',
    'consents_json' => '[]',
    'lifecycle_status' => 'cp_created',
    'leasing_email_sent' => 0,
    'smartucf_state' => SmartUcfLifecycleStates::NOT_STARTED,
    'smartucf_retryable' => 0,
    'created_at' => $now,
    'updated_at' => $now,
]);
$zeroRowsFailed = false;
try {
    $lifecycle->markCreated($attemptZero, 'sess-x', 'https://bank.example/x', 200);
} catch (\PrestaShop\Module\Unipayment\SmartUcf\SmartUcfLifecyclePersistenceException $e) {
    $zeroRowsFailed = true;
}
assertAud002bRepo($zeroRowsFailed, 'markCreated affected_rows=0 must not be accepted as created');
$zeroRow = $lifecycle->findByAttempt($attemptZero);
assertAud002bRepo((string) $zeroRow['smartucf_state'] === SmartUcfLifecycleStates::NOT_STARTED, 'zero-row markCreated leaves prior state');


// Retryable failed → claim
$attempt2 = $attemptId + 1;
$db->execute('DELETE FROM `' . _DB_PREFIX_ . FinancingSnapshotRepository::TABLE . '` WHERE `id_attempt`=' . (int) $attempt2);
$db->insert(FinancingSnapshotRepository::TABLE, [
    'id_attempt' => $attempt2,
    'id_order' => $attempt2,
    'order_reference' => 'AUD002BFAIL',
    'cart_fingerprint' => str_repeat('b', 32),
    'scheme_type' => 'standard',
    'scheme_key' => 'standard|Y|12|0',
    'kop_code' => 'Y',
    'months' => 12,
    'filter_id' => 0,
    'first_installment' => 0,
    'financed_amount' => 100,
    'monthly_installment' => 10,
    'total_payable' => 120,
    'glp' => 0,
    'gpr' => 0,
    'coefficient' => 1,
    'order_total' => 100,
    'currency_iso' => 'EUR',
    'id_currency' => 1,
    'module_version' => '2.0.0',
    'submission_source' => 'checkout',
    'customer_json' => '{}',
    'address_json' => '{}',
    'lines_json' => '[]',
    'consents_json' => '[]',
    'lifecycle_status' => 'cp_created',
    'leasing_email_sent' => 0,
    'smartucf_state' => SmartUcfLifecycleStates::FAILED,
    'smartucf_retryable' => 1,
    'created_at' => $now,
    'updated_at' => $now,
]);
$reclaim = $lifecycle->claimForSubmitting($attempt2);
assertAud002bRepo(is_array($reclaim) && (string) $reclaim['smartucf_state'] === SmartUcfLifecycleStates::SUBMITTING, 'retryable failed can reclaim');

// Stale submitting → outcome_unknown
$attempt3 = $attemptId + 2;
$db->execute('DELETE FROM `' . _DB_PREFIX_ . FinancingSnapshotRepository::TABLE . '` WHERE `id_attempt`=' . (int) $attempt3);
$staleAt = gmdate('Y-m-d H:i:s', time() - SmartUcfLifecycleRepository::STALE_SUBMITTING_SECONDS - 5);
$db->insert(FinancingSnapshotRepository::TABLE, [
    'id_attempt' => $attempt3,
    'id_order' => $attempt3,
    'order_reference' => 'AUD002BSTALE',
    'cart_fingerprint' => str_repeat('c', 32),
    'scheme_type' => 'standard',
    'scheme_key' => 'standard|Z|12|0',
    'kop_code' => 'Z',
    'months' => 12,
    'filter_id' => 0,
    'first_installment' => 0,
    'financed_amount' => 100,
    'monthly_installment' => 10,
    'total_payable' => 120,
    'glp' => 0,
    'gpr' => 0,
    'coefficient' => 1,
    'order_total' => 100,
    'currency_iso' => 'EUR',
    'id_currency' => 1,
    'module_version' => '2.0.0',
    'submission_source' => 'cart_popup',
    'customer_json' => '{}',
    'address_json' => '{}',
    'lines_json' => '[]',
    'consents_json' => '[]',
    'lifecycle_status' => 'cp_created',
    'leasing_email_sent' => 0,
    'smartucf_state' => SmartUcfLifecycleStates::SUBMITTING,
    'smartucf_retryable' => 0,
    'smartucf_claimed_at' => $staleAt,
    'created_at' => $now,
    'updated_at' => $now,
]);
$normalized = $lifecycle->readAndNormalize($attempt3);
assertAud002bRepo(
    is_array($normalized) && (string) $normalized['smartucf_state'] === SmartUcfLifecycleStates::OUTCOME_UNKNOWN,
    'stale submitting → outcome_unknown'
);
assertAud002bRepo($lifecycle->claimForSubmitting($attempt3) === null, 'outcome_unknown cannot auto-retry claim');

$lifecycle->markOutcomeUnknown($attempt2, SmartUcfFailureClassification::CLASS_TRANSPORT_AMBIGUOUS, 0);
// attempt2 was submitting from reclaim — mark unknown
$row2 = $lifecycle->findByAttempt($attempt2);
assertAud002bRepo((string) $row2['smartucf_state'] === SmartUcfLifecycleStates::OUTCOME_UNKNOWN, 'markOutcomeUnknown from submitting');

$db->execute('DELETE FROM `' . _DB_PREFIX_ . FinancingSnapshotRepository::TABLE . '` WHERE `id_attempt` IN ('
    . (int) $attemptId . ',' . (int) $attempt2 . ',' . (int) $attempt3 . ',' . (int) $attemptZero . ')');

fwrite(STDOUT, "OK (AUD-002B lifecycle repository)\n");
