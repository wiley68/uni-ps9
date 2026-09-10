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
assertCtrl(strpos($base, 'BoundedRawBodyReader::read') !== false, 'bounded raw body reader');
assertCtrl(strpos($base, "file_get_contents('php://input')") === false, 'must not unbounded-read php://input');
assertCtrl(strpos($base, 'ModuleRequestAuthenticator') !== false, 'authenticator used');
assertCtrl(strpos($base, 'authenticate($rawBody, $headers)') !== false, 'authenticate receives raw body then headers');
assertCtrl(strpos($base, 'expectedOperation()') !== false, 'expected operation is code-defined');
assertCtrl(strpos($base, 'assertExpectedOperation') !== false, 'operation binding enforced');
assertCtrl(strpos($base, 'ModuleApiResponse::failure') !== false, 'canonical failure envelope');
assertCtrl(strpos($base, 'normalizeSuccessEnvelope') !== false, 'canonical success envelope');
assertCtrl(strpos($base, 'PAYLOAD_TOO_LARGE') !== false, 'oversized body mapped');

foreach ($controllers as $file => $class) {
    $path = $root . '/controllers/front/' . $file;
    assertCtrl(is_file($path), "{$file} missing");
    $src = (string) file_get_contents($path);
    assertCtrl(strpos($src, "final class {$class} extends ModuleApiController") !== false, "{$file} class");
    assertCtrl(strpos($src, 'handleAuthenticatedRequest') !== false, "{$file} authenticated handler");
    assertCtrl(strpos($src, 'expectedOperation()') !== false, "{$file} expectedOperation");
}

$shopcache = (string) file_get_contents($root . '/controllers/front/shopcache.php');
assertCtrl(strpos($shopcache, 'replaceSnapshot') !== false, 'shopcache uses replaceSnapshot');
assertCtrl(strpos($shopcache, 'ShopConfigurationSnapshotValidationException') !== false, 'invalid snapshot mapping');
assertCtrl(strpos($shopcache, 'hash_equals($unicid, $data[\'unicid\'])') !== false, 'unicid identity check');

$bank = (string) file_get_contents($root . '/controllers/front/orderbankstatus.php');
assertCtrl(strpos($bank, 'ORDER_ID_MAX') !== false, 'bank status uses ORDER_ID_MAX');
assertCtrl(strpos($bank, 'is_string($value)') !== false, 'bank status rejects non-string order_id');
assertCtrl(strpos($bank, 'ORDER_AMBIGUOUS') !== false, 'bank status maps ambiguous matches');

$smart = (string) file_get_contents($root . '/controllers/front/smartucfdebuglog.php');
assertCtrl(strpos($smart, 'SmartUcfDiagnosticJournal') !== false, 'smartucf uses journal');
assertCtrl(strpos($smart, 'resolveAuthorizedFinancingOrder') !== false, 'smartucf authorizes via financing order');
assertCtrl(strpos($smart, 'findLatestForAuthorizedOrder') !== false, 'smartucf binds authorized ps_order_id');
assertCtrl(strpos($smart, 'context->shop->id') !== false, 'smartucf resolves authenticated shop from context');
assertCtrl(!preg_match('/findLatestByOrderId\s*\(/', $smart), 'smartucf must not use global order_id lookup');
assertCtrl(strpos($smart, 'ORDER_ID_MAX') !== false, 'smartucf uses ORDER_ID_MAX');

$module = (string) file_get_contents($root . '/unipayment.php');
assertCtrl(strpos($module, 'ApiNonceRepository') !== false, 'install wires nonce');
assertCtrl(strpos($module, 'OrderBankStatusRepository') !== false, 'install wires bank status');
assertCtrl(strpos($module, 'SmartUcfDebugLogRepository') !== false, 'install wires smartucf log');
assertCtrl(strpos($module, 'unipayment_checkout_lock') === false, 'no checkout lock table');
assertCtrl(strpos($module, 'unipayment_order_attempt') === false, 'no order attempt table');
assertCtrl(strpos($module, 'unipayment_financing_snapshot') === false, 'financing snapshot not installed');
assertCtrl(strpos($module, 'PopupSubmissionRepository') !== false, 'Phase 7 popup table is installed');

fwrite(STDOUT, "OK (inbound ModuleFrontController contracts)\n");
