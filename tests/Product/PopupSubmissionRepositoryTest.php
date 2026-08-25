<?php

declare(strict_types=1);

/**
 * Popup submission hash + in-memory repository: issue, expiry, atomic claim, replay, shop/identity.
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
use PrestaShop\Module\Unipayment\Product\PopupSubmissionStates;
use PrestaShop\Module\Unipayment\Tests\Support\FakePopupDb;

function assertPopupRepo(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$hash = new PopupSubmissionSelectionHash();
$base = [
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

$h1 = $hash->hash($base);
assertPopupRepo($h1 === $hash->hash($base) && strlen($h1) === 64, 'selection hash must be stable sha256');
$changedMonths = $base;
$changedMonths['months'] = 24;
assertPopupRepo($hash->hash($changedMonths) !== $h1, 'months change must invalidate hash');
$changedKop = $base;
$changedKop['kop_code'] = 'OTHER';
assertPopupRepo($hash->hash($changedKop) !== $h1, 'KOP change must invalidate hash');
$changedProduct = $base;
$changedProduct['id_product'] = 11;
assertPopupRepo($hash->hash($changedProduct) !== $h1, 'product change must invalidate hash');
$changedCombo = $base;
$changedCombo['id_product_attribute'] = 9;
assertPopupRepo($hash->hash($changedCombo) !== $h1, 'combination change must invalidate hash');
$changedQty = $base;
$changedQty['quantity'] = 2;
assertPopupRepo($hash->hash($changedQty) !== $h1, 'quantity change must invalidate hash');
$changedShop = $base;
$changedShop['id_shop'] = 2;
assertPopupRepo($hash->hash($changedShop) !== $h1, 'shop change must invalidate hash');
$changedGuest = $base;
$changedGuest['id_guest'] = 7;
assertPopupRepo($hash->hash($changedGuest) !== $h1, 'guest change must invalidate hash');
$changedCustomer = $base;
$changedCustomer['id_customer'] = 5;
assertPopupRepo($hash->hash($changedCustomer) !== $h1, 'customer change must invalidate hash');
$changedFirst = $base;
$changedFirst['first_installment'] = '10.50';
assertPopupRepo($hash->hash($changedFirst) === $h1, 'first installment normalization must be deterministic');

$db = new FakePopupDb();
$repo = new PopupSubmissionRepository($db);
assertPopupRepo($repo->install(), 'install');

$selectionHash = $hash->hash($base);
$issued = $repo->issueOrReuse(1, $selectionHash, 99, 0, '');
$token = (string) $issued['submission_token'];
assertPopupRepo(strlen($token) === 64 && ctype_xdigit($token), 'issued token is 64 hex chars');
assertPopupRepo((string) $issued['state'] === PopupSubmissionStates::ISSUED, 'issued state');

$reused = $repo->issueOrReuse(1, $selectionHash, 99, 0, $token);
assertPopupRepo((string) $reused['submission_token'] === $token, 'preferred token reused');

$reusedByHash = $repo->issueOrReuse(1, $selectionHash, 99, 0, '');
assertPopupRepo((string) $reusedByHash['submission_token'] === $token, 'same binding reuses issued row');

$otherHash = $hash->hash(array_merge($base, ['months' => 6]));
$newRow = $repo->issueOrReuse(1, $otherHash, 99, 0, $token);
assertPopupRepo((string) $newRow['submission_token'] !== $token, 'changed selection issues a new token');

$winner = $repo->claimForProcessing($token);
$loser = $repo->claimForProcessing($token);
assertPopupRepo(is_array($winner) && (string) $winner['state'] === PopupSubmissionStates::PROCESSING, 'first claim wins');
assertPopupRepo($loser === null, 'second claim must not win');

$claimSql = '';
foreach ($db->sql as $sql) {
    if (strpos($sql, 'processing') !== false && strpos($sql, 'UPDATE') !== false) {
        $claimSql = $sql;
        break;
    }
}
assertPopupRepo(strpos($claimSql, 'SELECT') === false, 'claim SQL is not a SELECT');
assertPopupRepo(strpos($claimSql, 'AND `state` =') !== false, 'claim SQL constrains issued state');

$repo->revertProcessingWithoutCart((int) $winner['id_submission']);
$reverted = $repo->findByToken($token);
assertPopupRepo(is_array($reverted) && (string) $reverted['state'] === PopupSubmissionStates::ISSUED, 'processing without cart reverts');

$claimedAgain = $repo->claimForProcessing($token);
assertPopupRepo(is_array($claimedAgain), 'reverted token can be claimed');
$repo->markIdentityAccepted((int) $claimedAgain['id_submission']);
$accepted = $repo->findByToken($token);
assertPopupRepo(is_array($accepted) && (string) $accepted['state'] === PopupSubmissionStates::IDENTITY_ACCEPTED, 'identity_accepted terminal');
assertPopupRepo($repo->claimForProcessing($token) === null, 'accepted token cannot be claimed again');

$expired = $repo->issueOrReuse(1, $hash->hash(array_merge($base, ['kop_code' => 'EXP'])), 99, 0, '');
$db->rows[(int) $expired['id_submission']]['expires_at'] = '2000-01-01 00:00:00';
assertPopupRepo($repo->isExpired($db->rows[(int) $expired['id_submission']]), 'expired issued token detected');
assertPopupRepo($repo->claimForProcessing((string) $expired['submission_token']) === null, 'expired token cannot be claimed');

$shopB = $repo->issueOrReuse(2, $hash->hash(array_merge($base, ['id_shop' => 2, 'kop_code' => 'S2'])), 99, 0, '');
assertPopupRepo((int) $shopB['id_shop'] === 2, 'token is shop-scoped at issue');

fwrite(STDOUT, "OK (popup submission hash + repository)\n");
