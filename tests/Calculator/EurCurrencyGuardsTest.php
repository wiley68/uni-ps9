<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

final class Cart
{
    public int $id_currency;
    public function __construct(int $idCurrency) { $this->id_currency = $idCurrency; }
}
final class Currency
{
    public int $id;
    public string $iso_code;
    /** @var array<int, string> */
    public static array $codes = [1 => 'EUR', 2 => 'BGN', 3 => 'USD', 4 => 'EUR'];
    public function __construct(int $id = 0)
    {
        $this->id = $id;
        $this->iso_code = self::$codes[$id] ?? '';
    }
}
final class Context
{
    public ?Currency $currency;
    public function __construct(?Currency $currency) { $this->currency = $currency; }
}
final class Validate
{
    public static function isLoadedObject(object $object): bool { return (int) ($object->id ?? 0) > 0; }
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Calculator\CartCurrencyGuard;
use PrestaShop\Module\Unipayment\Calculator\CurrencyGate;

function assertEurGuard(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$gate = new CurrencyGate();
foreach (['EUR' => true, ' eur ' => true, 'BGN' => false, 'USD' => false, '' => false] as $iso => $expected) {
    assertEurGuard($gate->supports($iso) === $expected, 'CurrencyGate case: ' . $iso);
}
$guard = new CartCurrencyGuard();
foreach ([
    [1, 1, true],
    [2, 1, false],
    [1, 2, false],
    [4, 1, false],
    [2, 2, false],
    [3, 3, false],
    [0, 1, false],
] as [$cartId, $contextId, $valid]) {
    assertEurGuard(($guard->supportedIso(new Cart($cartId), new Context(new Currency($contextId))) === 'EUR') === $valid,
        "cart/context currency mismatch: $cartId/$contextId");
}
assertEurGuard($guard->supportedIso(new Cart(1), new Context(null)) === '', 'missing context currency accepted');
