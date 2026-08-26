<?php

declare(strict_types=1);

/**
 * BO order financing block must show business leasing rows only.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertBoRows(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$details = (string) file_get_contents($root . '/src/Order/OrderLeasingDetailsPresenter.php');
$email = (string) file_get_contents($root . '/src/Order/LeasingOrderEmailPresenter.php');
$journal = (string) file_get_contents($root . '/src/SmartUcf/SmartUcfDiagnosticJournal.php');

assertBoRows(strpos($details, 'adminRowsFromSnapshot') !== false, '1: P1 uses admin leasing rows');
assertBoRows(strpos($details, 'applyBankStatusLabel') !== false, '6/7: bank status applied');
assertBoRows(strpos($details, 'appendOperationalDiagnostics') === false, 'diagnostics helper removed from BO path');
foreach (['Процес', 'Control Panel order ID', 'SmartUCF state', 'SmartUCF session', 'SmartUCF HTTP', 'Диагностика'] as $forbidden) {
    assertBoRows(strpos($details, $forbidden) === false, "BO must not contain {$forbidden}");
}
assertBoRows(strpos($email, 'Статус към банката') !== false, 'business bank status label source remains');
assertBoRows(strpos($email, 'КОП') !== false || strpos($email, 'KOP') !== false, 'KOP business field remains in email presenter');
assertBoRows(strpos($details, 'rowsForOrder') !== false, '8: non-financing still returns empty without snapshot');
assertBoRows(strpos($journal, 'buildExport') !== false && strpos($journal, 'SENSITIVE_KEYS') !== false, '9: journal keeps safe diagnostics');

fwrite(STDOUT, "OK (BO order financing business rows only)\n");
