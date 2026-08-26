<?php

declare(strict_types=1);

/**
 * Cart guest twin-order regression contracts (package/delivery sync + empty-line guard).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertTwin(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$cartApply = (string) file_get_contents($root . '/src/Cart/CartPopupApplyService.php');
$gateway = (string) file_get_contents($root . '/src/Order/NativePrestaShopOrderGateway.php');
$orchestrator = (string) file_get_contents($root . '/src/Order/OrderOrchestrator.php');
$sync = (string) file_get_contents($root . '/src/Order/CartShippingStateSynchronizer.php');
$resolver = (string) file_get_contents($root . '/src/Order/AuthoritativeOrderResolver.php');
$productApply = (string) file_get_contents($root . '/src/Product/ProductPopupApplyService.php');

assertTwin(strpos($cartApply, 'CartShippingStateSynchronizer') !== false, 'A: cart apply synchronizes shipping after guest mutation');
assertTwin(strpos($sync, 'resetStaticCache') !== false, 'A: shipping sync clears Cart static caches');
assertTwin(strpos($sync, 'delivery_option') !== false, 'A: shipping sync resets delivery_option');
assertTwin(strpos($sync, 'setDeliveryOption') !== false, 'A: shipping sync recomputes delivery option for current address');
assertTwin(strpos($sync, 'getPackageList(true)') !== false, 'A: shipping sync flushes package list');

assertTwin(strpos($gateway, 'AuthoritativeOrderResolver') !== false, 'D: gateway uses authoritative resolver');
assertTwin(strpos($gateway, 'listOrderIdsForCart') !== false, 'D: gateway enumerates same-cart candidates');
assertTwin(strpos($gateway, 'currentOrder') !== false, 'gateway still reads currentOrder as preferred hint');
assertTwin(
    (bool) preg_match('/resolveCreatedOrder\s*\(\s*\$idCart\s*,\s*\(int\)\s*\$this->module->currentOrder\s*\)/', $gateway),
    'D: create path resolves authoritative order after validateOrder'
);

assertTwin(strpos($resolver, 'never bind') !== false || strpos($resolver, 'empty twin') !== false, 'resolver documents empty-twin invariant');
assertTwin(strpos($resolver, 'Multiple non-empty') !== false, 'E: ambiguous fail-closed message');

assertTwin(strpos($orchestrator, 'EmptyOrderLines') !== false, 'F: orchestrator empty-line guard');
assertTwin(substr_count($orchestrator, 'EmptyOrderLines') >= 2, 'F: empty-line guard on create and pre-CP paths');
assertTwin(
    (bool) preg_match('/lines === \[\][\s\S]{0,200}EmptyOrderLines[\s\S]{0,400}submitToControlPanel/s', $orchestrator)
    || (bool) preg_match('/EmptyOrderLines[\s\S]{0,800}submitToControlPanel/s', $orchestrator),
    'F: empty lines block CP submit'
);

// B/C: logged-in cart + guest product paths unchanged in structure
assertTwin(strpos($cartApply, 'shouldUseAuthenticatedCustomer') !== false, 'B: logged-in cart branch retained');
assertTwin(strpos($productApply, 'createFreshCart') !== false, 'C: guest product still uses fresh cart');
assertTwin(strpos($productApply, 'CartShippingStateSynchronizer') === false, 'C: product path not forced through cart shipping sync');

// H: replay / existing order recovery must not validateOrder again when authoritative exists
assertTwin(strpos($gateway, 'findExistingAuthoritativeOrderId') !== false, 'H: existing authoritative order recovered before validateOrder');

fwrite(STDOUT, "OK (cart guest twin-order regression contracts)\n");
