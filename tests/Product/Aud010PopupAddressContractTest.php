<?php

declare(strict_types=1);

/**
 * AUD-010 / preferred address + ownership contract (no live Address mutation).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Product\PopupOrderAddressResolver;
use PrestaShop\Module\Unipayment\Product\PopupPreferredAddressSelector;

function assertAud010(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$selector = new PopupPreferredAddressSelector();
$addresses = [
    ['id_address' => 10, 'firstname' => 'A', 'address1' => 'Home 1', 'city' => 'Sofia', 'postcode' => '1000', 'address2' => ''],
    ['id_address' => 20, 'firstname' => 'B', 'address1' => 'Office 2', 'city' => 'Plovdiv', 'postcode' => '4000', 'address2' => ''],
];
assertAud010((int) ($selector->select($addresses, 20, 10)['id_address'] ?? 0) === 20, 'prefer delivery');
assertAud010((int) ($selector->select($addresses, 0, 20)['id_address'] ?? 0) === 20, 'prefer invoice');
assertAud010((int) ($selector->select($addresses, 0, 0)['id_address'] ?? 0) === 10, 'fallback first');
assertAud010(
    $selector->joinAddress($addresses[1]) === 'Office 2, Plovdiv, 4000',
    'join address for prefill/match'
);
assertAud010($selector->select([], 1, 2) === [], 'no addresses yields empty');

$apply = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Product/ProductPopupApplyIdentityService.php');
assertAud010(strpos($apply, 'getFirstCustomerAddressId') === false, 'apply identity does not use first address only');
assertAud010(strpos($apply, 'PopupOrderAddressResolver') !== false, 'apply identity uses resolver');
assertAud010(strpos($apply, 'assertOwnedActiveAddress') !== false, 'foreign id_address is ownership-checked');
assertAud010(strpos($apply, 'GuestCustomerFactory') === false, 'Phase 7 does not create guests');

$resolverSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Product/PopupOrderAddressResolver.php');
assertAud010(strpos($resolverSrc, 'Never mutates') !== false, 'resolver documents no mutation');
assertAud010(strpos($resolverSrc, 'ALIAS_BASE') !== false, 'alias policy present');
assertAud010(strpos($resolverSrc, 'id_customer === $customerId') !== false, 'ownership check present');
assertAud010(strpos($resolverSrc, 'deleted') !== false, 'deleted addresses rejected');
assertAud010(
    PopupOrderAddressResolver::effectiveContactPhone('0888', '02') === '0888',
    'phone_mobile preferred'
);
assertAud010(
    PopupOrderAddressResolver::effectiveContactPhone('', '02') === '02',
    'phone fallback'
);

fwrite(STDOUT, "OK (AUD-010 popup address contract)\n");
