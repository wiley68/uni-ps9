<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Product;

final class ProductPopupCheckoutPreselectionException extends \RuntimeException
{
    public function customerMessage(): string
    {
        return $this->getMessage();
    }
}
