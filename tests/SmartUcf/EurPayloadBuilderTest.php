<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Order\OrderCurrencyGuard;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfPayloadBuilder;

function assertEurPayload(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}
function rejectsEurPayload(SmartUcfPayloadBuilder $builder, array $shop, array $snapshot): bool
{
    try {
        $builder->build($shop, $snapshot);
    } catch (RuntimeException $exception) {
        return true;
    }
    return false;
}
$native = ['id_currency' => 1, 'currency_iso' => 'EUR'];
$guard = new OrderCurrencyGuard(static function (int $idOrder) use (&$native): array { return $native; });
$builder = new SmartUcfPayloadBuilder($guard);
$shop = ['uni_user' => 'test-user', 'uni_password' => 'test-password'];
$snapshot = [
    'id_order' => 55, 'id_currency' => 1, 'currency_iso' => 'EUR',
    'order_reference' => 'EURORDER1', 'kop_code' => 'KOP', 'order_total' => 100.0,
    'first_installment' => 0.0, 'months' => 10, 'monthly_installment' => 10.0,
    'lines_json' => [['id_product' => 7, 'name' => 'Item', 'quantity' => 2, 'total' => 100.0]],
];
$payload = $builder->build($shop, $snapshot);
assertEurPayload($payload['items'][0]['singlePrice'] === '50.00' && $payload['totalPrice'] === '100.00',
    'EUR line amount changed or fixed FX was applied');
foreach ([['currency_iso' => 'BGN'], ['currency_iso' => ''], ['id_currency' => 2], ['id_order' => 0]] as $badSnapshot) {
    assertEurPayload(rejectsEurPayload($builder, $shop, array_replace($snapshot, $badSnapshot)),
        'direct builder accepted invalid durable provenance');
}
$native = ['id_currency' => 2, 'currency_iso' => 'BGN'];
assertEurPayload(rejectsEurPayload($builder, $shop, $snapshot), 'direct builder accepted old native BGN order');
