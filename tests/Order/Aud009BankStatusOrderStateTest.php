<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
function assertAud009(bool $ok, string $message): void { if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }
$root = dirname(__DIR__, 2);
$controller = (string) file_get_contents($root . '/controllers/front/orderbankstatus.php');
$coordinator = (string) file_get_contents($root . '/src/SmartUcf/SmartUcfSessionCoordinator.php');
$orchestrator = (string) file_get_contents($root . '/src/Order/OrderOrchestrator.php');
$gateway = (string) file_get_contents($root . '/src/Order/NativePrestaShopOrderGateway.php');
$template = (string) file_get_contents($root . '/views/templates/admin/configuration.tpl');
assertAud009(strpos($controller, 'updateByOrderIdentifier') !== false, 'bank status persistence was removed');
assertAud009(strpos($controller, 'BankStatusOrderStateMapper') === false, 'callback still maps bank status to native order state');
assertAud009(strpos($controller, "['ps_order_state_changed'] = false") !== false, 'callback contract must report no native state mutation');
assertAud009(strpos($coordinator, 'markDefinitiveFailure') !== false, 'SmartUCF definitive failure handling was removed');
assertAud009(strpos($coordinator, 'markFailed($idOrder)') === false, 'SmartUCF failure still changes native order state');
assertAud009(strpos($orchestrator, '->markFailed(') === false, 'CP/order failure still changes native order state');
assertAud009(strpos($orchestrator, '->markAwaiting(') === false, 'CP success still rewrites native order state');
assertAud009(substr_count($gateway, 'setCurrentState') === 2, 'only explicit legacy gateway methods may contain native transitions');
assertAud009(strpos($gateway, 'validateOrder') !== false, 'initial configured order status was removed');
assertAud009(strpos($template, 'UNIPAYMENT_SYNC_BANK_REJECTION_STATE') === false, 'obsolete rejection sync setting remains visible');
foreach (['bank_sent_process1', 'bank_sent_process2', 'bank_send_failed_smartucf', '05', '60', '65', '85', '90', '91', '94'] as $statusId) {
    assertAud009(strpos($controller, "'{$statusId}'") === false, "status {$statusId} has native state policy in callback");
}
assertAud009(strpos((string) file_get_contents($root . '/unipayment.php'), "version = '2.0.1'") !== false, 'version 2.0.1');
assertAud009((glob($root . '/upgrade/upgrade-*.php') ?: []) === [], 'schema-free remediation must not add an upgrade script');
fwrite(STDOUT, "OK (bank and PrestaShop order status lifecycles are separated)\n");
