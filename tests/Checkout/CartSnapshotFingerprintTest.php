<?php

declare(strict_types=1);

/**
 * Checkout / cart fingerprint determinism and drift coverage (Phase 9).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Calculator\ProductContext;
use PrestaShop\Module\Unipayment\Cart\CartContext;
use PrestaShop\Module\Unipayment\Cart\CartLine;
use PrestaShop\Module\Unipayment\Checkout\CartSnapshot;

function assertFp(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$snapshot = new CartSnapshot();

$stateA = new CartContext(
    [new CartLine(new ProductContext(1, [7], 100.0), 0, 1, 100.0)],
    100.0,
    ['id_cart' => 9, 'carrier_id' => 1, 'shipping_total' => '0.00', 'cart_rules' => [], 'delivery_option' => []]
);
$stateB = new CartContext(
    [new CartLine(new ProductContext(2, [7], 100.0), 0, 1, 100.0)],
    100.0,
    ['id_cart' => 9, 'carrier_id' => 1, 'shipping_total' => '0.00', 'cart_rules' => [], 'delivery_option' => []]
);
assertFp(
    $snapshot->fingerprint($stateA, 'BGN') !== $snapshot->fingerprint($stateB, 'BGN'),
    'same total different product must change fingerprint'
);

$qty1x200 = new CartContext(
    [new CartLine(new ProductContext(1, [], 200.0), 0, 1, 200.0)],
    200.0,
    ['id_cart' => 1, 'carrier_id' => 1, 'shipping_total' => '0.00', 'cart_rules' => []]
);
$qty2x100 = new CartContext(
    [new CartLine(new ProductContext(1, [], 200.0), 0, 2, 200.0)],
    200.0,
    ['id_cart' => 1, 'carrier_id' => 1, 'shipping_total' => '0.00', 'cart_rules' => []]
);
assertFp(
    $snapshot->fingerprint($qty1x200, 'BGN') !== $snapshot->fingerprint($qty2x100, 'BGN'),
    '1x200 vs 2x100 quantity composition must differ'
);

$comboA = new CartContext(
    [new CartLine(new ProductContext(5, [], 100.0), 1, 1, 100.0)],
    100.0,
    ['id_cart' => 1, 'carrier_id' => 1, 'shipping_total' => '0.00', 'cart_rules' => []]
);
$comboB = new CartContext(
    [new CartLine(new ProductContext(5, [], 100.0), 2, 1, 100.0)],
    100.0,
    ['id_cart' => 1, 'carrier_id' => 1, 'shipping_total' => '0.00', 'cart_rules' => []]
);
assertFp(
    $snapshot->fingerprint($comboA, 'EUR') !== $snapshot->fingerprint($comboB, 'EUR'),
    'different combination must change fingerprint'
);

$carrierA = new CartContext(
    [new CartLine(new ProductContext(1, [], 1000.0), 0, 1, 1000.0)],
    1005.0,
    ['id_cart' => 1, 'carrier_id' => 10, 'shipping_total' => '5.00', 'cart_rules' => []]
);
$carrierB = new CartContext(
    [new CartLine(new ProductContext(1, [], 1000.0), 0, 1, 1000.0)],
    1015.0,
    ['id_cart' => 1, 'carrier_id' => 11, 'shipping_total' => '15.00', 'cart_rules' => []]
);
assertFp(
    $snapshot->fingerprint($carrierA, 'BGN') !== $snapshot->fingerprint($carrierB, 'BGN'),
    'carrier/shipping change must change fingerprint'
);

$sameShippingDifferentCarrier = new CartContext(
    [new CartLine(new ProductContext(1, [], 1000.0), 0, 1, 1000.0)],
    1005.0,
    ['id_cart' => 1, 'carrier_id' => 10, 'shipping_total' => '5.00', 'cart_rules' => []]
);
$sameShippingOtherCarrier = new CartContext(
    [new CartLine(new ProductContext(1, [], 1000.0), 0, 1, 1000.0)],
    1005.0,
    ['id_cart' => 1, 'carrier_id' => 99, 'shipping_total' => '5.00', 'cart_rules' => []]
);
assertFp(
    $snapshot->fingerprint($sameShippingDifferentCarrier, 'BGN')
        !== $snapshot->fingerprint($sameShippingOtherCarrier, 'BGN'),
    'carrier_id alone must change fingerprint even when shipping total equal'
);

$voucherA = new CartContext(
    [new CartLine(new ProductContext(1, [], 900.0), 0, 1, 1000.0)],
    900.0,
    ['id_cart' => 1, 'carrier_id' => 1, 'shipping_total' => '0.00', 'cart_rules' => [
        ['id_cart_rule' => 7, 'value_real' => '100.00', 'free_shipping' => 0],
    ]]
);
$voucherB = new CartContext(
    [new CartLine(new ProductContext(1, [], 900.0), 0, 1, 1000.0)],
    900.0,
    ['id_cart' => 1, 'carrier_id' => 1, 'shipping_total' => '0.00', 'cart_rules' => [
        ['id_cart_rule' => 8, 'value_real' => '100.00', 'free_shipping' => 0],
    ]]
);
assertFp(
    $snapshot->fingerprint($voucherA, 'BGN') !== $snapshot->fingerprint($voucherB, 'BGN'),
    'different cart-rule identity must change fingerprint'
);

$unordered = new CartContext(
    [
        new CartLine(new ProductContext(2, [], 1000.0), 0, 1, 600.0),
        new CartLine(new ProductContext(1, [], 1000.0), 0, 1, 400.0),
    ],
    1000.0,
    ['shipping_total' => '0.00', 'carrier_id' => 1, 'id_cart' => 3, 'cart_rules' => [
        ['id_cart_rule' => 2, 'value_real' => '10.00', 'free_shipping' => 0],
        ['id_cart_rule' => 1, 'value_real' => '5.00', 'free_shipping' => 0],
    ]]
);
$ordered = new CartContext(
    [
        new CartLine(new ProductContext(1, [], 1000.0), 0, 1, 400.0),
        new CartLine(new ProductContext(2, [], 1000.0), 0, 1, 600.0),
    ],
    1000.0,
    ['id_cart' => 3, 'carrier_id' => 1, 'shipping_total' => '0.00', 'cart_rules' => [
        ['id_cart_rule' => 1, 'value_real' => '5.00', 'free_shipping' => 0],
        ['id_cart_rule' => 2, 'value_real' => '10.00', 'free_shipping' => 0],
    ]]
);
assertFp(
    $snapshot->fingerprint($unordered, 'BGN') === $snapshot->fingerprint($ordered, 'BGN'),
    'deterministic sorting must normalize line/cart_rule order'
);

assertFp(
    $snapshot->fingerprint($stateA, 'BGN') !== $snapshot->fingerprint($stateA, 'EUR'),
    'currency must be part of fingerprint'
);

fwrite(STDOUT, "OK (Phase 9 checkout fingerprint)\n");
