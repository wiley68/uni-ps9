<?php

declare(strict_types=1);

// Reuse the isolated durable EUR/origin matrix, never a shop bootstrap.
require __DIR__ . '/EurPopupReplayTest.php';

use PrestaShop\Module\Unipayment\Order\ControlPanelOrderClientAdapter;

final class Db
{
    public static EurReplayDb $database;
    public static function getInstance(): EurReplayDb { return self::$database; }
}
final class Tools
{
    public static array $accepted = [];
    public static function getValue(string $key, mixed $default = false): mixed
    {
        return $key === 'unipayment_consent' ? self::$accepted : $default;
    }
}
final class Order
{
    public int $id;
    public int $id_currency = 1;
    public function __construct(int $id) { $this->id = $id; }
}
final class Currency
{
    public int $id;
    public string $iso_code = 'EUR';
    public function __construct(int $id) { $this->id = $id; }
}
final class Validate
{
    public static function isLoadedObject(object $object): bool { return $object->id > 0; }
}
class ModuleFrontController { public object $context; }
final class Unipayment {}

require dirname(__DIR__, 2) . '/controllers/front/productpopup.php';
require dirname(__DIR__, 2) . '/controllers/front/cartpopup.php';

function assertNoConsentReplayContinuation(array $response): void
{
    if (isset($response['redirect_url'])) {
        throw new RuntimeException('Consent refusal contains redirect_url');
    }
    if (isset($response['cp_error'])) {
        throw new RuntimeException('Consent refusal contains cp_error');
    }
}

// Exercise the same assertions used below, including each forbidden field alone.
$negativeChecks = 0;
foreach ([['redirect_url' => '/forbidden'], ['cp_error' => 'forbidden'], ['redirect_url' => '/forbidden', 'cp_error' => 'forbidden']] as $forbidden) {
    try {
        assertNoConsentReplayContinuation($forbidden);
    } catch (RuntimeException $exception) {
        ++$negativeChecks;
        continue;
    }
    assertPopupReplay(false, 'Replay assertion accepted a forbidden continuation field');
}
assertPopupReplay($negativeChecks === 3, 'All three negative replay assertion cases must reject');
assertNoConsentReplayContinuation([]);

Db::$database = $db;
$db->attempt = $validAttempt;
$db->snapshot = array_replace($validSnapshot, ['kop_code' => 'ZERO', 'months' => 10]);
$row = $submission + ['submission_token' => 'synthetic-token', 'order_reference' => 'EURORDER1'];
$shop = [
    'coeff_list' => [['onlineProductCode' => 'ZERO', 'installmentCount' => 10, 'coeff' => 0.1, 'interestPercent' => 0]],
    'consents' => [
        ['id' => 1, 'name' => 'All', 'mandatory' => 1],
        ['id' => 2, 'name' => 'Zero', 'mandatory' => 1, 'interest_scope' => 'zero_interest'],
        ['id' => 3, 'name' => 'Nonzero', 'mandatory' => 1, 'interest_scope' => 'nonzero_interest'],
    ],
];
// An unconstructed adapter cannot send anything: any accidental continuation fails this test.
$cp = (new ReflectionClass(ControlPanelOrderClientAdapter::class))->newInstanceWithoutConstructor();
foreach ([UnipaymentProductPopupModuleFrontController::class, UnipaymentCartPopupModuleFrontController::class] as $class) {
    $controller = (new ReflectionClass($class))->newInstanceWithoutConstructor();
    $controller->context = (object) ['shop' => (object) ['id' => 1]];
    $method = new ReflectionMethod($class, 'existingOrderResponse');
    foreach ([[1, 3], [], [1, 2, 3]] as $accepted) {
        $currentShop = $shop;
        if ($accepted === [1, 2, 3]) $currentShop['consents'][] = ['id' => 99, 'name' => 'New mandatory', 'mandatory' => 1];
        Tools::$accepted = $accepted;
        $before = [$db->attempt, $db->snapshot];
        $response = $method->invoke($controller, $row, $currentShop, new Unipayment(), $cp);
        assertPopupReplay($response['success'] && $response['final'] && $response['order']['id_order'] === 55 && $response['order']['control_panel_order_id'] === 901, $class . ' consent refusal lost existing order');
        assertPopupReplay(isset($response['post_order_error']), 'consent refusal must remain order-aware');
        assertNoConsentReplayContinuation($response);
        assertPopupReplay($before === [$db->attempt, $db->snapshot], 'consent refusal rewrote frozen history');
    }
    Tools::$accepted = [1, 2];
    $unknown = $shop;
    $unknown['coeff_list'] = [];
    $response = $method->invoke($controller, $row, $unknown, new Unipayment(), $cp);
    assertPopupReplay($response['final'] && isset($response['post_order_error']), 'unknown GLP must block completed replay');
    $before = $db->snapshot;
    $db->snapshot['currency_iso'] = 'BGN';
    try {
        $method->invoke($controller, $row, $shop, new Unipayment(), $cp);
        assertPopupReplay(false, 'consent shortcut bypassed EUR provenance');
    } catch (RuntimeException $exception) {
        assertPopupReplay(true, 'EUR guard preserved');
    }
    $db->snapshot = array_replace($before, ['cp_origin' => 'https://foreign.example']);
    try {
        $method->invoke($controller, $row, $shop, new Unipayment(), $cp);
        assertPopupReplay(false, 'consent shortcut bypassed origin provenance');
    } catch (RuntimeException $exception) {
        assertPopupReplay(true, 'origin guard preserved');
    }
    $db->snapshot = $before;
}
echo "OK completed Product/Cart consent replay, order awareness, EUR/origin, no continuation; {$negativeChecks} negative assertion cases\n";
