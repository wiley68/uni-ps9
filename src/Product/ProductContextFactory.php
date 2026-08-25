<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Product;

use PrestaShop\Module\Unipayment\Calculator\ProductContext;

final class ProductContextFactory
{
    public function create(int $productId, int $productAttributeId = 0, int $quantity = 1): ProductContext
    {
        if ($productId <= 0) {
            throw new \InvalidArgumentException('A valid product ID is required.');
        }
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('A valid product quantity is required.');
        }

        $product = new \Product($productId, false, (int) \Context::getContext()->language->id);
        if (!\Validate::isLoadedObject($product) || !$product->active) {
            throw new \InvalidArgumentException('The product is not available.');
        }
        if ($productAttributeId > 0 && !$this->attributeBelongsToProduct($productId, $productAttributeId)) {
            throw new \InvalidArgumentException('The selected product combination is invalid.');
        }

        $unitPrice = (float) $product->getPrice(true, $productAttributeId > 0 ? $productAttributeId : null, 6);
        $price = $unitPrice * $quantity;
        if (!is_finite($unitPrice) || $unitPrice <= 0 || !is_finite($price) || $price <= 0) {
            throw new \InvalidArgumentException('The product price is invalid.');
        }

        return new ProductContext($productId, array_map('intval', $product->getCategories()), $price);
    }

    private function attributeBelongsToProduct(int $productId, int $productAttributeId): bool
    {
        return (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_attribute`'
            . ' WHERE `id_product_attribute` = ' . (int) $productAttributeId
            . ' AND `id_product` = ' . (int) $productId
        ) === 1;
    }
}
