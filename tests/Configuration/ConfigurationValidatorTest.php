<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use PrestaShop\Module\Unipayment\Configuration\ConfigurationValidator;

$validator = new ConfigurationValidator();
$validUnicid = '123e4567-e89b-12d3-a456-426614174000';

$cases = [
    'accepts initial valid credentials' => [
        [],
        $validator->validate($validUnicid, 'secret', false),
    ],
    'requires initial credentials' => [
        [ConfigurationValidator::ERROR_UNICID_REQUIRED, ConfigurationValidator::ERROR_SECRET_REQUIRED],
        $validator->validate('', '', false),
    ],
    'requires unicid when missing' => [
        [ConfigurationValidator::ERROR_UNICID_REQUIRED],
        $validator->validate('', 'secret', false),
    ],
    'keeps an existing secret when input is empty' => [
        [],
        $validator->validate($validUnicid, '', true),
    ],
    'rejects an invalid unicid' => [
        [ConfigurationValidator::ERROR_UNICID_INVALID],
        $validator->validate('not-a-uuid', 'secret', false),
    ],
    'rejects an oversized unicid' => [
        [ConfigurationValidator::ERROR_UNICID_INVALID],
        $validator->validate($validUnicid . 'x', 'secret', false),
    ],
    'rejects an oversized secret' => [
        [ConfigurationValidator::ERROR_SECRET_TOO_LONG],
        $validator->validate($validUnicid, str_repeat('x', 65), false),
    ],
    'accepts add to cart and zero spacing' => [
        [],
        $validator->validate($validUnicid, 'secret', false, 'add_to_cart', '0'),
    ],
    'accepts buy and positive spacing' => [
        [],
        $validator->validate($validUnicid, 'secret', false, 'buy', '24'),
    ],
    'accepts spacing at upper boundary' => [
        [],
        $validator->validate($validUnicid, 'secret', false, 'buy', '200'),
    ],
    'rejects an invalid button action' => [
        [ConfigurationValidator::ERROR_BUTTON_ACTION_INVALID],
        $validator->validate($validUnicid, 'secret', false, 'checkout', '0'),
    ],
    'rejects negative spacing' => [
        [ConfigurationValidator::ERROR_BUTTON_TOP_SPACING_INVALID],
        $validator->validate($validUnicid, 'secret', false, 'add_to_cart', '-1'),
    ],
    'rejects non-integer spacing' => [
        [ConfigurationValidator::ERROR_BUTTON_TOP_SPACING_INVALID],
        $validator->validate($validUnicid, 'secret', false, 'add_to_cart', '12px'),
    ],
    'rejects spacing above limit' => [
        [ConfigurationValidator::ERROR_BUTTON_TOP_SPACING_INVALID],
        $validator->validate($validUnicid, 'secret', false, 'add_to_cart', '201'),
    ],
];

foreach ($cases as $name => [$expected, $actual]) {
    if ($expected !== $actual) {
        fwrite(STDERR, sprintf("FAIL: %s\nExpected: %s\nActual: %s\n", $name, json_encode($expected), json_encode($actual)));
        exit(1);
    }
}

fwrite(STDOUT, sprintf("OK (%d configuration validation cases)\n", count($cases)));
