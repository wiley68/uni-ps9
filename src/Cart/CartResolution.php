<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Cart;

use PrestaShop\Module\Unipayment\Calculator\Offer;

final class CartResolution
{
    /** @var mixed[] */
    public $standardSchemes;

    /** @var mixed[] */
    public $promoSchemes;

    /** @var Offer|null */
    public $standardOffer;

    /** @var Offer|null */
    public $promoOffer;

    /** @param array<int, mixed> $standardSchemes @param array<int, mixed> $promoSchemes */
    public function __construct(array $standardSchemes, array $promoSchemes, ?Offer $standardOffer, ?Offer $promoOffer)
    {
        $this->standardSchemes = array_values($standardSchemes);
        $this->promoSchemes = array_values($promoSchemes);
        $this->standardOffer = $standardOffer;
        $this->promoOffer = $promoOffer;
    }
}
