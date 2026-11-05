<?php

declare(strict_types=1);

// Existing typed in-memory PS/CP adapters; no live shop bootstrap or external sends.
require dirname(__DIR__) . '/Order/OrderOrchestratorTest.php';

use PrestaShop\Module\Unipayment\Calculator\{Calculator, CurrencyGate, ProductContext};
use PrestaShop\Module\Unipayment\Cart\{CartContext, CartLine, CartSchemeResolver};
use PrestaShop\Module\Unipayment\Checkout\{CartSnapshot, CartSnapshotSigner, CheckoutPaymentValidator, CheckoutValidationException, ConsentResolver, CustomerFieldValidator, ValidatedPaymentRequest};
use PrestaShop\Module\Unipayment\Order\{ControlPanelOrderPayloadBuilder, CreatedOrder, FinancingSnapshotFactory, OrderAttemptStoreInterface, OrderOrchestrationException, OrderOrchestrator, SensitiveDataCipher};

$checks = 0;
function recoveryCheck(bool $condition, string $label): void
{
    ++$GLOBALS['checks'];
    if (!$condition) throw new RuntimeException($label);
}
function recoveryReject(callable $run, string $label): void
{
    try { $run(); } catch (OrderOrchestrationException $exception) {
        recoveryCheck($exception->idOrder() === 55 && $exception->getPrevious() instanceof CheckoutValidationException, $label . ': order-aware consent cause');
        return;
    }
    throw new RuntimeException($label . ': expected rejection');
}
/** @return array{OrderOrchestrator, MemoryAttempts, MemorySnapshots, FakeOrders, FakeCp} */
function recoveryFlow(CreatedOrder $order, string $fingerprint, ?array $snapshot = null, bool $hidePreflight = false): array
{
    $attempts = new MemoryAttempts();
    $attempts->reserve(1, 91, $fingerprint);
    $attempts->attachOrderIfReserved(1, $order->idOrder, $order->reference);
    $snapshots = new MemorySnapshots();
    if ($snapshot !== null) $snapshots->save(1, $snapshot);
    $orders = new FakeOrders($order);
    $orders->orderAlreadyOnCart = true;
    $cp = new FakeCp();
    $store = $hidePreflight ? new class($attempts) implements OrderAttemptStoreInterface {
        public function __construct(private MemoryAttempts $inner) {}
        public function findByIdentity(int $shop, int $cart, string $fingerprint): ?array { return null; }
        public function reserve(int $shop, int $cart, string $fingerprint): array { return $this->inner->reserve($shop, $cart, $fingerprint); }
        public function update(int $id, array $changes): array { return $this->inner->update($id, $changes); }
        public function attachOrderIfReserved(int $id, int $order, string $reference): array { return $this->inner->attachOrderIfReserved($id, $order, $reference); }
    } : $attempts;
    $flow = new OrderOrchestrator($store, $snapshots, $orders, $cp, new FinancingSnapshotFactory(new SensitiveDataCipher()), new ControlPanelOrderPayloadBuilder());
    return [$flow, $attempts, $snapshots, $orders, $cp];
}
function recoveryNoWork(MemoryAttempts $attempts, MemorySnapshots $snapshots, FakeOrders $orders, FakeCp $cp, array $before): void
{
    recoveryCheck([$attempts->rows, $snapshots->rows] === $before, 'refusal changes no durable history');
    recoveryCheck($orders->created === 0 && $orders->validateOrderCalls === 0 && $cp->calls === [], 'refusal creates no native/CP order or external continuation');
}

$shop = calculatorFixture();
$shop['consents'] = [
    ['id' => 1, 'name' => 'All', 'mandatory' => 1],
    ['id' => 2, 'name' => 'Zero', 'mandatory' => 1, 'interest_scope' => 'zero_interest'],
    ['id' => 3, 'name' => 'Nonzero', 'mandatory' => 1, 'interest_scope' => 'nonzero_interest'],
];
$calculator = new Calculator('2026-08-17');
$product = new ProductContext(42, [7], 1050);
$cart = new CartContext([new CartLine($product, 3, 2, 1050)], 1050);
$snapshot = new CartSnapshot();
$fingerprint = $snapshot->fingerprint($cart, 'EUR');
$signer = new CartSnapshotSigner('synthetic-recovery-key');
$validator = new CheckoutPaymentValidator($calculator, new CartSchemeResolver($calculator), new CurrencyGate(), $snapshot, $signer, new CustomerFieldValidator(), new ConsentResolver());
$posted = ['scheme_key' => '12:0', 'kop_code' => 'STD', 'first_installment' => 0, 'cart_snapshot' => $signer->sign($fingerprint), 'consent' => [1, 3], 'interest_scope' => 'all', 'required_ids' => [], 'glp' => 0];
$customer = ['first_name' => 'Ivan', 'last_name' => 'Ivanov', 'address' => 'Sofia 1', 'phone' => '+359888123', 'email' => 'ivan@example.test'];

// Original F1 reproduction: existing native order, absent snapshot, valid resolved nonzero scheme.
[$flow, $attempts, $snapshots, $orders, $cp] = recoveryFlow($created, $fingerprint);
$selection = $flow->consentSelection(1, 91, $fingerprint, []) ?: null;
$request = $validator->validate($shop, $cart, 'EUR', $posted, $customer, $selection);
recoveryCheck($request->acceptedConsentIds === [1, 3], 'F1: metadata-only recovery uses the resolved server KOP/months');
$before = [$attempts->rows, $snapshots->rows];
recoveryReject(static fn () => $validator->validate($shop, $cart, 'EUR', array_replace($posted, ['consent' => [1]]), $customer, $selection), 'missing applicable recovery consent');
recoveryNoWork($attempts, $snapshots, $orders, $cp, $before);
$cp->queue[] = ['data' => ['id' => 901]];
$result = $flow->orchestrate(1, 91, $request, $shop);
recoveryCheck($result->idOrder === 55 && $orders->created === 0 && $orders->createCalls === 0, 'recovery keeps the attached order; no new native create');
recoveryCheck(count($cp->calls) === 1 && !isset($snapshots->rows[1]['_consent_snapshot_missing']), 'one CP create and no preflight annotation persisted');
$frozen = [$snapshots->rows, $attempts->rows];
$flow->orchestrate(1, 91, $request, $shop);
recoveryCheck(count($cp->calls) === 1 && $orders->created === 0 && $frozen === [$snapshots->rows, $attempts->rows], 'completed recovery replays without duplicate order/send/history writes');

// Persisted zero-interest A always wins over the submitted, valid nonzero B.
$zeroScheme = $calculator->availableSchemes($shop, $product, 'promo')[0];
$zero = $calculator->calculateScheme($shop, 1050, $zeroScheme);
$savedA = (new FinancingSnapshotFactory(new SensitiveDataCipher()))->create(new ValidatedPaymentRequest($zero, [], [1, 2], $fingerprint), $created);
[$flow, $attempts, $snapshots, $orders, $cp] = recoveryFlow($created, $fingerprint, $savedA);
$selection = $flow->consentSelection(1, 91, $fingerprint, []) ?: null;
$requestA = $validator->validate($shop, $cart, 'EUR', array_replace($posted, ['consent' => [1, 2]]), $customer, $selection);
recoveryCheck($requestA->acceptedConsentIds === [1, 2] && $requestA->calculation->scheme->kopCode === 'STD', 'persisted A consent classification is independent from POST B calculation');
$before = [$attempts->rows, $snapshots->rows];
recoveryReject(static fn () => $validator->validate($shop, $cart, 'EUR', $posted, $customer, $selection), 'B consent cannot satisfy A');
foreach (['missing', 'invalid'] as $coefficient) {
    $changedShop = $shop;
    foreach ($changedShop['coeff_list'] as $key => &$entry) {
        if ($entry['onlineProductCode'] !== $zeroScheme->kopCode) continue;
        if ($coefficient === 'missing') unset($changedShop['coeff_list'][$key]);
        else $entry['coeff'] = 0;
    }
    unset($entry);
    recoveryReject(static fn () => $validator->validate($changedShop, $cart, 'EUR', array_replace($posted, ['consent' => [1, 2, 3]]), $customer, $selection), 'A coefficient ' . $coefficient . ' has no B fallback');
}
recoveryNoWork($attempts, $snapshots, $orders, $cp, $before);

foreach ([array_replace($savedA, ['kop_code' => '']), array_replace($savedA, ['months' => 0]), array_diff_key($savedA, ['kop_code' => true]), ['id_order' => 0, '_consent_snapshot_missing' => true], []] as $malformed) {
    [$badFlow, $a, $s, $o, $c] = recoveryFlow($created, $fingerprint, $malformed);
    $effective = $badFlow->consentSelection(1, 91, $fingerprint, []) ?: null;
    $before = [$a->rows, $s->rows];
    recoveryReject(static fn () => $validator->validate($shop, $cart, 'EUR', array_replace($posted, ['consent' => [1, 2, 3]]), $customer, $effective), 'present malformed snapshot cannot use missing-snapshot fallback');
    recoveryNoWork($a, $s, $o, $c, $before);
}

// A becomes authoritative only at reserve(): revalidate candidates after recovery too.
[$race, $a, $s, $o, $c] = recoveryFlow($created, $fingerprint, $savedA, true);
$requestB = $validator->validate($shop, $cart, 'EUR', $posted, $customer, $race->consentSelection(1, 91, $fingerprint, []) ?: null);
$before = [$a->rows, $s->rows];
recoveryReject(static fn () => $race->orchestrate(1, 91, $requestB, $shop), 'authoritative A discovered after B preflight');
recoveryNoWork($a, $s, $o, $c, $before);
$both = $validator->validate($shop, $cart, 'EUR', array_replace($posted, ['consent' => [1, 2, 3]]), $customer);
$c->queue[] = ['data' => ['id' => 901]];
$race->orchestrate(1, 91, $both, $shop);
recoveryCheck($both->acceptedConsentIds === [1, 2] && count($c->calls) === 1 && $o->created === 0, 'recovered A revalidates original candidates without duplicating the native order');
recoveryCheck($s->rows[1]['kop_code'] === $savedA['kop_code'] && $s->rows[1]['monthly_installment'] === $savedA['monthly_installment'], 'recovery preserves historical financial terms');

foreach (['foreign-attempt', 'foreign-snapshot', 'non-EUR'] as $badProvenance) {
    $native = $badProvenance === 'non-EUR' ? new CreatedOrder(55, $created->reference, 1050, 'BGN', 2, $created->customer, $created->addresses, $created->lines) : $created;
    [$guarded, $a, $s, $o, $c] = recoveryFlow($native, $fingerprint, $badProvenance === 'foreign-snapshot' ? $savedA : null);
    if ($badProvenance === 'foreign-attempt') $a->update(1, ['cp_origin' => 'https://foreign.example']);
    if ($badProvenance === 'foreign-snapshot') $s->rows[1]['cp_origin'] = 'https://foreign.example';
    $effective = $guarded->consentSelection(1, 91, $fingerprint, []) ?: null;
    $valid = $validator->validate($shop, $cart, 'EUR', array_replace($posted, ['consent' => [1, 2, 3]]), $customer, $effective);
    $before = [$a->rows, $s->rows];
    try {
        $guarded->orchestrate(1, 91, $valid, $shop);
        throw new RuntimeException('Provenance guard was bypassed');
    } catch (OrderOrchestrationException $exception) {
        recoveryCheck($exception->idOrder() === 55 && !($exception->getPrevious() instanceof CheckoutValidationException), 'existing EUR/origin proof remains authoritative');
    }
    recoveryNoWork($a, $s, $o, $c, $before);
}
echo "OK consent recovery selection {$checks} assertions\n";
