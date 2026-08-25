<?php

declare(strict_types=1);

/**
 * Operation guard: missing/expired/replay/wrong shop/wrong identity/selection changed.
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

use PrestaShop\Module\Unipayment\Product\PopupSubmissionRepository;
use PrestaShop\Module\Unipayment\Product\PopupSubmissionSelectionHash;
use PrestaShop\Module\Unipayment\Product\ProductPopupOperationGuard;
use PrestaShop\Module\Unipayment\Tests\Support\FakePopupDb;

function assertOpGuard(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$hash = new PopupSubmissionSelectionHash();
$binding = [
    'id_shop' => 1,
    'id_product' => 10,
    'id_product_attribute' => 2,
    'quantity' => 1,
    'scheme_type' => 'standard',
    'kop_code' => 'STD',
    'months' => 12,
    'filter_id' => 0,
    'scheme_key' => 'standard|STD|12|0',
    'first_installment' => 10.5,
    'id_guest' => 99,
    'id_customer' => 0,
];
$selectionHash = $hash->hash($binding);

$db = new FakePopupDb();
$repo = new PopupSubmissionRepository($db);
$guard = new ProductPopupOperationGuard($repo);
$issued = $repo->issueOrReuse(1, $selectionHash, 99, 0, '');
$token = (string) $issued['submission_token'];

$missing = $guard->resolve('', $selectionHash, 1, 99, 0);
assertOpGuard(isset($missing['response']) && $missing['response']['success'] === false, 'missing token rejected');

$unknown = $guard->resolve(str_repeat('a', 64), $selectionHash, 1, 99, 0);
assertOpGuard(isset($unknown['response']) && $unknown['response']['success'] === false, 'unknown token rejected');

$wrongShop = $guard->resolve($token, $selectionHash, 2, 99, 0);
assertOpGuard(isset($wrongShop['response']) && $wrongShop['response']['success'] === false, 'cross-shop token rejected');

$wrongGuest = $guard->resolve($token, $selectionHash, 1, 7, 0);
assertOpGuard(
    isset($wrongGuest['response']['selection_changed']) && $wrongGuest['response']['selection_changed'] === true,
    'session mismatch rejected'
);

$wrongCustomer = $guard->resolve($token, $selectionHash, 1, 99, 5);
assertOpGuard(
    isset($wrongCustomer['response']['selection_changed']) && $wrongCustomer['response']['selection_changed'] === true,
    'customer mismatch rejected'
);

$tamperedHash = $hash->hash(array_merge($binding, ['months' => 24]));
$tampered = $guard->resolve($token, $tamperedHash, 1, 99, 0);
assertOpGuard(
    isset($tampered['response']['selection_changed']) && $tampered['response']['selection_changed'] === true,
    'tampered months rejected'
);

$winner = $guard->resolve($token, $selectionHash, 1, 99, 0);
assertOpGuard(isset($winner['submission']) && (string) $winner['submission']['state'] === 'processing', 'first apply claims');

$concurrent = $guard->resolve($token, $selectionHash, 1, 99, 0);
assertOpGuard(
    isset($concurrent['response']['step']) && $concurrent['response']['step'] === 'processing',
    'concurrent claim returns processing'
);

$repo->markIdentityAccepted((int) $winner['submission']['id_submission']);
$replay = $guard->resolve($token, $selectionHash, 1, 99, 0);
assertOpGuard(
    isset($replay['response']['step']) && $replay['response']['step'] === 'identity_accepted'
        && !empty($replay['response']['replay']),
    'replay of accepted identity returns same safe state'
);

$expiredDb = new FakePopupDb();
$expiredRepo = new PopupSubmissionRepository($expiredDb);
$expiredGuard = new ProductPopupOperationGuard($expiredRepo);
$fresh = $expiredRepo->issueOrReuse(1, $selectionHash, 99, 0, '');
$expiredDb->rows[(int) $fresh['id_submission']]['expires_at'] = '2000-01-01 00:00:00';
$expired = $expiredGuard->resolve((string) $fresh['submission_token'], $selectionHash, 1, 99, 0);
assertOpGuard(
    isset($expired['response']) && $expired['response']['success'] === false,
    'expired token rejected'
);

fwrite(STDOUT, "OK (product popup operation guard)\n");
