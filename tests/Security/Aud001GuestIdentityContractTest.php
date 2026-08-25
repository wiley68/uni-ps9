<?php

declare(strict_types=1);

/**
 * AUD-001: guest identity is never selected by e-mail lookup.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertAud001(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$guest = (string) file_get_contents($root . '/src/Product/GuestCustomerFactory.php');
$gate = (string) file_get_contents($root . '/src/Product/PopupCustomerIdentityGate.php');
$binding = (string) file_get_contents($root . '/src/Product/PopupSubmissionBindingFactory.php');
$apply = (string) file_get_contents($root . '/src/Product/ProductPopupApplyIdentityService.php');
$controller = (string) file_get_contents($root . '/controllers/front/productpopup.php');

assertAud001(strpos($guest, 'customerExists') === false, 'must not look up Customer by email');
assertAud001(strpos($guest, 'getByEmail') === false, 'must not call Customer::getByEmail');
assertAud001(strpos($guest, 'is_guest = 1') !== false, 'anonymous apply creates is_guest=1');
assertAud001(strpos($guest, 'random_bytes') !== false, 'guest password must use CSPRNG, not mt_rand');
assertAud001(strpos($gate, 'isLogged()') !== false, 'identity gate trusts isLogged()');
assertAud001(strpos($binding, 'Tools::getValue') === false, 'binding must not read identity from POST');
assertAud001(strpos($binding, 'cookie->id_guest') !== false, 'guest identity from cookie');
assertAud001(strpos($binding, 'isLogged()') !== false, 'customer identity from Context login');
assertAud001(strpos($apply, 'GuestCustomerFactory') === false, 'Phase 7 apply must not create guests');
assertAud001(strpos($controller, 'ensure(') === false, 'controller must not create guest customers in Phase 7');

fwrite(STDOUT, "OK (AUD-001 guest identity contract)\n");
