<?php

declare(strict_types=1);

/**
 * Canonical CP↔module protocol adaptation contracts for PS9.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Api\ModuleApiError;
use PrestaShop\Module\Unipayment\Api\ModuleApiOperation;
use PrestaShop\Module\Unipayment\Api\ModuleApiResponse;
use PrestaShop\Module\Unipayment\Order\BankStatus;
use PrestaShop\Module\Unipayment\Order\ControlPanelOrderPayloadBuilder;
use PrestaShop\Module\Unipayment\Security\ModuleRequestSignatureProtocol;

function assertCanon(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);

// --- Envelope helper ---
$success = ModuleApiResponse::success('ok', ['fetched_at' => 'x']);
assertCanon($success['success'] === true && $success['error'] === null && is_array($success['data']), 'success envelope keys');
$failure = ModuleApiResponse::failure(ModuleApiError::INVALID_SIGNATURE, 'bad', []);
$encodedFailure = json_encode($failure, JSON_THROW_ON_ERROR);
assertCanon(strpos($encodedFailure, '"data":{}') !== false, 'empty failure data must encode as JSON object');
assertCanon($failure['error'] === 'invalid_signature', 'machine error code');

// --- Operation constants ---
assertCanon(ModuleApiOperation::SHOP_CACHE === 'shop-cache', 'shop-cache operation');
assertCanon(ModuleApiOperation::ORDER_BANK_STATUS === 'order-bank-status', 'order-bank-status operation');
assertCanon(ModuleApiOperation::SMARTUCF_DEBUG_LOG === 'smartucf-debug-log', 'smartucf-debug-log operation');

// --- Endpoint operation binding (source contracts) ---
$shopcache = (string) file_get_contents($root . '/controllers/front/shopcache.php');
$bank = (string) file_get_contents($root . '/controllers/front/orderbankstatus.php');
$debug = (string) file_get_contents($root . '/controllers/front/smartucfdebuglog.php');
$apiCtrl = (string) file_get_contents($root . '/src/Controller/ModuleApiController.php');

assertCanon(strpos($shopcache, 'ModuleApiOperation::SHOP_CACHE') !== false, 'shopcache binds shop-cache');
assertCanon(strpos($bank, 'ModuleApiOperation::ORDER_BANK_STATUS') !== false, 'orderbankstatus binds order-bank-status');
assertCanon(strpos($debug, 'ModuleApiOperation::SMARTUCF_DEBUG_LOG') !== false, 'smartucfdebuglog binds smartucf-debug-log');
assertCanon(strpos($apiCtrl, 'assertExpectedOperation') !== false, 'controller enforces operation binding');
assertCanon(strpos($apiCtrl, 'expectedOperation()') !== false, 'expected operation is code-defined');
assertCanon(strpos($apiCtrl, 'UNSUPPORTED_OPERATION') !== false, 'wrong operation uses unsupported_operation');
assertCanon(strpos($apiCtrl, 'PAYLOAD_TOO_LARGE') !== false, 'body size gate present');
assertCanon(strpos($apiCtrl, 'ModuleApiResponse::failure') !== false, 'canonical failure envelope');
assertCanon(strpos($apiCtrl, 'normalizeSuccessEnvelope') !== false, 'canonical success envelope');

// --- order_id max 13 ---
assertCanon(ModuleRequestSignatureProtocol::ORDER_ID_MAX === 13, 'ORDER_ID_MAX is 13');
assertCanon(strpos($bank, 'ORDER_ID_MAX') !== false, 'bank status uses ORDER_ID_MAX');
assertCanon(strpos($debug, 'ORDER_ID_MAX') !== false, 'debug uses ORDER_ID_MAX');
assertCanon(strpos($bank, 'status_label') === false, 'no status_label wire alias on bank callback');
assertCanon(strpos($bank, 'is_string($value)') !== false, 'bank status rejects non-string order_id');

// --- Debug cross-shop ownership ---
assertCanon(strpos($debug, 'resolveAuthorizedFinancingOrder') !== false, 'debug authorizes via financing order');
assertCanon(strpos($debug, 'findLatestForAuthorizedOrder') !== false, 'debug scopes diagnostic by ps_order_id');
assertCanon(strpos($debug, 'ORDER_NOT_FOUND') !== false, 'debug opaque 404');

$journal = (string) file_get_contents($root . '/src/SmartUcf/SmartUcfDiagnosticJournal.php');
$store = (string) file_get_contents($root . '/src/SmartUcf/SmartUcfDebugLogRepository.php');
$bankRepo = (string) file_get_contents($root . '/src/Order/OrderBankStatusRepository.php');
assertCanon(strpos($journal, 'findLatestForAuthorizedOrder') !== false, 'journal authorized lookup');
assertCanon(strpos($journal, 'ps_order_id') !== false, 'journal binds ps_order_id');
assertCanon(strpos($store, 'findLatestByOrderIdAndShop') !== false, 'store shop-scoped lookup');
assertCanon(strpos($bankRepo, 'resolveAuthorizedFinancingOrder') !== false, 'financing order resolver present');
assertCanon(strpos($bankRepo, 'executeS') !== false, 'ambiguous financing resolve uses executeS');

// --- Create-order P1/P2 schema identity ---
$builder = new ControlPanelOrderPayloadBuilder();
$snapshot = [
    'order_reference' => 'ABCDEFGHIJKLMNOP',
    'order_total' => 100.0,
    'monthly_installment' => 10.0,
    'gpr' => 0.0,
    'months' => 10,
    'first_installment' => 10.0,
    'currency_iso' => 'BGN',
    'module_version' => '2.0.2',
    'customer_json' => [
        'first_name' => 'Ivan',
        'last_name' => 'Petrov',
        'phone' => '0888123456',
        'email' => 'ivan@example.com',
        'egn' => '9001011234',
        'phone2' => '0888000000',
    ],
    'address_json' => [
        'invoice' => ['address1' => 'Street 1', 'city' => 'Sofia', 'postcode' => '1000', 'country' => 'BG'],
        'delivery' => [],
    ],
    'lines_json' => [
        ['id_product' => 1, 'id_product_attribute' => 0, 'name' => 'Item', 'quantity' => 1],
    ],
];
$p1 = $builder->build($snapshot, ['uni_proces' => 0]);
$p2 = $builder->build($snapshot, ['uni_proces' => 1]);
assertCanon($p1['order_id'] === 'ABCDEFGHIJKLM', 'order_id truncated outbound to 13');
assertCanon(!isset($p1['status']) && !isset($p1['status_id']), 'P1 create has no status');
assertCanon(!isset($p2['status']) && !isset($p2['status_id']), 'P2 create has no status');
assertCanon(!isset($p1['egn']) && !isset($p1['phone2']), 'P1 create has no EGN/phone2');
assertCanon(!isset($p2['egn']) && !isset($p2['phone2']), 'P2 create has no EGN/phone2');
ksort($p1);
ksort($p2);
assertCanon($p1 === $p2, 'P1/P2 create schemas are identical for lifecycle fields');

// --- Lifecycle PATCH targets ---
$lifecycle = (string) file_get_contents($root . '/src/Order/PostControlPanelLifecycleService.php');
$coordinator = (string) file_get_contents($root . '/src/SmartUcf/SmartUcfSessionCoordinator.php');
assertCanon(strpos($lifecycle, 'synchronizeAfterHandoff') !== false, 'P2 uses durable CP status sync after handoff');
assertCanon(strpos($lifecycle, 'ControlPanelStatusSyncService') !== false || strpos($lifecycle, 'statusSync') !== false, 'P2 lifecycle wires status sync service');
assertCanon(strpos($coordinator, 'BankStatus::successfulSend(false)') !== false, 'P1 SmartUCF success uses process1 status');
assertCanon(strpos($coordinator, 'synchronizeAfterHandoff') !== false || strpos($coordinator, 'statusSync()') !== false, 'P1 uses durable CP status sync');
$p1Status = BankStatus::successfulSend(false);
$p2Status = BankStatus::successfulSend(true);
assertCanon($p1Status['status_id'] === 'bank_sent_process1', 'P1 PATCH status_id');
assertCanon($p2Status['status_id'] === 'bank_sent_process2', 'P2 PATCH status_id');

// --- CP client identity validation ---
$cpClient = (string) file_get_contents($root . '/src/Api/ControlPanelClient.php');
assertCanon(strpos($cpClient, 'storeTokenResponse($response, true)') !== false, 'login stores tokens from data');
assertCanon(strpos($cpClient, "\$data['access_token']") !== false, 'access_token under data');
assertCanon(strpos($cpClient, 'assertCreateOrderIdentity') !== false, 'create identity validation');
assertCanon(strpos($cpClient, 'assertPatchStatusEcho') !== false, 'PATCH echo validation');
assertCanon(strpos($cpClient, 'decodeSuccessEnvelope') !== false, 'canonical success envelope decode');
assertCanon(strpos($cpClient, 'error !== null') !== false || strpos($cpClient, '$decodedObject->error !== null') !== false, 'error must be null');
assertCanon(strpos($cpClient, 'instanceof \\stdClass') !== false, 'data must be JSON object');
assertCanon(strpos($cpClient, 'ModuleDeploymentEnvironment') !== false, 'CP client uses deployment environment for base URL');

// --- Ambiguous CP create must not write bank_send_failed_cp ---
$orchestrator = (string) file_get_contents($root . '/src/Order/OrderOrchestrator.php');
assertCanon(strpos($orchestrator, 'definitive_local_failure') !== false, 'orchestrator distinguishes definitive CP rejection');
assertCanon(strpos($orchestrator, 'cp_create_auth_ambiguous') !== false, 'auth create failures stay ambiguous');
assertCanon(
    strpos($orchestrator, 'Local bank_send_failed_cp is only written for definitive CP rejection') !== false
    || strpos($orchestrator, 'definitiveLocalFailure') !== false,
    'ambiguous CP create must not write bank_send_failed_cp'
);

// --- Durable sync schema ---
$snapshotRepo = (string) file_get_contents($root . '/src/Order/FinancingSnapshotRepository.php');
assertCanon(strpos($snapshotRepo, 'cp_status_sync_state') !== false, 'snapshot schema has cp_status_sync_state');
assertCanon(strpos($snapshotRepo, 'cp_status_sync_status_id') !== false, 'snapshot schema has cp_status_sync_status_id');
assertCanon(strpos($snapshotRepo, 'cp_status_sync_status') !== false, 'snapshot schema has cp_status_sync_status');
assertCanon(strpos($snapshotRepo, 'cp_status_sync_error_class') !== false, 'snapshot schema has cp_status_sync_error_class');
assertCanon(strpos($snapshotRepo, 'cp_status_sync_updated_at') !== false, 'snapshot schema has cp_status_sync_updated_at');

// --- Version unchanged ---
$module = (string) file_get_contents($root . '/unipayment.php');
assertCanon(strpos($module, "version = '2.0.2'") !== false, 'module version remains 2.0.2');

fwrite(STDOUT, "OK (Canonical CP↔module protocol adaptation contracts)\n");
