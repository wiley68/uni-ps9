<?php

declare(strict_types=1);

/**
 * AUD-006 — uninstall cleanup contracts (true uninstall, no schema recreate).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\SmartUcf\Certificate\CertificateLocalStore;
use PrestaShop\Module\Unipayment\Uninstall\ModuleDataPurgeResult;

function assertAud006(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// --- Certificate filesystem purge (uninstall mode: no protection recreate) ---
$keys = sys_get_temp_dir() . '/unipayment-aud006-keys-' . getmypid();
@mkdir($keys, 0700, true);
file_put_contents($keys . '/avalon_cert.pem', "CERT\n");
file_put_contents($keys . '/avalon_private_key.pem', "KEY\n");
file_put_contents($keys . '/.ssl_state.json', '{}');
file_put_contents($keys . '/.sync.lock', '');
@mkdir($keys . '/.incoming', 0700, true);
file_put_contents($keys . '/.incoming/avalon_cert.pem', 'x');
file_put_contents($keys . '/.htaccess', "Require all denied\n");
file_put_contents($keys . '/index.php', "<?php\nexit;\n");

$store = new CertificateLocalStore($keys);
assertAud006($store->purgeRuntimeArtifacts(false), 'cert purge ok');
assertAud006(!is_file($keys . '/avalon_cert.pem'), '12: cert removed');
assertAud006(!is_file($keys . '/avalon_private_key.pem'), '12b: key removed');
assertAud006(!is_file($keys . '/.ssl_state.json'), '13: state removed');
assertAud006(is_file($keys . '/.htaccess'), 'htaccess preserved when present');
assertAud006(is_file($keys . '/index.php'), 'index preserved when present');
// Uninstall mode must not recreate protection if deleted
@unlink($keys . '/.htaccess');
@unlink($keys . '/index.php');
assertAud006($store->purgeRuntimeArtifacts(false), 'second uninstall purge');
assertAud006(!is_file($keys . '/.htaccess') && !is_file($keys . '/index.php'), 'no protection recreate on uninstall');

// Sync context still restores protection when requested
assertAud006($store->purgeRuntimeArtifacts(true), 'sync-context purge');
assertAud006(is_file($keys . '/.htaccess') && is_file($keys . '/index.php'), 'protection restored for sync context');

$r = new ModuleDataPurgeResult(true, ['tokens'], []);
assertAud006($r->isSuccess(), 'result success');

$purgerSrc = (string) file_get_contents($root . '/src/Uninstall/ModuleDataPurger.php');
assertAud006(strpos($purgerSrc, 'recreateEmptySchema') === false, '2: no schema recreate');
assertAud006(strpos($purgerSrc, 'ENABLED') === false, 'no ENABLED=false post-purge');
assertAud006(strpos($purgerSrc, 'purgeRuntimeArtifacts(false)') !== false, 'uninstall cert cleanup');
assertAud006(strpos($purgerSrc, 'logout') !== false, '22: best-effort logout');
assertAud006(
    strpos($purgerSrc, 'ShopConfigurationCache') !== false
        && strpos($purgerSrc, 'FinancingSnapshotRepository') !== false
        && strpos($purgerSrc, 'OrderAttemptRepository') !== false
        && strpos($purgerSrc, 'PopupSubmissionRepository') !== false
        && strpos($purgerSrc, 'SmartUcfDebugLogRepository') !== false
        && strpos($purgerSrc, 'OrderBankStatusRepository') !== false
        && strpos($purgerSrc, 'CheckoutSubmitLockRepository') !== false,
    '1: all module table owners listed'
);

$moduleSrc = (string) file_get_contents($root . '/unipayment.php');
assertAud006(strpos($moduleSrc, 'submitUnipaymentPurgeData') === false, '26: no custom purge handler submit');
assertAud006(strpos($moduleSrc, 'handleModuleDataPurge') === false, '26b: no purge handler');
assertAud006(strpos($moduleSrc, 'ModuleDataPurger') !== false, 'uninstall uses ModuleDataPurger');
assertAud006(
    strpos($moduleSrc, 'Настройките и локалните данни на UniPayment ще бъдат изтрити') !== false,
    '24: confirmUninstall configured'
);
assertAud006(strpos($moduleSrc, "version = '2.0.2'") !== false, 'version 2.0.2');
assertAud006(
    strpos($moduleSrc, 'AdminConfigurationRequestReader') !== false
        && is_file($root . '/src/Configuration/AdminConfigurationRequestReader.php')
        && strpos(
            (string) file_get_contents($root . '/src/Configuration/AdminConfigurationRequestReader.php'),
            'submitUnipaymentConfiguration'
        ) !== false,
    '27: save remains via AdminConfigurationRequestReader'
);
assertAud006(
    strpos(
        (string) file_get_contents($root . '/src/Configuration/AdminConfigurationRequestReader.php'),
        'submitUnipaymentRefresh'
    ) !== false,
    '27: refresh remains'
);
assertAud006(strpos($moduleSrc, 'submitUnipaymentDownloadJournal') !== false, '27: journal remains');

$tpl = (string) file_get_contents($root . '/views/templates/admin/configuration.tpl');
assertAud006(strpos($tpl, 'Опасна зона') === false, '25: no danger panel');
assertAud006(strpos($tpl, 'submitUnipaymentPurgeData') === false, '25b: no purge button');
assertAud006(strpos($tpl, 'Запази настройките') !== false, '27: save button');
assertAud006(strpos($tpl, 'Обнови данните от банката') !== false, '27: refresh button');
assertAud006(strpos($tpl, 'Изтегли журнал операции') !== false, '27: journal button');

$osSrc = (string) file_get_contents($root . '/src/Order/OrderStateInstaller.php');
assertAud006(strpos($osSrc, 'isReferenced') !== false, '17/18: reference check');
assertAud006(strpos($osSrc, 'findExistingStateId') !== false, '19/20: reuse historical state');

fwrite(STDOUT, "OK (AUD-006 uninstall cleanup contracts)\n");
