<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Cart;

use PrestaShop\Module\Unipayment\Calculator\ProductContext;

final class CartLine
{
    /** @var ProductContext */
    public $product;

    /** @var int */
    public $productAttributeId;

    /** @var int */
    public $quantity;

    /** @var float */
    public $lineTotal;

    public function __construct(ProductContext $product, int $productAttributeId, int $quantity, float $lineTotal)
    {
        $this->product = $product;
        $this->productAttributeId = max(0, $productAttributeId);
        $this->quantity = max(1, $quantity);
        $this->lineTotal = round($lineTotal, 2);
    }
}
