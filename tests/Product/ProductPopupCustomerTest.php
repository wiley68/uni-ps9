<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Product\ProductPopupCustomerPrefill;
use PrestaShop\Module\Unipayment\Product\ProductPopupCustomerValidator;
use PrestaShop\Module\Unipayment\Product\ProductPopupValidationException;

function assertProductCustomer(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$prefill = new ProductPopupCustomerPrefill();
$customer = ['firstname' => 'Customer', 'lastname' => 'Name', 'email' => 'customer@example.test'];
$addresses = [
    ['id_address' => 10, 'firstname' => 'Invoice', 'lastname' => 'Person', 'address1' => 'Invoice 1', 'city' => 'Sofia', 'postcode' => '1000', 'phone_mobile' => '', 'phone' => '111'],
    ['id_address' => 11, 'firstname' => 'Delivery', 'lastname' => 'Person', 'address1' => 'Delivery 2', 'address2' => 'Floor 3', 'city' => 'Plovdiv', 'postcode' => '4000', 'phone_mobile' => '+359 888 123', 'phone' => '222'],
];
$logged = $prefill->present(true, $customer, $addresses, 11, 10);
assertProductCustomer($logged['first_name'] === 'Delivery' && $logged['last_name'] === 'Person', 'delivery-address customer names were not preferred');
assertProductCustomer($logged['address'] === 'Delivery 2, Floor 3, Plovdiv, 4000', 'native address fields were not formatted with Woo semantics');
assertProductCustomer($logged['phone'] === '+359 888 123', 'phone_mobile was not preferred');
assertProductCustomer($logged['email'] === 'customer@example.test' && $logged['is_logged'], 'logged-in email/is_logged prefill failed');

$phoneFallback = $prefill->present(true, $customer, [$addresses[0]], 10, 0);
assertProductCustomer($phoneFallback['phone'] === '111', 'phone fallback failed');
$withoutAddress = $prefill->present(true, $customer, [], 0, 0);
assertProductCustomer($withoutAddress['first_name'] === 'Customer' && $withoutAddress['address'] === '' && $withoutAddress['phone'] === '', 'logged-in customer without address must not receive invented address data');
$guest = $prefill->present(false, $customer, $addresses, 11, 10);
assertProductCustomer($guest === ['first_name' => '', 'last_name' => '', 'address' => '', 'phone' => '', 'email' => '', 'is_logged' => false], 'guest popup fields must be empty');

$validator = new ProductPopupCustomerValidator();
$valid = $validator->validate(['first_name' => ' Иван ', 'last_name' => ' Иванов ', 'address' => ' София ', 'phone' => '+359 (88) 123-45', 'email' => 'ivan@example.test']);
assertProductCustomer($valid['first_name'] === 'Иван' && $valid['phone'] === '+359 (88) 123-45', 'valid customer data was not trimmed/sanitized');
assertProductCustomer($validator->validName('Иван') && $validator->validName('Ivan'), 'Bulgarian/Latin first names must be accepted');
assertProductCustomer($validator->validName('Иванов') && $validator->validName('Petrov'), 'Bulgarian/Latin last names must be accepted');

foreach (
    [
        ['input' => ['first_name' => '', 'last_name' => 'B', 'address' => 'C', 'phone' => '123', 'email' => 'a@b.test'], 'field' => 'first_name'],
        ['input' => ['first_name' => 'A', 'last_name' => 'B', 'address' => 'C', 'phone' => '---', 'email' => 'a@b.test'], 'field' => 'phone'],
        ['input' => ['first_name' => 'A', 'last_name' => 'B', 'address' => 'C', 'phone' => '123', 'email' => 'invalid'], 'field' => 'email'],
        ['input' => ['first_name' => 'Иван1', 'last_name' => 'Иванов', 'address' => 'София', 'phone' => '0888123456', 'email' => 'a@b.test'], 'field' => 'first_name', 'message' => 'Името може да съдържа само букви, интервал, тире и апостроф.'],
        ['input' => ['first_name' => 'Иван', 'last_name' => 'Иванов2', 'address' => 'София', 'phone' => '0888123456', 'email' => 'a@b.test'], 'field' => 'last_name', 'message' => 'Фамилията може да съдържа само букви, интервал, тире и апостроф.'],
    ] as $case
) {
    try {
        $validator->validate($case['input']);
        assertProductCustomer(false, 'invalid customer payload was accepted');
    } catch (ProductPopupValidationException $exception) {
        assertProductCustomer(isset($exception->errors()[$case['field']]), 'expected field validation error is missing');
        if (isset($case['message'])) {
            assertProductCustomer(
                $exception->errors()[$case['field']] === $case['message'],
                'expected customer-safe field message for ' . $case['field']
            );
        }
    }
}

assertProductCustomer(!$validator->validName('Иван1'), 'digit in first_name must fail isName parity');
assertProductCustomer(!$validator->validName('Иванов2'), 'digit in last_name must fail isName parity');

$process2Base = [
    'first_name' => 'Иван',
    'last_name' => 'Иванов',
    'address' => 'София',
    'phone' => '+359888123456',
    'email' => 'ivan@example.test',
];
try {
    $validator->validate($process2Base, true);
    assertProductCustomer(false, 'Process 2 payload without EGN and phone2 was accepted');
} catch (ProductPopupValidationException $exception) {
    assertProductCustomer(isset($exception->errors()['egn']) && isset($exception->errors()['phone2']), 'Process 2 must require EGN and secondary phone');
}

try {
    $validator->validate($process2Base + ['egn' => '1990010199', 'phone2' => '---'], true);
    assertProductCustomer(false, 'invalid Process 2 secondary phone was accepted');
} catch (ProductPopupValidationException $exception) {
    assertProductCustomer(isset($exception->errors()['phone2']), 'invalid phone2 error missing');
}

$process2 = $validator->validate($process2Base + ['egn' => '1990010199', 'phone2' => '+359 2 123 456'], true);
assertProductCustomer($process2['egn'] === '1990010199' && $process2['phone2'] === '+359 2 123 456', 'valid Process 2 EGN and secondary phone were not kept');
assertProductCustomer(!isset($valid['egn']) && !isset($valid['phone2']), 'Process 1 must not require EGN or secondary phone');

fwrite(STDOUT, "OK (Product popup Step 2 customer prefill and validation)\n");
