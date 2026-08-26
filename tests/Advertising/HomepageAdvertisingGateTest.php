<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Advertising\HomepageAdvertisingGate;

function assertAdvertisingGate(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$gate = new HomepageAdvertisingGate();

assertAdvertisingGate($gate->allowsPage('index'), 'PrestaShop homepage php_self=index must be allowed');
assertAdvertisingGate(!$gate->allowsPage('product'), 'product page must not load homepage advertising');
assertAdvertisingGate(!$gate->allowsPage('cart'), 'cart page must not load homepage advertising');
assertAdvertisingGate(!$gate->allowsPage('order'), 'checkout must not load homepage advertising');
assertAdvertisingGate(!$gate->allowsPage('category'), 'category listing must not load homepage advertising');

assertAdvertisingGate(
    $gate->allowsLocalSettings(true, true, true, 'SHOP-1'),
    'enabled module + advertising flag + UNICID must pass the local settings gate'
);
assertAdvertisingGate(
    !$gate->allowsLocalSettings(false, true, true, 'SHOP-1'),
    'inactive module must not enqueue homepage advertising'
);
assertAdvertisingGate(
    !$gate->allowsLocalSettings(true, false, true, 'SHOP-1'),
    'disabled UNIPAYMENT_ENABLED must not enqueue homepage advertising'
);
assertAdvertisingGate(
    !$gate->allowsLocalSettings(true, true, false, 'SHOP-1'),
    'UNIPAYMENT_ADVERTISING_ENABLED=0 must not enqueue homepage advertising'
);
assertAdvertisingGate(
    !$gate->allowsLocalSettings(true, true, true, ''),
    'missing UNICID must not enqueue homepage advertising'
);
assertAdvertisingGate(
    !$gate->allowsLocalSettings(true, true, true, '   '),
    'whitespace UNICID must not enqueue homepage advertising'
);

assertAdvertisingGate(
    $gate->allowsAssets('index', true, true, true, 'SHOP-1'),
    'homepage + local settings must allow asset enqueue'
);
assertAdvertisingGate(
    !$gate->allowsAssets('product', true, true, true, 'SHOP-1'),
    'valid settings on a non-home page must still skip advertising assets'
);
assertAdvertisingGate(
    !$gate->allowsAssets('index', true, true, false, 'SHOP-1'),
    'homepage with advertising disabled must skip advertising assets'
);

assertAdvertisingGate($gate->allowsShop(['uni_status' => 1, 'uni_container_status' => 1]), 'active CP shop + container must render');
assertAdvertisingGate($gate->allowsShop(['uni_status' => 'Yes', 'uni_container_status' => '1']), 'Woo yes-flag values must be accepted');
assertAdvertisingGate(!$gate->allowsShop(['uni_status' => 0, 'uni_container_status' => 1]), 'inactive CP shop must hide advertising');
assertAdvertisingGate(!$gate->allowsShop(['uni_status' => 1, 'uni_container_status' => 0]), 'disabled CP container must hide advertising');
assertAdvertisingGate(!$gate->allowsShop([]), 'missing CP flags must hide advertising');

fwrite(STDOUT, "OK (Homepage advertising permission gates)\n");
