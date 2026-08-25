<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Cart;

final class CartContext
{
    /** @var CartLine[] */
    public $lines;

    /** @var float */
    public $total;
    /** @var array<string, mixed> */
    public $checkoutState;

    /** @param CartLine[] $lines @param array<string, mixed> $checkoutState */
    public function __construct(array $lines, float $total, array $checkoutState = [])
    {
        $this->lines = array_values($lines);
        $this->total = round($total, 2);
        $this->checkoutState = $checkoutState;
    }
}
