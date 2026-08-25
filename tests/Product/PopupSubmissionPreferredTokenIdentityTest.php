<?php

declare(strict_types=1);

/**
 * preferredToken reuse must enforce the same identity isolation as apply gate.
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
use PrestaShop\Module\Unipayment\Tests\Support\FakePopupDb;

function assertPreferredIdentity(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$hash = new PopupSubmissionSelectionHash();
$selection = [
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
$selectionHash = $hash->hash($selection);

$db = new FakePopupDb();
$repo = new PopupSubmissionRepository($db);

// 1) same guest + same selection → preferred token reused
$guestA = $repo->issueOrReuse(1, $selectionHash, 99, 0, '');
$tokenA = (string) $guestA['submission_token'];
$sameGuest = $repo->issueOrReuse(1, $selectionHash, 99, 0, $tokenA);
assertPreferredIdentity((string) $sameGuest['submission_token'] === $tokenA, 'same guest reuses preferred token');

// 2) different guest + same selection → foreign token not reused
$guestB = $repo->issueOrReuse(1, $selectionHash, 77, 0, $tokenA);
$tokenB = (string) $guestB['submission_token'];
assertPreferredIdentity($tokenB !== $tokenA, 'different guest must not receive guest A token');
assertPreferredIdentity((int) ($guestB['id_guest'] ?? 0) === 77, 'guest B row binds guest 77');

// 3) same customer + same selection → reused
$customerSelection = array_merge($selection, ['id_guest' => 5, 'id_customer' => 17, 'kop_code' => 'CUST']);
$customerHash = $hash->hash($customerSelection);
$customerA = $repo->issueOrReuse(1, $customerHash, 5, 17, '');
$tokenCustomerA = (string) $customerA['submission_token'];
$sameCustomer = $repo->issueOrReuse(1, $customerHash, 5, 17, $tokenCustomerA);
assertPreferredIdentity(
    (string) $sameCustomer['submission_token'] === $tokenCustomerA,
    'same customer reuses preferred token'
);

// 4) different customer + same selection → not reused
$customerB = $repo->issueOrReuse(1, $customerHash, 5, 18, $tokenCustomerA);
assertPreferredIdentity(
    (string) $customerB['submission_token'] !== $tokenCustomerA,
    'different customer must not receive customer A token'
);

// 5) guest token → logged-in identity → not reused
$loggedFromGuest = $repo->issueOrReuse(1, $selectionHash, 99, 42, $tokenA);
assertPreferredIdentity(
    (string) $loggedFromGuest['submission_token'] !== $tokenA,
    'guest → logged-in must not reuse guest token'
);

// 6) cross-shop preferred token → not reused
$shopB = $repo->issueOrReuse(2, $selectionHash, 99, 0, $tokenA);
assertPreferredIdentity(
    (string) $shopB['submission_token'] !== $tokenA,
    'cross-shop preferred token must not be reused'
);

// 7) changed selection → not reused
$otherHash = $hash->hash(array_merge($selection, ['months' => 24]));
$changedSelection = $repo->issueOrReuse(1, $otherHash, 99, 0, $tokenA);
assertPreferredIdentity(
    (string) $changedSelection['submission_token'] !== $tokenA,
    'changed selection must not reuse preferred token'
);

// 8) expired token → not reused
$expired = $repo->issueOrReuse(1, $hash->hash(array_merge($selection, ['kop_code' => 'EXP'])), 99, 0, '');
$expiredToken = (string) $expired['submission_token'];
$db->rows[(int) $expired['id_submission']]['expires_at'] = '2000-01-01 00:00:00';
$afterExpired = $repo->issueOrReuse(
    1,
    $hash->hash(array_merge($selection, ['kop_code' => 'EXP'])),
    99,
    0,
    $expiredToken
);
assertPreferredIdentity(
    (string) $afterExpired['submission_token'] !== $expiredToken,
    'expired preferred token must not be reused'
);

// 9) shared identity helper matches findReusableIssued / guard semantics
assertPreferredIdentity(
    PopupSubmissionRepository::identityMatches(['id_guest' => null, 'id_customer' => null], 0, 0),
    'NULL identity columns match zero guest/customer'
);
assertPreferredIdentity(
    !PopupSubmissionRepository::identityMatches(['id_guest' => 99, 'id_customer' => 0], 77, 0),
    'guest mismatch detected by shared helper'
);

// 10) rejected reuse path must not log the raw preferred token
$repoSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Product/PopupSubmissionRepository.php');
$controllerSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/controllers/front/productpopup.php');
$issueStart = strpos($repoSrc, 'public function issueOrReuse');
$findStart = strpos($repoSrc, 'public function findByToken');
assertPreferredIdentity($issueStart !== false && $findStart !== false && $findStart > $issueStart, 'issueOrReuse body locatable');
$issueBody = substr($repoSrc, $issueStart, $findStart - $issueStart);
assertPreferredIdentity(
    strpos($issueBody, 'PrestaShopLogger') === false
        && strpos($issueBody, 'addLog') === false
        && strpos($issueBody, 'error_log') === false,
    'issueOrReuse must not log on rejected preferred token'
);
$issueHandlerStart = strpos($controllerSrc, 'function handleIssueSubmissionToken');
$applyHandlerStart = strpos($controllerSrc, 'function handleApply');
assertPreferredIdentity(
    $issueHandlerStart !== false && $applyHandlerStart !== false && $applyHandlerStart > $issueHandlerStart,
    'issue handler locatable'
);
$issueHandler = substr($controllerSrc, $issueHandlerStart, $applyHandlerStart - $issueHandlerStart);
assertPreferredIdentity(
    strpos($issueHandler, 'addLog') === false
        && strpos($issueHandler, 'PrestaShopLogger') === false
        && strpos($issueHandler, 'error_log') === false,
    'controller issue path must not log raw popup_submission_token'
);
assertPreferredIdentity(
    strpos($issueBody, 'identityMatches($existing, $idGuest, $idCustomer)') !== false,
    'preferred reuse requires identityMatches'
);

fwrite(STDOUT, "OK (preferred token identity isolation)\n");
