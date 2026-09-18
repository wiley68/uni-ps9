<?php

declare(strict_types=1);

/**
 * Runtime DB regression: empty-string CP sync fields admit via real MySQL CAS.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
$psRoot = dirname(__DIR__, 4);
$config = $psRoot . '/config/config.inc.php';
if (!is_file($config)) {
    fwrite(STDOUT, "SKIP (CP status-sync CAS empty-string runtime; PS config missing)\n");
    exit(0);
}

require $root . '/vendor/autoload.php';
require dirname(__DIR__) . '/Support/TestSuiteGuard.php';

use PrestaShop\Module\Unipayment\Tests\Support\TestSuiteGuard;

TestSuiteGuard::skipUnlessRuntimeIntegration('CP status-sync CAS empty-string runtime');

require $config;

use PrestaShop\Module\Unipayment\Order\BankStatus;
use PrestaShop\Module\Unipayment\Order\ControlPanelStatusSyncStates;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotRepository;

function assertCasRuntime(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$db = Db::getInstance();
$repo = new FinancingSnapshotRepository($db);
assertCasRuntime($repo->install(), 'snapshot schema available');

$attemptId = 910000 + random_int(1, 99999);
$table = _DB_PREFIX_ . FinancingSnapshotRepository::TABLE;
$now = gmdate('Y-m-d H:i:s');

$db->execute('DELETE FROM `' . $table . '` WHERE `id_attempt`=' . (int) $attemptId);

// Reproduce production empty-string persistence (not SQL NULL).
$db->execute(
    'INSERT INTO `' . $table . '` (
        `id_attempt`, `id_order`, `order_reference`, `cart_fingerprint`, `scheme_type`, `scheme_key`,
        `kop_code`, `months`, `filter_id`, `first_installment`, `financed_amount`, `monthly_installment`,
        `total_payable`, `glp`, `gpr`, `coefficient`, `order_total`, `currency_iso`, `id_currency`,
        `module_version`, `submission_source`, `customer_json`, `address_json`, `lines_json`, `consents_json`,
        `lifecycle_status`, `smartucf_state`, `smartucf_retryable`,
        `cp_status_sync_state`, `cp_status_sync_status_id`, `cp_status_sync_status`, `cp_status_sync_error_class`,
        `created_at`, `updated_at`
     ) VALUES (
        ' . (int) $attemptId . ', ' . (int) $attemptId . ', \'CASRT' . (int) $attemptId . '\', \'' . str_repeat('c', 64) . '\',
        \'standard\', \'standard|X|12|0\', \'X\', 12, 0, 0, 100, 10, 120, 0, 0, 1, 100, \'EUR\', 1,
        \'2.0.3\', \'checkout\', \'{}\', \'{}\', \'[]\', \'{}\',
        \'cp_created\', \'not_started\', 0,
        \'not_needed\', \'\', \'\', \'\',
        \'' . pSQL($now) . '\', \'' . pSQL($now) . '\'
     )'
);

$phys = $db->getRow(
    'SELECT
        IF(`cp_status_sync_status_id` IS NULL, \'IS_NULL\', CONCAT(\'LEN=\', LENGTH(`cp_status_sync_status_id`))) AS id_phys,
        IF(`cp_status_sync_status` IS NULL, \'IS_NULL\', CONCAT(\'LEN=\', LENGTH(`cp_status_sync_status`))) AS status_phys
     FROM `' . $table . '` WHERE `id_attempt`=' . (int) $attemptId
);
assertCasRuntime(is_array($phys) && $phys['id_phys'] === 'LEN=0', 'precondition: empty-string status_id');
assertCasRuntime(is_array($phys) && $phys['status_phys'] === 'LEN=0', 'precondition: empty-string status');

$admitted = $repo->compareAndSetPendingTarget(
    $attemptId,
    ControlPanelStatusSyncStates::NOT_NEEDED,
    null,
    null,
    BankStatus::SENT_PROCESS1,
    BankStatus::LABEL_SENT_PROCESS1
);
assertCasRuntime($admitted, 'runtime empty-string not_needed → pending process1');

$row = $repo->findByAttempt($attemptId);
assertCasRuntime(is_array($row), 'row readable after admit');
assertCasRuntime(
    (string) ($row['cp_status_sync_state'] ?? '') === ControlPanelStatusSyncStates::PENDING,
    'runtime state is pending'
);
assertCasRuntime(
    (string) ($row['cp_status_sync_status_id'] ?? '') === BankStatus::SENT_PROCESS1,
    'runtime target is process1'
);

// Exact non-null must not match a different target.
$noMatch = $repo->compareAndSetConfirmed(
    $attemptId,
    BankStatus::SENT_PROCESS2,
    BankStatus::LABEL_SENT_PROCESS2
);
assertCasRuntime(!$noMatch, 'exact non-null CAS rejects wrong target');

$confirmed = $repo->compareAndSetConfirmed(
    $attemptId,
    BankStatus::SENT_PROCESS1,
    BankStatus::LABEL_SENT_PROCESS1
);
assertCasRuntime($confirmed, 'confirm exact pending process1');

// SQL NULL compatibility on a second row.
$attemptNull = $attemptId + 1;
$db->execute('DELETE FROM `' . $table . '` WHERE `id_attempt`=' . (int) $attemptNull);
$db->execute(
    'INSERT INTO `' . $table . '` (
        `id_attempt`, `id_order`, `order_reference`, `cart_fingerprint`, `scheme_type`, `scheme_key`,
        `kop_code`, `months`, `filter_id`, `first_installment`, `financed_amount`, `monthly_installment`,
        `total_payable`, `glp`, `gpr`, `coefficient`, `order_total`, `currency_iso`, `id_currency`,
        `module_version`, `submission_source`, `customer_json`, `address_json`, `lines_json`, `consents_json`,
        `lifecycle_status`, `smartucf_state`, `smartucf_retryable`,
        `cp_status_sync_state`, `cp_status_sync_status_id`, `cp_status_sync_status`, `cp_status_sync_error_class`,
        `created_at`, `updated_at`
     ) VALUES (
        ' . (int) $attemptNull . ', ' . (int) $attemptNull . ', \'CASRN' . (int) $attemptNull . '\', \'' . str_repeat('d', 64) . '\',
        \'standard\', \'standard|X|12|0\', \'X\', 12, 0, 0, 100, 10, 120, 0, 0, 1, 100, \'EUR\', 1,
        \'2.0.3\', \'checkout\', \'{}\', \'{}\', \'[]\', \'{}\',
        \'cp_created\', \'not_started\', 0,
        \'not_needed\', NULL, NULL, NULL,
        \'' . pSQL($now) . '\', \'' . pSQL($now) . '\'
     )'
);
assertCasRuntime(
    $repo->compareAndSetPendingTarget(
        $attemptNull,
        ControlPanelStatusSyncStates::NOT_NEEDED,
        null,
        null,
        BankStatus::SENT_PROCESS2,
        BankStatus::LABEL_SENT_PROCESS2
    ),
    'runtime SQL NULL not_needed → pending process2'
);

$db->execute(
    'DELETE FROM `' . $table . '` WHERE `id_attempt` IN (' . (int) $attemptId . ',' . (int) $attemptNull . ')'
);

fwrite(STDOUT, "OK (CP status-sync CAS empty-string runtime)\n");
