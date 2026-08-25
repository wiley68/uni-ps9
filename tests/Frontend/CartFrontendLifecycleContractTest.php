<?php

declare(strict_types=1);

/**
 * Cart FO lifecycle / asset / no-jQuery contract (Hummingbird + Classic).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertCartFrontend(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$module = (string) file_get_contents($root . '/unipayment.php');
$jsCart = (string) file_get_contents($root . '/views/js/cart-calculator.js');
$jsProduct = (string) file_get_contents($root . '/views/js/product-calculator.js');
$css = (string) file_get_contents($root . '/views/css/cart-calculator.css');

assertCartFrontend((bool) preg_match('/function\s+hookDisplayShoppingCart\b/', $module), 'displayShoppingCart handler');
assertCartFrontend(strpos($module, "php_self === 'cart'") !== false, 'cart asset gate');
assertCartFrontend(strpos($module, 'registerFrontOfficeHooks') !== false, 'idempotent FO hook registration');
assertCartFrontend(strpos($jsCart, 'updatedCart') !== false, 'listen to updatedCart after AJAX cart refresh');
assertCartFrontend(strpos($jsCart, 'isConnected') !== false, 'ignore detached roots');
assertCartFrontend(strpos($jsCart, 'AbortController') !== false, 'abort stale cart refresh');
assertCartFrontend(strpos($jsCart, 'refreshSequence') !== false, 'sequence guard for stale responses');
assertCartFrontend(strpos($jsCart, 'unipaymentInvalidatePopup') !== false, 'invalidate popup token/state on cart change');
assertCartFrontend(!preg_match('/\$\s*\(|\bjQuery\b/', $jsCart), 'no jQuery in cart JS');
assertCartFrontend(strpos($jsProduct, 'isCartSource') !== false, 'shared popup JS detects cart');
assertCartFrontend(
    !preg_match('/if\s*\(\s*isCartSource\s*\)\s*\{\s*return Promise\.resolve\(""\)/', $jsProduct),
    'cart source must issue Phase 7 submission tokens'
);
assertCartFrontend(strpos($css, '.unipayment-cart-calculator') !== false, 'cart CSS scope');

// Theme event names verified against installed Hummingbird 2.0 / Classic core:
// updateCart → AJAX → updatedCart (themes/core.js + theme cart handlers).
$eventsMap = '/var/www/presta9.avalonbg.com/themes/hummingbird/src/js/constants/events-map.ts';
if (is_file($eventsMap)) {
    $map = (string) file_get_contents($eventsMap);
    assertCartFrontend(strpos($map, "updatedCart: 'updatedCart'") !== false, 'Hummingbird events-map exposes updatedCart');
    assertCartFrontend(strpos($map, "updateCart: 'updateCart'") !== false, 'Hummingbird events-map exposes updateCart');
}

fwrite(STDOUT, "OK (Phase 8 cart frontend lifecycle)\n");
