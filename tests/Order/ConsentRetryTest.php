<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

// Reuse audited in-memory order/CP adapters; this never bootstraps PrestaShop.
require __DIR__ . '/OrderOrchestratorTest.php';

use PrestaShop\Module\Unipayment\Checkout\ConsentResolver;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrationException;
use PrestaShop\Module\Unipayment\Calculator\CalculationResult;
use PrestaShop\Module\Unipayment\Checkout\CheckoutValidationException;
use PrestaShop\Module\Unipayment\Checkout\ValidatedPaymentRequest;
use PrestaShop\Module\Unipayment\Order\OrderAttemptStoreInterface;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrator;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotFactory;
use PrestaShop\Module\Unipayment\Order\ControlPanelOrderPayloadBuilder;
use PrestaShop\Module\Unipayment\Order\SensitiveDataCipher;
use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\ProductContext;

$scopeShop = calculatorFixture();
$scopeShop['consents'] = [
    ['id'=>1,'name'=>'All','mandatory'=>1,'interest_scope'=>'all'],
    ['id'=>2,'name'=>'Zero','mandatory'=>1,'interest_scope'=>'zero_interest'],
    ['id'=>3,'name'=>'Nonzero','mandatory'=>1,'interest_scope'=>'nonzero_interest'],
];
$calc = new Calculator('2026-08-17');
$product = new ProductContext(42,[7],1050);
$zeroCalc = $calc->calculateScheme($scopeShop,1050,$calc->availableSchemes($scopeShop,$product,'promo')[0]);
$nonzeroCalc = $calc->calculateScheme($scopeShop,1050,$calc->availableSchemes($scopeShop,$product,'standard')[0]);
$a = new MemoryAttempts(); $s = new MemorySnapshots(); $o = new FakeOrders($created); $c = new FakeCp();
$c->queue[] = ['data'=>['id'=>901]];
$flow = new OrderOrchestrator($a,$s,$o,$c,new FinancingSnapshotFactory(new SensitiveDataCipher()),new ControlPanelOrderPayloadBuilder());
function retryRequest(CalculationResult $calculation, array $ids): ValidatedPaymentRequest {
    return new ValidatedPaymentRequest($calculation,[], $ids, hash('sha256','same cart'), [], $ids);
}
try {
    $flow->orchestrate(1,91,retryRequest($zeroCalc,[1,3]),$scopeShop);
    assertOrder(false,'invalid new consent accepted');
} catch (CheckoutValidationException | OrderOrchestrationException $e) {
    if ($e instanceof OrderOrchestrationException) assertOrder($e->isPostOrder() && $e->getPrevious() instanceof CheckoutValidationException, 'consent rejection lost its order-aware cause');
    assertOrder($a->rows === [] && $o->created === 0 && $c->calls === [],'invalid consent mutated creation state');
}
$flow->orchestrate(1,91,retryRequest($zeroCalc,[1,2,999]),$scopeShop);
assertOrder(array_column($s->rows[1]['consents_json'],'id') === [1,2], 'only applicable accepted records persist');
assertOrder($s->rows[1]['consents_json'][1]['interest_scope'] === 'zero_interest','normalized scope persisted');
$before = $s->rows[1];
foreach (['checkout','product_popup','cart_popup'] as $source) {
    try {
        $flow->orchestrate(1,91,retryRequest($nonzeroCalc,[1,3]),$scopeShop,$source);
        assertOrder(false,'POST B consents bypassed saved A');
    } catch (CheckoutValidationException | OrderOrchestrationException $e) {
    if ($e instanceof OrderOrchestrationException) assertOrder($e->isPostOrder() && $e->getPrevious() instanceof CheckoutValidationException, 'consent rejection lost its order-aware cause');
        assertOrder($o->created === 1 && count($c->calls) === 1 && $s->rows[1] === $before,'invalid retry caused side effects');
    }
    $flow->orchestrate(1,91,retryRequest($nonzeroCalc,[1,2]),$scopeShop,$source);
    assertOrder($o->created === 1 && count($c->calls) === 1,'valid retry duplicated order or CP');
}
// A recovered operation appears only at reservation, after preflight validated B.
$racing = new class($a) implements OrderAttemptStoreInterface {
    private $inner;
    public function __construct(OrderAttemptStoreInterface $inner) { $this->inner = $inner; }
    public function findByIdentity(int $shop,int $cart,string $fingerprint): ?array { return null; }
    public function reserve(int $shop,int $cart,string $fingerprint): array { return $this->inner->reserve($shop,$cart,$fingerprint); }
    public function update(int $id,array $changes): array { return $this->inner->update($id,$changes); }
    public function attachOrderIfReserved(int $id, int $order, string $reference): array { return $this->inner->attachOrderIfReserved($id, $order, $reference); }
};
$raceFlow = new OrderOrchestrator($racing,$s,$o,$c,new FinancingSnapshotFactory(new SensitiveDataCipher()),new ControlPanelOrderPayloadBuilder());
try {
    $raceFlow->orchestrate(1,91,retryRequest($nonzeroCalc,[1,3]),$scopeShop);
    assertOrder(false,'concurrent saved A bypassed final consent validation');
} catch (CheckoutValidationException | OrderOrchestrationException $e) {
    if ($e instanceof OrderOrchestrationException) assertOrder($e->isPostOrder() && $e->getPrevious() instanceof CheckoutValidationException, 'consent rejection lost its order-aware cause');
    assertOrder($s->rows[1] === $before && count($c->calls) === 1,'concurrent rejection changed snapshot/CP');
}
$raceFlow->orchestrate(1,91,retryRequest($nonzeroCalc,[1,2,3]),$scopeShop);
assertOrder($o->created === 1 && count($c->calls) === 1,'concurrent valid replay duplicated creation');
// Retry from a saved CP failure must preserve payload bytes and financial terms.
$a->update(1,['state'=>OrderOrchestrator::CP_FAILED_RETRYABLE]);
$c->queue[]=['data'=>['id'=>901]];
$flow->orchestrate(1,91,retryRequest($nonzeroCalc,[1,2]),$scopeShop);
assertOrder(json_encode($c->calls[0]) === json_encode($c->calls[1]) && $o->created === 1,'retry changed payload or created order');
assertOrder($s->rows[1]['kop_code'] === $before['kop_code'] && $s->rows[1]['months'] === $before['months'] && $s->rows[1]['glp'] === $before['glp'],'retry changed financial snapshot');
// A later recovery read selects a different snapshot after the initial saved-scheme check.
$a->update(1, ['state'=>OrderOrchestrator::CP_FAILED_RETRYABLE]);
$changingSnapshots = new class($s->rows[1], (new ConsentResolver())->selection($nonzeroCalc)) implements \PrestaShop\Module\Unipayment\Order\FinancingSnapshotStoreInterface {
    private $saved;
    private $replacement;
    private $reads = 0;
    public function __construct(array $saved, array $selection) { $this->saved=$saved; $this->replacement=array_replace($saved,$selection); }
    public function findByAttempt(int $id): ?array { return ++$this->reads < 3 ? $this->saved : $this->replacement; }
    public function save(int $id,array $snapshot): void { throw new RuntimeException('Unexpected snapshot write'); }
    public function update(int $id,array $changes): void { throw new RuntimeException('Unexpected snapshot update'); }
};
$changedFlow = new OrderOrchestrator($a,$changingSnapshots,$o,$c,new FinancingSnapshotFactory(new SensitiveDataCipher()),new ControlPanelOrderPayloadBuilder());
try {
    $changedFlow->orchestrate(1,91,retryRequest($zeroCalc,[1,2]),$scopeShop);
    assertOrder(false,'final recovery snapshot bypassed consent validation');
} catch (CheckoutValidationException | OrderOrchestrationException $e) {
    if ($e instanceof OrderOrchestrationException) assertOrder($e->isPostOrder() && $e->getPrevious() instanceof CheckoutValidationException, 'consent rejection lost its order-aware cause'); assertOrder(count($c->calls) === 2, 'changed final snapshot reached CP'); }
$updatedShop = $scopeShop;
$updatedShop['consents'][]=['id'=>99,'name'=>'New','mandatory'=>1];
try {
    $flow->orchestrate(1,91,retryRequest($nonzeroCalc,[1,2]),$updatedShop);
    assertOrder(false,'new mandatory consent missing from old form accepted');
} catch (CheckoutValidationException | OrderOrchestrationException $e) {
    if ($e instanceof OrderOrchestrationException) assertOrder($e->isPostOrder() && $e->getPrevious() instanceof CheckoutValidationException, 'consent rejection lost its order-aware cause');
    assertOrder(strpos($e->getMessage(),'обновете') !== false && count($c->calls) === 2,'new consent must require refresh before CP');
}
// Same persisted KOP with unusable current coefficients must not use rounded historical GLP.
$scopeShop['coeff_list']=[];
try {
    $flow->orchestrate(1,91,retryRequest($nonzeroCalc,[1,2,3]),$scopeShop);
    assertOrder(false,'historical rounded GLP used on retry');
} catch (CheckoutValidationException | OrderOrchestrationException $e) {
    if ($e instanceof OrderOrchestrationException) assertOrder($e->isPostOrder() && $e->getPrevious() instanceof CheckoutValidationException, 'consent rejection lost its order-aware cause'); assertOrder(count($c->calls) === 2,'unknown retry reached network'); }
echo "OK consent retry / recovery / idempotency boundaries\n";
