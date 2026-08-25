<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Configuration\AdminConfigurationRequestReader;

function assertReader(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$_GET = ['submitUnipaymentRefresh' => '1'];
$_POST = [];
$_REQUEST = $_GET;
$reader = new AdminConfigurationRequestReader();
assertReader(!$reader->isBankRefreshSubmit(), 'GET-only refresh must not count as bank refresh submit');
assertReader(!$reader->isConfigurationSubmit(), 'GET must not count as configuration submit');

$_POST = [
    'submitUnipaymentConfiguration' => '1',
    'UNIPAYMENT_UNICID' => '123e4567-e89b-12d3-a456-426614174000',
    'UNIPAYMENT_SECRET' => 'posted-secret-value',
];
$_GET = ['submitUnipaymentRefresh' => 'stale'];
$_REQUEST = array_merge($_GET, $_POST);
$reader = new AdminConfigurationRequestReader();
assertReader($reader->isConfigurationSubmit(), 'POST configuration submit must be detected');
assertReader(
    $reader->isBankRefreshSubmit() === false
        || array_key_exists('submitUnipaymentRefresh', $_POST),
    'refresh detection must not use GET alone when checking isolation'
);
// Explicit: GET stale refresh flag must not make isBankRefreshSubmit true when only in GET
assertReader(!$reader->isBankRefreshSubmit(), 'stale GET refresh flag must be ignored');
assertReader($reader->hasSecretField(), 'SECRET field must be detected in POST');
assertReader($reader->secretInPost(), 'SECRET must be present in $_POST');
assertReader($reader->getSecret() === 'posted-secret-value', 'SECRET must be read from $_POST without Tools mutation path');
assertReader(strlen($reader->getSecret()) === 19, 'SECRET length must match posted value');

$_POST = [
    'submitUnipaymentConfiguration' => '1',
    'UNIPAYMENT_UNICID' => '123e4567-e89b-12d3-a456-426614174000',
    'UNIPAYMENT_SECRET' => '',
];
$reader = new AdminConfigurationRequestReader();
assertReader($reader->hasSecretField(), 'empty SECRET field is still present');
assertReader($reader->getSecret() === '', 'blank SECRET must be empty string');

fwrite(STDOUT, "OK (AdminConfigurationRequestReader POST-only and SECRET sources)\n");
