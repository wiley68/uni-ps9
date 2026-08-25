<?php

declare(strict_types=1);

/**
 * Inbound ModuleFrontController contracts — authenticated raw-body flow.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertCtrl(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$controllers = [
    'shopcache.php' => 'UnipaymentShopcacheModuleFrontController',
    'orderbankstatus.php' => 'UnipaymentOrderbankstatusModuleFrontController',
    'smartucfdebuglog.php' => 'UnipaymentSmartucfdebuglogModuleFrontController',
];

$base = (string) file_get_contents($root . '/src/Controller/ModuleApiController.php');
assertCtrl(strpos($base, 'extends \\ModuleFrontController') !== false, 'base is ModuleFrontController');
assertCtrl(strpos($base, "!== 'POST'") !== false, 'POST-only');
assertCtrl(strpos($base, 'php://input') !== false, 'raw body from php://input');
assertCtrl(strpos($base, 'ModuleRequestAuthenticator') !== false, 'authenticator used');
assertCtrl(strpos($base, 'json_decode($rawBody') !== false, 'JSON parsed after raw capture');
assertCtrl(
    strpos($base, 'authenticate($payload, $rawBody, $headers)') !== false,
    'authenticate receives raw body'
);

foreach ($controllers as $file => $class) {
    $path = $root . '/controllers/front/' . $file;
    assertCtrl(is_file($path), "{$file} missing");
    $src = (string) file_get_contents($path);
    assertCtrl(strpos($src, "final class {$class} extends ModuleApiController") !== false, "{$file} class");
    assertCtrl(strpos($src, 'handleAuthenticatedRequest') !== false, "{$file} authenticated handler");
}

$shopcache = (string) file_get_contents($root . '/controllers/front/shopcache.php');
assertCtrl(strpos($shopcache, 'replaceSnapshot') !== false, 'shopcache uses replaceSnapshot');
assertCtrl(strpos($shopcache, 'ShopConfigurationSnapshotValidationException') !== false, 'invalid snapshot mapping');
assertCtrl(strpos($shopcache, 'hash_equals($unicid, $data[\'unicid\'])') !== false, 'unicid identity check');

$smart = (string) file_get_contents($root . '/controllers/front/smartucfdebuglog.php');
assertCtrl(strpos($smart, 'SmartUcfDiagnosticJournal') !== false, 'smartucf uses journal');
assertCtrl(strpos($smart, 'findLatestByOrderId') !== false, 'smartucf is read endpoint');

$module = (string) file_get_contents($root . '/unipayment.php');
assertCtrl(strpos($module, 'ApiNonceRepository') !== false, 'install wires nonce');
assertCtrl(strpos($module, 'OrderBankStatusRepository') !== false, 'install wires bank status');
assertCtrl(strpos($module, 'SmartUcfDebugLogRepository') !== false, 'install wires smartucf log');
assertCtrl(strpos($module, 'unipayment_checkout_lock') === false, 'no checkout lock table');
assertCtrl(strpos($module, 'unipayment_order_attempt') === false, 'no order attempt table');
assertCtrl(strpos($module, 'unipayment_financing_snapshot') === false, 'financing snapshot not installed in Phase 4');
assertCtrl(strpos($module, 'unipayment_popup_submission') === false, 'no popup table');

fwrite(STDOUT, "OK (inbound ModuleFrontController contracts)\n");
