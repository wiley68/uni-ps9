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
use PrestaShop\Module\Unipayment\Order\CreatedOrder;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrator;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotFactory;
use PrestaShop\Module\Unipayment\Order\ControlPanelOrderPayloadBuilder;
use PrestaShop\Module\Unipayment\Product\ProductPopupApplyService;
use PrestaShop\Module\Unipayment\Product\ProductPopupCustomerValidator;
use PrestaShop\Module\Unipayment\Product\GuestCustomerFactory;
use PrestaShop\Module\Unipayment\Product\ProductPopupValidationException;
use PrestaShop\Module\Unipayment\Product\ProductPopupSchemeList;

final class ConsentMutationBoundary extends RuntimeException {}
final class Context
{
    public static self $current;
    public Currency $currency;
    public Cart $cart;
    public Customer $customer;
    public object $shop;
    public object $language;
    public PopupCookie $cookie;

    public static function getContext(): self { return self::$current; }
}

class ModuleFrontController
{
    public function __construct(public Context $context, public object $module) {}
}

final class Tools
{
    public static array $posted = [];

    public static function getToken(bool $page): string { return 'synthetic-token'; }
    public static function getValue(string $key, mixed $default = false): mixed { return self::$posted[$key] ?? $default; }
}

final class Product
{
    public bool $active = true;
    public bool $available_for_order = true;

    public function __construct(public int $id, bool $full, int $idLang) {}
    public function getPrice(bool $tax, ?int $attribute, int $precision): float { return 1000; }
    public function getCategories(): array { return [7]; }
    public function checkQty(int $quantity): bool { return $quantity === 1; }
}
final class Currency { public int $id; public string $iso_code='EUR'; public function __construct(int $id) { $this->id=$id; } }
trait PopupFixtureFields
{
    private array $fields = [];

    public function __set(string $key, mixed $value): void { $this->fields[$key] = $value; }
    public function __get(string $key): mixed { return $this->fields[$key] ?? null; }
}

final class Cart
{
    use PopupFixtureFields;

    public const BOTH = 3;

    public int $id = 9;
    public int $id_currency = 1;
    public int $id_address_delivery = 0;
    public int $id_address_invoice = 0;

    public function add(): bool { return true; }
    public function update(): bool { return true; }
    public function updateQty(int $quantity, int $idProduct, ?int $idAttribute): bool { return true; }
    public static function resetStaticCache(): void {}
    public function getProducts(bool $refresh): array
    {
        return [['id_product' => 42, 'cart_quantity' => 1, 'total_wt' => 1000]];
    }
    public function getOrderTotal(bool $tax, int $type): float { return 1000; }
    public function getDeliveryOption(?object $country, bool $dontAutoSelect, bool $useCache): array { return []; }
}

final class Address
{
    use PopupFixtureFields;

    public bool $deleted = false;

    public function __construct(public int $id = 0)
    {
        if ($id > 0) {
            $this->id_customer = 7;
            $this->firstname = 'Ivan';
            $this->lastname = 'Ivanov';
            $this->address1 = 'Sofia 1';
            $this->phone_mobile = '+359888123';
        }
    }

    public function add(): bool { $this->id = 8; return true; }
}

final class PopupCookie
{
    use PopupFixtureFields;

    public int $id_guest = 42;

    public function write(): void {}
}
final class Validate { public static function isLoadedObject(object $o): bool { return $o->id > 0; } }
final class Configuration { public static function get(string $key): int { return 1; } }
final class Customer
{
    use PopupFixtureFields;

    public static bool $stopAtMutation = true;

    public function __construct(public int $id = 0) {}
    public function isLogged(): bool { return $this->id === 7; }
    public function getAddresses(int $idLang): array { return $this->isLogged() ? [['id_address' => 7]] : []; }

    public function add(): bool
    {
        if (self::$stopAtMutation) {
            throw new ConsentMutationBoundary('fake customer creation boundary');
        }
        $this->id = 8;

        return true;
    }
}
$ctx = new Context(); $ctx->currency=new Currency(1); $ctx->cart=new Cart(); $ctx->customer=new Customer();
$ctx->shop=(object)['id'=>1,'id_shop_group'=>1]; $ctx->language=(object)['id'=>1];
$shop=calculatorFixture(['uni_proces' => 1]);
$shop['consents']=[['id'=>1,'name'=>'Zero','mandatory'=>1,'interest_scope'=>'zero_interest'],['id'=>2,'name'=>'Nonzero','mandatory'=>1,'interest_scope'=>'nonzero_interest']];
$calculator=new Calculator('2026-08-17'); $product=new ProductContext(42,[7],1000);
$cart=new CartContext([new CartLine($product,0,1,1000)],1000);
$scheme=$calculator->availableSchemes($shop,$product,'standard')[0];
$a=new MemoryAttempts(); $s=new MemorySnapshots(); $o=new FakeOrders($created); $cp=new FakeCp();
$flow=new OrderOrchestrator($a,$s,$o,$cp,new FinancingSnapshotFactory(),new ControlPanelOrderPayloadBuilder());
$productApply=new ProductPopupApplyService($calculator,new ProductPopupCustomerValidator(),new GuestCustomerFactory(),$flow);
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
Customer::$stopAtMutation = false;
$popupOrder = new CreatedOrder(55, 'ABCD12345', 1000, 'EUR', 1, $created->customer, $created->addresses, $created->lines);
foreach (['product', 'cart'] as $source) {
    foreach ([false, true] as $authenticated) {
        foreach ([[], ['egn' => ['legacy-marker'], 'phone2' => 'legacy-marker']] as $legacyKeys) {
            $ctx->customer = new Customer($authenticated ? 7 : 0);
            $ctx->cart = new Cart();
            $ctx->cookie = new PopupCookie();
            $snapshots = new MemorySnapshots();
            $orders = new FakeOrders($popupOrder);
            $cpClient = new FakeCp();
            $cpClient->queue[] = ['data' => ['id' => 901]];
            $orchestrator = new OrderOrchestrator(new MemoryAttempts(), $snapshots, $orders, $cpClient, new FinancingSnapshotFactory(), new ControlPanelOrderPayloadBuilder());
            $posted = $base + ['consent' => [2]] + $legacyKeys;

            if ($source === 'product') {
                $service = new ProductPopupApplyService($calculator, new ProductPopupCustomerValidator(), new GuestCustomerFactory(), $orchestrator);
                $result = $service->apply($shop, $posted, $product, 42, 0, 1, $ctx);
            } else {
                $service = new CartPopupApplyService($calculator, new CartPopupCalculator($calculator, new CartSchemeResolver($calculator)), new ProductPopupCustomerValidator(), new GuestCustomerFactory(), $orchestrator);
                $result = $service->apply($shop, $posted, $cart, $ctx);
            }

            assertOrder($result->state === OrderOrchestrator::CP_CREATED && $orders->created === 1 && count($cpClient->calls) === 1, $source . ' Process 2 reaches durable CP create once');
            assertOrder($ctx->customer->id === ($authenticated ? 7 : 8), $source . ' preserves authenticated identity or creates a fresh guest');
            $snapshot = $snapshots->rows[1];
            assertOrder($snapshot['sensitive_payload'] === null, $source . ' does not persist removed values');
            assertOrder(!array_key_exists('egn', $snapshot['customer_json']) && !array_key_exists('phone2', $snapshot['customer_json']), $source . ' excludes both obsolete keys from customer JSON');
        }
    }
}

Context::$current = $ctx;
$module = new class ($shop) {
    public bool $active = true;

    public function __construct(private array $snapshot) {}
    public function getShopConfigurationService(): self { return $this; }
    public function get(): array { return $this->snapshot; }
};
$_SERVER['REQUEST_METHOD'] = 'POST';
$controllerRoot = dirname(__DIR__, 2) . '/controllers/front/';
require $controllerRoot . 'productpopup.php';
require $controllerRoot . 'cartpopup.php';
foreach ([new UnipaymentProductPopupModuleFrontController($ctx, $module), new UnipaymentCartPopupModuleFrontController($ctx, $module)] as $controller) {
    $response = new ReflectionMethod($controller, 'response');
    $supported = array_intersect_key($base, array_flip(['first_name', 'last_name', 'address', 'phone', 'email']));
    foreach ([[], ['egn' => ['legacy-marker'], 'phone2' => 'legacy-marker'], ['egn' => 'legacy-marker', 'phone2' => ['legacy-marker']]] as $legacyKeys) {
        Tools::$posted = $base + ['popup_action' => 'validate_step2', 'token' => 'synthetic-token', 'id_product' => 42] + $legacyKeys;
        $result = $response->invoke($controller);
        assertOrder($result['success'] === true && $result['customer'] === $supported, get_class($controller) . ' validates Step 2 and returns only supported customer fields');
    }
}

echo "OK product/cart Process 2 Step 2, consent, guest/authenticated apply and durable creation with synthetic adapters\n";
