<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Product;

use PrestaShop\Module\Unipayment\Checkout\CheckoutPreferenceStore;

final class ProductPopupCheckoutPreselectionService
{
    /** @var CheckoutPreferenceStore */
    private $preferences;
    /** @var ProductPopupPreselectOperationGuard */
    private $operations;

    public function __construct(
        ?CheckoutPreferenceStore $preferences = null,
        ?ProductPopupPreselectOperationGuard $operations = null
    ) {
        $this->preferences = $preferences ?? new CheckoutPreferenceStore();
        $this->operations = $operations ?? new ProductPopupPreselectOperationGuard();
    }

    /**
     * @param array<string, mixed> $calculation
     * @return array{checkout_url: string}
     */
    public function execute(
        array $calculation,
        int $productId,
        int $productAttributeId,
        int $quantity,
        string $operationToken,
        \Context $context,
        \Link $link
    ): array {
        if ($productId <= 0 || $quantity <= 0) {
            throw new ProductPopupCheckoutPreselectionException('Продуктът не може да бъде добавен в количката.');
        }

        $this->operations->validateOperationToken($operationToken);
        $this->operations->clearLegacyMarker($context->cookie);

        $cart = $this->ensureCart($context);
        $cartId = (int) $cart->id;
        $lineQtyBefore = $this->lineQuantity($cart, $productId, $productAttributeId);
        $applied = $this->operations->readApplied($context->cookie);

        if (!$this->operations->shouldSkipCartMutation(
            $applied,
            $operationToken,
            $cartId,
            $productId,
            $productAttributeId,
            $lineQtyBefore
        )) {
            $this->addProductToCart($cart, $productId, $productAttributeId, $quantity);
            $lineQtyAfter = $this->lineQuantity($cart, $productId, $productAttributeId);
            if ($lineQtyAfter < $lineQtyBefore + $quantity) {
                throw new ProductPopupCheckoutPreselectionException('Продуктът не може да бъде добавен в количката.');
            }

            $this->operations->persistApplied(
                $context->cookie,
                $operationToken,
                $cartId,
                $productId,
                $productAttributeId,
                $lineQtyAfter
            );
            $context->cookie->write();
        }

        $finalLineQty = $this->lineQuantity($cart, $productId, $productAttributeId);
        if ($finalLineQty <= 0) {
            throw new ProductPopupCheckoutPreselectionException('Продуктът не може да бъде добавен в количката.');
        }

        $context->cart = $cart;
        $context->cookie->id_cart = $cartId;

        $this->preferences->save($context->cookie, [
            'product_id' => $productId,
            'product_attribute_id' => $productAttributeId,
            'quantity' => $quantity,
            'scheme_type' => (string) ($calculation['scheme_type'] ?? ''),
            'kop_code' => (string) ($calculation['kop_code'] ?? ''),
            'months' => (int) ($calculation['months'] ?? 0),
            'filter_id' => (int) ($calculation['filter_id'] ?? 0),
            'first_installment' => $calculation['first_installment'] ?? 0,
            'product_amount' => $calculation['price'] ?? 0,
        ], $cartId, (int) $context->customer->id);

        return [
            'checkout_url' => $link->getPageLink('order', true),
        ];
    }

    private function ensureCart(\Context $context): \Cart
    {
        $cart = $context->cart;
        if ($cart instanceof \Cart && (int) $cart->id > 0) {
            return $cart;
        }

        if ((int) $context->cookie->id_guest <= 0) {
            \Guest::setNewGuest($context->cookie);
        }

        $cart = new \Cart();
        $cart->id_shop_group = (int) $context->shop->id_shop_group;
        $cart->id_shop = (int) $context->shop->id;
        $cart->id_lang = (int) $context->language->id;
        $cart->id_currency = (int) $context->currency->id;
        $cart->id_customer = (int) $context->customer->id;
        $cart->id_guest = (int) $context->cookie->id_guest;
        $cart->secure_key = (string) $context->customer->secure_key;
        if (!$cart->add()) {
            throw new ProductPopupCheckoutPreselectionException('Количката не може да бъде инициализирана.');
        }

        $context->cart = $cart;
        $context->cookie->id_cart = (int) $cart->id;
        $context->cookie->write();

        return $cart;
    }

    private function lineQuantity(\Cart $cart, int $productId, int $productAttributeId): int
    {
        $row = $cart->getProductQuantity(
            $productId,
            $productAttributeId > 0 ? $productAttributeId : 0
        );

        return (int) ($row['quantity'] ?? 0);
    }

    private function addProductToCart(\Cart $cart, int $productId, int $productAttributeId, int $quantity): void
    {
        $updated = $cart->updateQty(
            $quantity,
            $productId,
            $productAttributeId > 0 ? $productAttributeId : null
        );
        if ($updated === false) {
            throw new ProductPopupCheckoutPreselectionException('Продуктът не може да бъде добавен в количката.');
        }
    }
}
