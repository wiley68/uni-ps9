<?php

declare(strict_types=1);

/**
 * Phase 4 install must create only the four allowed module tables.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertPhase4Tables(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$module = (string) file_get_contents($root . '/unipayment.php');
$repo = (string) file_get_contents($root . '/src/Order/OrderBankStatusRepository.php');
$ctrl = (string) file_get_contents($root . '/controllers/front/orderbankstatus.php');

$allowedInstall = [
    'ShopConfigurationCache',
    'ApiNonceRepository',
    'OrderBankStatusRepository',
    'SmartUcfDebugLogRepository',
];
foreach ($allowedInstall as $class) {
    assertPhase4Tables(
        (bool) preg_match('/\$\w+\s*=\s*new PrestaShop\\\\Module\\\\Unipayment\\\\[^;]+' . preg_quote($class, '/') . '\(\)/', $module)
            || strpos($module, $class) !== false,
        "install must reference {$class}"
    );
}

assertPhase4Tables(
    (bool) preg_match('/\$cache->install\(\)/', $module)
        && (bool) preg_match('/\$apiNonce->install\(\)/', $module)
        && (bool) preg_match('/\$bankStatus->install\(\)/', $module)
        && (bool) preg_match('/\$debugLog->install\(\)/', $module),
    'Phase 4 install calls cache/nonce/bank/debug install'
);

assertPhase4Tables(strpos($module, 'unipayment_financing_snapshot') === false, 'module must not install financing_snapshot');
assertPhase4Tables(strpos($module, 'FinancingSnapshotRepository') === false, 'module must not wire FinancingSnapshotRepository install');
assertPhase4Tables(strpos($module, 'unipayment_checkout_lock') === false, 'no checkout lock');
assertPhase4Tables(strpos($module, 'unipayment_order_attempt') === false, 'no order attempt');
assertPhase4Tables(strpos($module, 'unipayment_popup_submission') === false, 'no popup');

assertPhase4Tables(strpos($repo, 'financingSnapshotTableExists') !== false, 'missing-table gate present');
assertPhase4Tables(strpos($repo, 'SHOW TABLES LIKE') !== false, 'existence uses SHOW TABLES');
assertPhase4Tables(strpos($repo, 'INNER JOIN') !== false, 'AUD-011 JOIN retained');
assertPhase4Tables(strpos($repo, 'ctype_digit') === false, 'no numeric id_order fallback');

assertPhase4Tables(
    preg_match('/if \(\$result === null\)[\s\S]*404/', $ctrl) === 1,
    'controller maps null repository result to 404'
);

fwrite(STDOUT, "OK (Phase 4 install tables + financing snapshot absence gate)\n");
