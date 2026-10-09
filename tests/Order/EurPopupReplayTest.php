<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
define('_DB_PREFIX_', 'ps_');
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Order\FinancingSnapshotRepository;
use PrestaShop\Module\Unipayment\Order\OrderAttemptRepository;
use PrestaShop\Module\Unipayment\Order\OrderCurrencyGuard;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrator;
use PrestaShop\Module\Unipayment\Order\PopupOrderReplayCurrencyGuard;

final class EurReplayDb
{
    /** @var array<string, mixed> */
    public array $attempt = [];
    /** @var array<string, mixed> */
    public array $snapshot = [];
    /** @return array<string, mixed>|false */
    public function getRow(string $sql): array|false|null
    {
        return strpos($sql, 'unipayment_order_attempt') !== false ? $this->attempt : $this->snapshot;
    }
}
function assertPopupReplay(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}
function rejectsPopupReplay(PopupOrderReplayCurrencyGuard $guard, array $submission): bool
{
    try {
        $guard->assertReplay($submission, 1);
    } catch (RuntimeException $exception) {
        return true;
    }
    return false;
}

$db = new EurReplayDb();
$payload = [
    'order_id' => 'EURORDER1', 'name' => 'Buyer', 'phone' => '', 'email' => '',
    'address' => '', 'address2' => '', 'price' => 100.0, 'vnoska' => 10.0,
    'gpr' => 1.0, 'vnoski' => 10, 'parva' => 0.0, 'products_id' => '1',
    'products_name' => 'Product', 'products_q' => '1', 'type_client' => 1,
    'currency' => 'EUR', 'version' => '2.0.3',
];
$db->attempt = [
    'cp_origin' => \PrestaShop\Module\Unipayment\Configuration\ControlPanelOrigin::current(),
    'id_attempt' => 7, 'id_order' => 55, 'id_shop' => 1, 'control_panel_order_id' => 901,
    'state' => OrderOrchestrator::CP_CREATED, 'cp_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
];
$db->snapshot = [
    'cp_origin' => \PrestaShop\Module\Unipayment\Configuration\ControlPanelOrigin::current(),
    'id_attempt' => 7, 'id_order' => 55, 'id_currency' => 1, 'currency_iso' => 'EUR',
    'control_panel_order_id' => 901, 'lifecycle_status' => OrderOrchestrator::CP_CREATED,
    'order_reference' => 'EURORDER1', 'order_total' => 100,
    'customer_json' => '{}', 'address_json' => '{}', 'lines_json' => '[]', 'consents_json' => '[]',
];
$nativeIso = 'EUR';
$nativeId = 1;
$currencyGuard = new OrderCurrencyGuard(static function (int $idOrder) use (&$nativeIso, &$nativeId): array {
    return ['id_currency' => $nativeId, 'currency_iso' => $nativeIso];
});
$guard = new PopupOrderReplayCurrencyGuard(new OrderAttemptRepository($db), new FinancingSnapshotRepository($db), $currencyGuard);
$submission = ['id_order' => 55, 'id_attempt' => 7, 'control_panel_order_id' => 901];
$guard->assertReplay($submission, 1);

$nativeIso = 'BGN';
$nativeId = 2;
assertPopupReplay(rejectsPopupReplay($guard, $submission), 'old native BGN order accepted');
$nativeIso = 'EUR';
$nativeId = 1;
foreach ([['currency_iso' => 'BGN'], ['id_currency' => 2], ['id_order' => 56]] as $badSnapshot) {
    $saved = $db->snapshot;
    $db->snapshot = array_replace($saved, $badSnapshot);
    assertPopupReplay(rejectsPopupReplay($guard, $submission), 'invalid durable snapshot accepted');
    $db->snapshot = $saved;
}
foreach ([null, '', '{', '{}', json_encode(array_replace($payload, ['currency' => 'BGN']), JSON_THROW_ON_ERROR)] as $badPayload) {
    $db->attempt['cp_payload'] = $badPayload;
    assertPopupReplay(rejectsPopupReplay($guard, $submission), 'invalid saved CP payload accepted');
}
$db->attempt['cp_payload'] = json_encode($payload, JSON_THROW_ON_ERROR);
$validAttempt = $db->attempt;
$validSnapshot = $db->snapshot;
foreach ([OrderOrchestrator::CP_OUTCOME_UNKNOWN, OrderOrchestrator::CP_FAILED_RETRYABLE] as $badState) {
    $db->attempt = array_replace($validAttempt, ['state' => $badState]);
    assertPopupReplay(rejectsPopupReplay($guard, $submission), 'non-successful CP state accepted');
}
foreach ([null, 0, -1, 902] as $badCpId) {
    $db->attempt = array_replace($validAttempt, ['control_panel_order_id' => $badCpId]);
    assertPopupReplay(rejectsPopupReplay($guard, $submission), 'missing or mismatched attempt CP ID accepted');
}
$db->attempt = $validAttempt;
$db->snapshot = array_replace($validSnapshot, ['control_panel_order_id' => 902]);
assertPopupReplay(rejectsPopupReplay($guard, $submission), 'mismatched snapshot CP ID accepted');
$db->snapshot = $validSnapshot;
$db->snapshot = array_replace($validSnapshot, ['lifecycle_status' => OrderOrchestrator::CP_OUTCOME_UNKNOWN]);
assertPopupReplay(rejectsPopupReplay($guard, $submission), 'mismatched snapshot CP state accepted');
$db->snapshot = $validSnapshot;
assertPopupReplay(rejectsPopupReplay($guard, array_replace($submission, ['control_panel_order_id' => 902])),
    'mismatched popup CP ID accepted');
assertPopupReplay(rejectsPopupReplay($guard, array_replace($submission, ['control_panel_order_id' => 0])),
    'missing popup CP ID accepted');
$db->attempt = array_replace($validAttempt, [
    'cp_payload' => json_encode(array_replace($payload, ['name' => []]), JSON_THROW_ON_ERROR),
]);
assertPopupReplay(rejectsPopupReplay($guard, $submission), 'malformed required CP field accepted');
$db->attempt = $validAttempt;
assertPopupReplay($db->attempt === $validAttempt && $db->snapshot === $validSnapshot,
    'replay guard mutated durable rows');
foreach (['productpopup.php', 'cartpopup.php'] as $controller) {
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/controllers/front/' . $controller);
    assertPopupReplay(strpos($source, 'PopupOrderReplayCurrencyGuard') !== false
        && strpos($source, 'assertReplay(') !== false
        && strpos($source, 'assertReplay(') < strpos($source, "'success' => true", strpos($source, 'private function existingOrderResponse')),
        $controller . ' does not validate before successful replay');
}
