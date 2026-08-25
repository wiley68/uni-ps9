<?php

declare(strict_types=1);

/**
 * Phase 7 apply identity: consents, EGN stripped from result, Process 2 required fields.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

final class Context
{
    /** @var object */
    public $cookie;
    /** @var object|null */
    public $customer;
    /** @var object */
    public $language;
    /** @var object|null */
    public $cart;
}

final class Customer
{
    public int $id = 0;

    public function isLogged(): bool
    {
        return $this->id > 0;
    }
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Product\ProductPopupApplyIdentityService;
use PrestaShop\Module\Unipayment\Product\ProductPopupValidationException;

function assertApplyId(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$posted = [
    'first_name' => 'Иван',
    'last_name' => 'Иванов',
    'address' => 'София',
    'phone' => '0888123456',
    'email' => 'ivan@example.test',
    'consent' => [1],
];
$shop = [
    'uni_proces' => 0,
    'consents' => [
        ['id' => 1, 'name' => 'Съгласие', 'mandatory' => 1, 'url' => ''],
    ],
];

$context = new Context();
$context->cookie = (object) ['id_guest' => 42];
$context->customer = null;
$context->language = (object) ['id' => 1];
$context->cart = null;

$service = new ProductPopupApplyIdentityService();
$result = $service->accept($shop, $posted, $context);
assertApplyId($result['identity']['is_logged'] === false, 'guest is not logged-in');
assertApplyId($result['identity']['id_guest'] === 42, 'guest id from cookie');
assertApplyId($result['identity']['id_customer'] === 0, 'guest has no customer id');
assertApplyId(!isset($result['customer']['egn']), 'EGN never returned');

try {
    $service->accept($shop, array_merge($posted, ['consent' => []]), $context);
    assertApplyId(false, 'missing consents must fail');
} catch (ProductPopupValidationException $exception) {
    assertApplyId(isset($exception->errors()['consents']), 'consent error present');
}

$process2Shop = array_merge($shop, ['uni_proces' => 1]);
try {
    $service->accept($process2Shop, $posted, $context);
    assertApplyId(false, 'Process 2 without EGN must fail');
} catch (ProductPopupValidationException $exception) {
    assertApplyId(isset($exception->errors()['egn']), 'Process 2 requires EGN');
}

$process2Posted = array_merge($posted, ['egn' => '1990010199', 'phone2' => '0888000000']);
$process2 = $service->accept($process2Shop, $process2Posted, $context);
assertApplyId(!isset($process2['customer']['egn']), 'EGN stripped from Process 2 response');
assertApplyId(($process2['customer']['phone2'] ?? '') === '0888000000', 'phone2 kept after validation');

fwrite(STDOUT, "OK (product popup apply identity)\n");
