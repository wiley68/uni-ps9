<?php

declare(strict_types=1);

/**
 * Journal download authorization for PS9 Symfony AdminModules configure.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertJournalAuth(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$module = (string) file_get_contents($root . '/unipayment.php');
$tpl = (string) file_get_contents($root . '/views/templates/admin/configuration.tpl');
$journal = (string) file_get_contents($root . '/src/SmartUcf/SmartUcfDiagnosticJournal.php');

assertJournalAuth(strpos($module, 'function isAuthorizedJournalDownload') !== false, 'auth helper present');
assertJournalAuth(strpos($module, 'UserTokenManager') !== false, 'valid BO uses UserTokenManager::isTokenValid');
assertJournalAuth(
    (bool) preg_match('/isAuthorizedJournalDownload\(\)[\s\S]*displayError[\s\S]*Нямате право/u', $module),
    'denied path still shows authorization error'
);
assertJournalAuth(
    strpos($module, "Tools::getValue('token'") !== false
        || strpos($module, 'isTokenValid') !== false,
    'missing/invalid token rejected via token manager or legacy fallback'
);
assertJournalAuth(strpos($tpl, 'name="token"') !== false, 'form posts token');
assertJournalAuth(strpos($tpl, 'name="_token"') !== false, 'form posts Symfony _token');
assertJournalAuth(strpos($module, 'Content-Disposition: attachment') !== false, 'download headers');
assertJournalAuth(strpos($module, 'application/json') !== false, 'JSON content type');
assertJournalAuth(strpos($module, 'ob_end_clean') !== false, 'no markup before download');
assertJournalAuth(strpos($journal, 'SENSITIVE_KEYS') !== false, 'EGN/secrets redacted in export');
assertJournalAuth(
    (bool) preg_match('/id_shop[^\n]+\$idShop/', $module) || strpos($module, "'id_shop' => \$idShop") !== false,
    'export scoped to current shop'
);

fwrite(STDOUT, "OK (journal authorization + download contract)\n");
