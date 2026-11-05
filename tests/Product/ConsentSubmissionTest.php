<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/Order/OrderOrchestratorTest.php';

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\ProductContext;
use PrestaShop\Module\Unipayment\Cart\CartContext;
use PrestaShop\Module\Unipayment\Cart\CartLine;
use PrestaShop\Module\Unipayment\Cart\CartPopupApplyService;
use PrestaShop\Module\Unipayment\Cart\CartPopupCalculator;
use PrestaShop\Module\Unipayment\Cart\CartSchemeResolver;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrator;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotFactory;
use PrestaShop\Module\Unipayment\Order\ControlPanelOrderPayloadBuilder;
use PrestaShop\Module\Unipayment\Order\SensitiveDataCipher;
use PrestaShop\Module\Unipayment\Product\ProductPopupApplyService;
use PrestaShop\Module\Unipayment\Product\ProductPopupCustomerValidator;
use PrestaShop\Module\Unipayment\Product\GuestCustomerFactory;
use PrestaShop\Module\Unipayment\Product\ProductPopupValidationException;
use PrestaShop\Module\Unipayment\Product\ProductPopupSchemeList;

final class ConsentMutationBoundary extends RuntimeException {}
final class Context { public Currency $currency; public Cart $cart; public Customer $customer; public object $shop; public object $language; }
final class Currency { public int $id; public string $iso_code='EUR'; public function __construct(int $id) { $this->id=$id; } }
final class Cart { public int $id=9; public int $id_currency=1; }
final class Validate { public static function isLoadedObject(object $o): bool { return $o->id > 0; } }
final class Configuration { public static function get(string $key): int { return 1; } }
final class Customer {
    public int $id=0;
    public function __set(string $key, mixed $value): void {}
    public function add(): bool { throw new ConsentMutationBoundary('fake customer creation boundary'); }
}
$ctx = new Context(); $ctx->currency=new Currency(1); $ctx->cart=new Cart(); $ctx->customer=new Customer();
$ctx->shop=(object)['id'=>1,'id_shop_group'=>1]; $ctx->language=(object)['id'=>1];
$shop=calculatorFixture();
$shop['consents']=[['id'=>1,'name'=>'Zero','mandatory'=>1,'interest_scope'=>'zero_interest'],['id'=>2,'name'=>'Nonzero','mandatory'=>1,'interest_scope'=>'nonzero_interest']];
$calculator=new Calculator('2026-08-17'); $product=new ProductContext(42,[7],1000);
$cart=new CartContext([new CartLine($product,0,1,1000)],1000);
$scheme=$calculator->availableSchemes($shop,$product,'standard')[0];
$a=new MemoryAttempts(); $s=new MemorySnapshots(); $o=new FakeOrders($created); $cp=new FakeCp();
$flow=new OrderOrchestrator($a,$s,$o,$cp,new FinancingSnapshotFactory(new SensitiveDataCipher()),new ControlPanelOrderPayloadBuilder());
$productApply=new ProductPopupApplyService($calculator,new ProductPopupCustomerValidator(),new GuestCustomerFactory(),$flow,new SensitiveDataCipher());
$cartApply=new CartPopupApplyService($calculator,new CartPopupCalculator($calculator,new CartSchemeResolver($calculator)),new ProductPopupCustomerValidator(),new GuestCustomerFactory(),$flow);
$base=['popup_offer_type'=>'standard','scheme_type'=>$scheme->type,'kop_code'=>$scheme->kopCode,'months'=>$scheme->months,'filter_id'=>$scheme->filterId,'scheme_key'=>ProductPopupSchemeList::key($scheme),'first_installment'=>0,'first_name'=>'Ivan','last_name'=>'Ivanov','address'=>'Sofia 1','phone'=>'+359888123','email'=>'ivan@example.test','glp'=>0,'required_ids'=>[],'interest_scope'=>'all'];
foreach (['product','cart'] as $source) {
    $run = static function (array $posted) use ($source,$shop,$productApply,$cartApply,$product,$cart,$ctx): void {
        if ($source === 'product') $productApply->apply($shop,$posted,$product,42,0,1,$ctx);
        else $cartApply->apply($shop,$posted,$cart,$ctx);
    };
    try { $run($base+['consent'=>[1,true,[2],999]]); assertOrder(false,$source.' accepted hidden/complex IDs'); }
    catch (ProductPopupValidationException $e) { assertOrder(isset($e->errors()['consents']),$source.' must reject before mutation'); }
    try { $run($base+['consent'=>[2]]); assertOrder(false,$source.' did not reach fake mutation boundary'); }
    catch (ConsentMutationBoundary $e) { assertOrder(true,$source.' valid acceptance reaches next boundary'); }
}
assertOrder($a->rows === [] && $cp->calls === [] && $o->created === 0,'popup consent tests reached real order/network adapter');
echo "OK product/cart consent submit boundaries (fake customer creation)\n";
