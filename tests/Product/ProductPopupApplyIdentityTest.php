<?php

declare(strict_types=1);

/**
 * Popup apply identity, consent and Process 2 customer allowlist.
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

    public function __construct(int $id = 0)
    {
        $this->id = $id;
    }

    public function getAddresses(int $idLang): array
    {
        return [];
    }

    public function isLogged(): bool
    {
        return $this->id > 0;
    }
}

final class Validate
{
    public static function isLoadedObject(object $object): bool
    {
        return $object->id > 0;
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
$expectedCustomer = array_diff_key($posted, ['consent' => true]);
foreach ([false, true] as $loggedIn) {
    $context->customer = new Customer();
    $context->customer->id = $loggedIn ? 7 : 0;
    foreach ([[], ['egn' => 'legacy-marker', 'phone2' => ['legacy-marker']]] as $legacyKeys) {
        $process2 = $service->accept($process2Shop, $posted + $legacyKeys, $context);
        assertApplyId($process2['customer'] === $expectedCustomer, 'Process 2 returns only supported customer fields');
        assertApplyId($process2['identity']['is_logged'] === $loggedIn, 'Process 2 preserves authenticated or guest identity');
    }
}

fwrite(STDOUT, "OK (product popup apply identity)\n");
