<?php

declare(strict_types=1);

/**
 * Cart apply double-click / replay via shared ProductPopupOperationGuard.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}
if (!defined('_MYSQL_ENGINE_')) {
    define('_MYSQL_ENGINE_', 'InnoDB');
}
if (!function_exists('pSQL')) {
    function pSQL(string $string, bool $htmlOK = false): string
    {
        unset($htmlOK);

        return addslashes($string);
    }
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/Support/FakePopupDb.php';

use PrestaShop\Module\Unipayment\Product\PopupSubmissionBindingFactory;
use PrestaShop\Module\Unipayment\Product\PopupSubmissionRepository;
use PrestaShop\Module\Unipayment\Product\PopupSubmissionSelectionHash;
use PrestaShop\Module\Unipayment\Product\ProductPopupOperationGuard;
use PrestaShop\Module\Unipayment\Tests\Support\FakePopupDb;

final class Context
{
    /** @var object */
    public $cookie;
    /** @var object|null */
    public $customer;
    /** @var object */
    public $shop;
}

function assertCartReplay(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$ctx = new Context();
$ctx->cookie = (object) ['id_guest' => 7];
$ctx->customer = null;
$ctx->shop = (object) ['id' => 1];

$binding = (new PopupSubmissionBindingFactory())->fromCartSelection([
    'id_cart' => 501,
    'cart_total' => 300.0,
    'scheme_type' => 'standard',
    'kop_code' => 'CAT',
    'months' => 12,
    'filter_id' => 1,
    'scheme_key' => 'standard|CAT|12|1',
    'first_installment' => 0,
], $ctx);

$db = new FakePopupDb();
$repo = new PopupSubmissionRepository($db);
$row = $repo->issueOrReuse(1, $binding['hash'], $binding['id_guest'], $binding['id_customer'], '');
$token = (string) $row['submission_token'];
$guard = new ProductPopupOperationGuard($repo);

$first = $guard->resolve($token, $binding['hash'], 1, $binding['id_guest'], $binding['id_customer']);
assertCartReplay(isset($first['submission']) && !isset($first['response']), 'first cart apply claim must succeed');

$replay = $guard->resolve($token, $binding['hash'], 1, $binding['id_guest'], $binding['id_customer']);
assertCartReplay(isset($replay['response']), 'duplicate cart apply must return safe replay response');
assertCartReplay(($replay['response']['success'] ?? false) === true, 'replay must be successful safe response');

$productHash = (new PopupSubmissionSelectionHash())->hash([
    'flow' => PopupSubmissionSelectionHash::FLOW_PRODUCT_POPUP,
    'id_shop' => 1,
    'id_product' => 1,
    'id_product_attribute' => 0,
    'quantity' => 1,
    'scheme_type' => 'standard',
    'kop_code' => 'CAT',
    'months' => 12,
    'filter_id' => 1,
    'scheme_key' => 'standard|CAT|12|1',
    'first_installment' => 0,
    'id_guest' => 7,
    'id_customer' => 0,
]);
$crossFlow = $guard->resolve($token, $productHash, 1, 7, 0);
assertCartReplay(isset($crossFlow['response']) && ($crossFlow['response']['success'] ?? true) === false, 'product hash must not claim cart token');

fwrite(STDOUT, "OK (Phase 8 cart apply replay + flow mismatch)\n");
