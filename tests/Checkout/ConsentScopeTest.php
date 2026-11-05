<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/Calculator/fixtures.php';

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\ProductContext;
use PrestaShop\Module\Unipayment\Calculator\SchemePresentationCategory;
use PrestaShop\Module\Unipayment\Cart\CartContext;
use PrestaShop\Module\Unipayment\Cart\CartLine;
use PrestaShop\Module\Unipayment\Cart\CartPopupCalculator;
use PrestaShop\Module\Unipayment\Cart\CartSchemeResolver;
use PrestaShop\Module\Unipayment\Checkout\CartSnapshot;
use PrestaShop\Module\Unipayment\Checkout\CartSnapshotSigner;
use PrestaShop\Module\Unipayment\Checkout\CheckoutPaymentCalculator;
use PrestaShop\Module\Unipayment\Checkout\CheckoutPaymentPresenter;
use PrestaShop\Module\Unipayment\Checkout\CheckoutPaymentValidator;
use PrestaShop\Module\Unipayment\Checkout\CheckoutValidationException;
use PrestaShop\Module\Unipayment\Checkout\ConsentResolver;
use PrestaShop\Module\Unipayment\Checkout\CustomerFieldValidator;
use PrestaShop\Module\Unipayment\Calculator\CurrencyGate;
use PrestaShop\Module\Unipayment\Configuration\ShopSnapshotSanitizer;
use PrestaShop\Module\Unipayment\Product\ProductPopupCalculator;
use PrestaShop\Module\Unipayment\Product\ProductPopupSchemeList;

$count = 0;
function consentCheck(bool $ok, string $label): void {
    ++$GLOBALS['count'];
    if (!$ok) throw new RuntimeException($label);
}
function consentReject(callable $run, string $label): void {
    try { $run(); } catch (CheckoutValidationException $error) { consentCheck(true, $label); return; }
    consentCheck(false, $label);
}
$resolver = new ConsentResolver();
foreach ([0, '0', '0.00', 0.000009999, -0.000009999, 0.00001, -0.00001] as $glp) {
    consentCheck($resolver->interestState(['interestPercent' => $glp]) === 'zero', 'epsilon zero');
}
foreach ([0.000010001, -0.000010001, 0.0001, -20, '20'] as $glp) {
    consentCheck($resolver->interestState(['interestPercent' => $glp]) === 'nonzero', 'epsilon nonzero');
}
foreach ([null, '', 'bad', '0,0', true, false, [], new stdClass(), NAN, INF, -INF, '1e999', 'NaN'] as $glp) {
    consentCheck($resolver->interestState(['interestPercent' => $glp]) === 'unknown', 'invalid GLP');
}
consentCheck($resolver->interestState(null) === 'unknown' && $resolver->interestState([]) === 'unknown', 'missing GLP');
foreach ([null, '', 'unknown', 'ZERO_INTEREST', [], true, 1] as $scope) {
    consentCheck($resolver->normalizeScope($scope) === 'all', 'scope fallback');
}
$shop = calculatorFixture();
$shop['consents'] = [];
foreach (['all', 'zero_interest', 'nonzero_interest'] as $i => $scope) {
    foreach ([0, 1] as $mandatory) $shop['consents'][] = ['id' => 1 + $i * 2 + $mandatory, 'name' => $scope, 'mandatory' => $mandatory, 'interest_scope' => $scope];
}
$normal = $resolver->normalize($shop);
consentCheck(array_column($normal, 'mandatory') === array_column($normal, 'has_checkbox'), 'mandatory independent of scope');
$jsonShop = $shop; $jsonShop['consents'] = json_encode($shop['consents']);
consentCheck($resolver->normalize($jsonShop) === $normal, 'JSON compatibility');
consentCheck(ShopSnapshotSanitizer::sanitize($shop)['consents'] === $shop['consents'], 'cache sanitizer preservation');
$calculator = new Calculator('2026-08-17');
$product = new ProductContext(42, [7], 1000);
$cart = new CartContext([new CartLine($product, 0, 1, 1000)], 1000);
$cartResolver = new CartSchemeResolver($calculator);
$schemes = $calculator->availableSchemes($shop, $product, 'standard');
$standard = array_values(array_filter($schemes, static function ($s) { return $s->months === 12; }))[0];
$zero = $calculator->availableSchemes($shop, $product, 'promo')[0];
foreach ([[$standard, 'nonzero', [1,2,5,6], [2,6]], [$zero, 'zero', [1,2,3,4], [2,4]]] as [$scheme, $state, $applicable, $required]) {
    $calc = $calculator->calculateScheme($shop, 1000, $scheme);
    $selection = $resolver->selection($calc);
    $context = $resolver->context($shop, $selection);
    consentCheck($context['interest_state'] === $state && $context['applicable_ids'] === $applicable && $context['required_ids'] === $required, 'applicability matrix');
    consentCheck($resolver->validate($shop, array_merge($required, $required, [999]), $selection) === $required, 'duplicates/unknown IDs excluded');
    consentCheck($resolver->validate($shop, implode(',', $required), $selection) === $required, 'CSV accepted IDs');
    consentReject(static function () use ($resolver, $shop, $selection) { $resolver->validate($shop, [1,999,true,[2]], $selection); }, 'complex values cannot accept mandatory IDs');
    $result = (new ProductPopupCalculator($calculator))->calculate($shop, $product, 'EUR', 'standard', $scheme->type, $scheme->kopCode, $scheme->months, $scheme->filterId, ProductPopupSchemeList::key($scheme), 0);
    consentCheck($result['consent_context'] === $context, 'product server context');
    if ($scheme->type === 'standard') {
        $result = (new CartPopupCalculator($calculator, $cartResolver))->calculate($shop, $cart, 'EUR', 'standard', $scheme->type, $scheme->kopCode, $scheme->months, $scheme->filterId, ProductPopupSchemeList::key($scheme), 0);
        consentCheck($result['consent_context'] === $context, 'cart server context');
    }
}
consentCheck($resolver->parseAcceptedIds([true, false, [], [1], new stdClass(), 1.2, '2x', '1e2', -2, '2', 2]) === [2], 'strict accepted ID parser');
foreach ([null, 'bad', true, NAN, INF] as $value) {
    $invalid = $shop; $invalid['coeff_list'][1]['interestPercent'] = $value;
    consentReject(static function () use ($resolver, $invalid) { $resolver->validate($invalid, [2,4,6], ['kop_code'=>'STD','months'=>12]); }, 'unknown blocks scoped');
    $invalid['consents'] = [$shop['consents'][2]];
    consentReject(static function () use ($resolver, $invalid) { $resolver->validate($invalid, [], ['kop_code'=>'STD','months'=>12]); }, 'unknown blocks informational scope');
}
foreach ([0, -1, 'bad', INF] as $value) {
    $invalid = $shop; $invalid['coeff_list'][1]['coeff'] = $value;
    consentCheck(!$resolver->context($invalid, ['kop_code'=>'STD','months'=>12])['valid'], 'invalid coefficient unknown');
}
$legacy = ['consents'=>[['id'=>9,'name'=>'Legacy','mandatory'=>1]]];
consentCheck($resolver->validate($legacy,[9]) === [9], 'all-only unknown remains compatible');
consentCheck($resolver->validate(['consents'=>[]], [999]) === [], 'empty list');
$tiny = $shop; $tiny['coeff_list'][1]['interestPercent'] = 0.0001;
$tinyCalc = $calculator->calculateScheme($tiny, 1000, $calculator->availableSchemes($tiny, $product, 'standard')[1]);
consentCheck($tinyCalc->glp === 0.0 && $resolver->context($tiny, $resolver->selection($tinyCalc))['interest_state'] === 'nonzero', 'display GLP is ignored');
$sameKop = $shop; $sameKop['coeff_list'][1]['interestPercent'] = 0;
consentCheck($resolver->context($sameKop,['kop_code'=>'STD','months'=>6])['interest_state'] === 'nonzero' && $resolver->context($sameKop,['kop_code'=>'STD','months'=>12])['interest_state'] === 'zero', 'same KOP different months');
$schema = calculatorFixture(['uni_typekop'=>1, 'kop'=>['by_schema'=>['filters'=>schemaFiltersFixture()]]]);
$schema['consents'] = $shop['consents'];
$schemaScheme = $calculator->availableSchemes($schema, $product, 'standard')[0];
$schemaCalc = $calculator->calculateScheme($schema, 1000, $schemaScheme);
consentCheck($resolver->context($schema,$resolver->selection($schemaCalc))['interest_state']==='nonzero', 'schema/filter coefficient');
consentCheck(SchemePresentationCategory::classify($schemaScheme,$schema) === 'nonzero_promo', 'nonzero promotional presentation fixture');
$signer = new CartSnapshotSigner('test');
$snapshot = new CartSnapshot();
$validator = new CheckoutPaymentValidator($calculator,$cartResolver,new CurrencyGate(),$snapshot,$signer,new CustomerFieldValidator(),$resolver);
$posted = ['scheme_key'=>'12:0','kop_code'=>'STD','first_installment'=>0,'cart_snapshot'=>$signer->sign($snapshot->fingerprint($cart,'EUR')),'consent'=>[2,6],'interest'=>0,'required_ids'=>[],'interest_scope'=>'all'];
$customer = ['first_name'=>'Ivan','last_name'=>'Ivanov','address'=>'Sofia 1','phone'=>'+359888123','email'=>'ivan@example.test'];
$valid = $validator->validate($shop,$cart,'EUR',$posted,$customer);
consentCheck($valid->acceptedConsentIds === [2,6] && $valid->acceptedConsents[1]['interest_scope']==='nonzero_interest', 'checkout server acceptance snapshot');
$posted['consent']=[2,4];
consentReject(static function () use ($validator,$shop,$cart,$posted,$customer) { $validator->validate($shop,$cart,'EUR',$posted,$customer); }, 'browser flags cannot bypass');
$saved = ['kop_code'=>$zero->kopCode,'months'=>$zero->months,'filter_id'=>$zero->filterId,'scheme_type'=>$zero->type];
consentCheck($validator->validate($shop,$cart,'EUR',$posted,$customer,$saved)->acceptedConsentIds === [2,4], 'checkout validates persisted A despite POST B');
$view = (new CheckoutPaymentPresenter($calculator,$cartResolver,new CurrencyGate(),$snapshot,$signer,$resolver))->present(true,$shop,$cart,'EUR');
consentCheck(isset($view['schemes'][0]['consent_context']), 'initial checkout context');
$posted['consent']=[2,6];
$live = (new CheckoutPaymentCalculator($calculator,$cartResolver))->calculate($shop,$cart,'EUR',$posted);
consentCheck($live['consent_context']['required_ids'] === [2,6] && $live['monthly_installment'] === 95.0, 'checkout financial regression');
echo "OK consent scope {$count} assertions\n";
