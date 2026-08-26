<?php

declare(strict_types=1);

/**
 * Phase 10 install must create exactly eight UniPayment tables including durable order path.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertPhase10Tables(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$module = (string) file_get_contents($root . '/unipayment.php');
$repo = (string) file_get_contents($root . '/src/Order/OrderBankStatusRepository.php');

$expectedInstallClasses = [
    'ShopConfigurationCache',
    'ApiNonceRepository',
    'OrderBankStatusRepository',
    'SmartUcfDebugLogRepository',
    'PopupSubmissionRepository',
    'CheckoutSubmitLockRepository',
    'OrderAttemptRepository',
    'FinancingSnapshotRepository',
    'OrderStateInstaller',
];
foreach ($expectedInstallClasses as $class) {
    assertPhase10Tables(strpos($module, $class) !== false, "install must reference {$class}");
}

assertPhase10Tables(strpos($module, 'CheckoutSubmitLockRepository') !== false, 'checkout lock repository wired in install');
assertPhase10Tables(strpos($module, 'OrderAttemptRepository') !== false, 'order attempt repository wired in install');
assertPhase10Tables(strpos($module, 'FinancingSnapshotRepository') !== false, 'financing snapshot repository wired in install');
assertPhase10Tables(strpos($module, 'actionEmailSendBefore') !== false, 'defer native order_conf via email hook');

assertPhase10Tables(strpos($repo, 'financingSnapshotTableExists') !== false, 'snapshot table gate present');
assertPhase10Tables(strpos($repo, 'INNER JOIN') !== false, 'AUD-011 JOIN retained');

$tables = [
    'unipayment_shop_cache',
    'unipayment_api_nonce',
    'unipayment_order_bank_status',
    'unipayment_smartucf_log',
    'unipayment_popup_submission',
    'unipayment_checkout_lock',
    'unipayment_order_attempt',
    'unipayment_financing_snapshot',
];
foreach ($tables as $table) {
    assertPhase10Tables(
        is_file($root . '/src/' . match ($table) {
            'unipayment_shop_cache' => 'Configuration/ShopConfigurationCache.php',
            'unipayment_api_nonce' => 'Security/ApiNonceRepository.php',
            'unipayment_order_bank_status' => 'Order/OrderBankStatusRepository.php',
            'unipayment_smartucf_log' => 'SmartUcf/SmartUcfDebugLogRepository.php',
            'unipayment_popup_submission' => 'Product/PopupSubmissionRepository.php',
            'unipayment_checkout_lock' => 'Checkout/CheckoutSubmitLockRepository.php',
            'unipayment_order_attempt' => 'Order/OrderAttemptRepository.php',
            'unipayment_financing_snapshot' => 'Order/FinancingSnapshotRepository.php',
            default => '',
        }),
        "repository file must exist for {$table}"
    );
}

fwrite(STDOUT, "OK (Phase 10 install tables + financing snapshot presence)\n");
