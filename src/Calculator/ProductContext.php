<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Calculator;

final class ProductContext
{
    public $productId;
    public $categoryIds;
    public $price;

    /** @param int[] $categoryIds */
    public function __construct(int $productId, array $categoryIds, float $price)
    {
        $this->productId = $productId;
        $this->categoryIds = array_values(array_unique(array_map('intval', $categoryIds)));
        $this->price = $price;
    }
}
