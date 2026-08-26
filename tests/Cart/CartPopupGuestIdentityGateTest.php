<?php

declare(strict_types=1);

/**
 * Cart popup apply gate must use BindingFactory identity (isLogged), not raw customer->id.
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
    public $shop;
}

final class Customer
{
    public int $id = 0;
    public bool $logged = false;
    public int $is_guest = 0;

    public function isLogged(): bool
    {
        return $this->logged;
    }
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
use PrestaShop\Module\Unipayment\Tests\Support\FakePopupDb;

function assertCartGuestId(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$factory = new PopupSubmissionBindingFactory();
$hash = new PopupSubmissionSelectionHash();

// A) Guest customer object id > 0, isLogged() false → authenticated customer identity is 0 → MATCH
$guestCustomer = new Customer();
$guestCustomer->id = 4;
$guestCustomer->logged = false;
$guestCustomer->is_guest = 1;
$guestCtx = new Context();
$guestCtx->cookie = (object) ['id_guest' => 8967];
$guestCtx->customer = $guestCustomer;
$guestCtx->shop = (object) ['id' => 1];
$guestIdentity = $factory->identityFromContext($guestCtx);
assertCartGuestId($guestIdentity['id_guest'] === 8967, 'A: guest id from cookie');
assertCartGuestId($guestIdentity['id_customer'] === 0, 'A: guest customer object must resolve to id_customer 0');
$rowGuest = ['id_guest' => 8967, 'id_customer' => null];
assertCartGuestId(
    PopupSubmissionRepository::identityMatches($rowGuest, $guestIdentity['id_guest'], $guestIdentity['id_customer']),
    'A: cart guest token matches despite guest customer id=4'
);
// Prove raw customer->id would wrongly reject (the pre-fix bug).
assertCartGuestId(
    !PopupSubmissionRepository::identityMatches($rowGuest, 8967, (int) $guestCustomer->id),
    'A: raw customer->id must NOT be used for guest gate'
);

// B) Logged-in matching customer → accepted
$logged = new Customer();
$logged->id = 42;
$logged->logged = true;
$loggedCtx = new Context();
$loggedCtx->cookie = (object) ['id_guest' => 10];
$loggedCtx->customer = $logged;
$loggedCtx->shop = (object) ['id' => 1];
$loggedIdentity = $factory->identityFromContext($loggedCtx);
assertCartGuestId($loggedIdentity['id_customer'] === 42, 'B: logged-in resolves customer 42');
assertCartGuestId(
    PopupSubmissionRepository::identityMatches(
        ['id_guest' => 10, 'id_customer' => 42],
        $loggedIdentity['id_guest'],
        $loggedIdentity['id_customer']
    ),
    'B: matching logged-in token accepted'
);

// C) Logged-in wrong customer → rejected
assertCartGuestId(
    !PopupSubmissionRepository::identityMatches(
        ['id_guest' => 10, 'id_customer' => 41],
        $loggedIdentity['id_guest'],
        $loggedIdentity['id_customer']
    ),
    'C: wrong logged-in customer rejected'
);

// D) Wrong guest id → rejected
$wrongGuestCtx = new Context();
$wrongGuestCtx->cookie = (object) ['id_guest' => 200];
$wrongGuestCtx->customer = $guestCustomer;
$wrongGuestCtx->shop = (object) ['id' => 1];
$wrongGuestIdentity = $factory->identityFromContext($wrongGuestCtx);
assertCartGuestId(
    !PopupSubmissionRepository::identityMatches(
        ['id_guest' => 100, 'id_customer' => 0],
        $wrongGuestIdentity['id_guest'],
        $wrongGuestIdentity['id_customer']
    ),
    'D: wrong guest rejected'
);

// E/F/G) Wrong shop / flow / selection hash → distinct hashes (cart binding)
$baseCart = [
    'flow' => PopupSubmissionSelectionHash::FLOW_CART_POPUP,
    'id_shop' => 1,
    'id_cart' => 55,
    'cart_total' => 199.99,
    'scheme_type' => 'standard',
    'kop_code' => 'STD',
    'months' => 12,
    'filter_id' => 0,
    'scheme_key' => 'standard|STD|12|0',
    'first_installment' => 20,
    'id_guest' => 8967,
    'id_customer' => 0,
];
$baseHash = $hash->hash($baseCart);
$otherShop = $baseCart;
$otherShop['id_shop'] = 2;
$otherFlow = $baseCart;
$otherFlow['flow'] = PopupSubmissionSelectionHash::FLOW_PRODUCT_POPUP;
$otherSel = $baseCart;
$otherSel['kop_code'] = 'OTHER';
assertCartGuestId($hash->hash($otherShop) !== $baseHash, 'E: wrong shop changes selection hash');
assertCartGuestId($hash->hash($otherFlow) !== $baseHash, 'F: wrong flow changes selection hash');
assertCartGuestId($hash->hash($otherSel) !== $baseHash, 'G: wrong selection changes hash');

// Repository issue/reuse + preferred token respects BindingFactory identity for cart flow
$db = new FakePopupDb();
$repo = new PopupSubmissionRepository($db);
$issued = $repo->issueOrReuse(1, $baseHash, 8967, 0, '', PopupSubmissionSelectionHash::FLOW_CART_POPUP);
$token = (string) $issued['submission_token'];
assertCartGuestId((string) $issued['flow'] === PopupSubmissionSelectionHash::FLOW_CART_POPUP, 'cart flow stored');
assertCartGuestId((int) ($issued['id_customer'] ?? 0) === 0, 'issued guest row has id_customer 0');
$found = $repo->findByToken($token);
assertCartGuestId(is_array($found), 'token findable');
assertCartGuestId(
    PopupSubmissionRepository::identityMatches($found, $guestIdentity['id_guest'], $guestIdentity['id_customer']),
    'issued cart guest row matches BindingFactory guest identity'
);

// Contract: cart apply gate uses BindingFactory, not raw customer->id
$ctrl = (string) file_get_contents(dirname(__DIR__, 2) . '/controllers/front/cartpopup.php');
assertCartGuestId(strpos($ctrl, 'identityFromContext') !== false, 'cart gate uses BindingFactory identityFromContext');
assertCartGuestId(
    !preg_match(
        '/identityMatches\(\s*\$row\s*,\s*\$idGuest\s*,\s*\(int\)\s*\(\s*\$this->context->customer->id/',
        $ctrl
    ),
    'cart gate must not pass raw context->customer->id into identityMatches'
);
assertCartGuestId(
    strpos($ctrl, '(int) ($this->context->customer->id ?? 0)') === false
        || strpos($ctrl, 'identityFromContext') !== false,
    'canonical identity path present'
);
// Stronger: no customer->id adjacent to identityMatches call site
$gateStart = strpos($ctrl, 'function resolvePopupSubmissionGate');
assertCartGuestId($gateStart !== false, 'gate method present');
$gateBody = substr($ctrl, $gateStart, 1800);
assertCartGuestId(strpos($gateBody, 'identityFromContext') !== false, 'gate body uses identityFromContext');
assertCartGuestId(strpos($gateBody, 'customer->id') === false, 'gate body must not read raw customer->id');

fwrite(STDOUT, "OK (cart guest popup identity gate)\n");
