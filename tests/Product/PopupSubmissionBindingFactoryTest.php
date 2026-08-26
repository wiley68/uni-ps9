<?php

declare(strict_types=1);

/**
 * PopupSubmissionBindingFactory uses Context identity, not POST.
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

    public function isLogged(): bool
    {
        return $this->logged;
    }
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Product\PopupSubmissionBindingFactory;

function assertBinding(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$selection = [
    'id_product' => 10,
    'id_product_attribute' => 2,
    'quantity' => 1,
    'scheme_type' => 'standard',
    'kop_code' => 'STD',
    'months' => 12,
    'filter_id' => 0,
    'scheme_key' => 'standard|STD|12|0',
    'first_installment' => 10.5,
];

$guestContext = new Context();
$guestContext->cookie = (object) ['id_guest' => 42];
$guestContext->customer = null;
$guestContext->shop = (object) ['id' => 1];
$guest = (new PopupSubmissionBindingFactory())->fromSelection($selection, $guestContext);
assertBinding($guest['id_guest'] === 42 && $guest['id_customer'] === 0, 'guest binding from cookie');
assertBinding($guest['binding']['id_shop'] === 1, 'shop from context');

$loggedCustomer = new Customer();
$loggedCustomer->id = 17;
$loggedCustomer->logged = true;
$loggedContext = new Context();
$loggedContext->cookie = (object) ['id_guest' => 9];
$loggedContext->customer = $loggedCustomer;
$loggedContext->shop = (object) ['id' => 1];
$logged = (new PopupSubmissionBindingFactory())->fromSelection($selection, $loggedContext);
assertBinding($logged['id_customer'] === 17 && $logged['id_guest'] === 9, 'logged-in customer from Context');

$poisoned = new Customer();
$poisoned->id = 99;
$poisoned->logged = false;
$poisonedContext = new Context();
$poisonedContext->cookie = (object) ['id_guest' => 3];
$poisonedContext->customer = $poisoned;
$poisonedContext->shop = (object) ['id' => 1];
$notLogged = (new PopupSubmissionBindingFactory())->fromSelection($selection, $poisonedContext);
assertBinding($notLogged['id_customer'] === 0, 'cookie id_customer leftover must not bind customer');

$guestObj = new Customer();
$guestObj->id = 4;
$guestObj->logged = false;
$guestObjCtx = new Context();
$guestObjCtx->cookie = (object) ['id_guest' => 8967];
$guestObjCtx->customer = $guestObj;
$guestObjCtx->shop = (object) ['id' => 1];
$viaIdentity = (new PopupSubmissionBindingFactory())->identityFromContext($guestObjCtx);
assertBinding($viaIdentity === ['id_guest' => 8967, 'id_customer' => 0], 'identityFromContext matches issue semantics for PS guest customer');

$otherQty = $selection;
$otherQty['quantity'] = 2;
$qtyHash = (new PopupSubmissionBindingFactory())->fromSelection($otherQty, $guestContext);
assertBinding($qtyHash['hash'] !== $guest['hash'], 'quantity drift changes hash');

fwrite(STDOUT, "OK (popup submission binding factory)\n");
