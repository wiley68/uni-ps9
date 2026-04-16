<?php

/**
 * Copyright since 2024 Ilko Ivanov
 *
 * @author    Ilko Ivanov <ilko.iv@gmail.com>
 * @copyright Ilko Ivanov
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

/**
 * Front controller: AJAX „Купи на изплащане“ — добавя продукт в количката, cookie за UNI, URL към order с select_payment_option.
 *
 * @property UniPayment $module
 */
class UnipaymentPrepareinstallmentcheckoutModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    /**
     * @var array{success: bool, checkout_url: string, message: string}
     */
    public $result = [
        'success' => false,
        'checkout_url' => '',
        'message' => '',
    ];

    /**
     * @see FrontController::initContent()
     */
    public function initContent()
    {
        $this->ajax = true;

        if (Tools::getToken(false) !== (string) Tools::getValue('token')) {
            $this->result['message'] = $this->translateShop('Invalid security token.');
            parent::initContent();

            return;
        }

        $idProduct = (int) Tools::getValue('id_product');
        $idProductAttribute = (int) Tools::getValue('id_product_attribute');
        $qty = (int) Tools::getValue('qty', 1);
        $installmentsRequested = (int) Tools::getValue('installments', 0);

        if ($idProduct <= 0 || $qty < 1) {
            $this->result['message'] = $this->translateShop('Invalid product or quantity.');
            parent::initContent();

            return;
        }

        $product = new Product($idProduct, true, $this->context->language->id, $this->context->shop->id);
        if (!Validate::isLoadedObject($product)) {
            $this->result['message'] = $this->translateShop('Product not available.');
            parent::initContent();

            return;
        }
        $activeInShop = (bool) Db::getInstance()->getValue(
            'SELECT `active` FROM `' . _DB_PREFIX_ . 'product_shop` WHERE `id_product` = ' . (int) $idProduct
                . ' AND `id_shop` = ' . (int) $this->context->shop->id
        );
        if (!$activeInShop) {
            $this->result['message'] = $this->translateShop('Product not available.');
            parent::initContent();

            return;
        }

        /** @var Cart $cart */
        $cart = $this->context->cart;
        if (!Validate::isLoadedObject($cart) || (int) $cart->id <= 0) {
            $cart = $this->initializeContextCart();
        }
        if (!Validate::isLoadedObject($cart) || (int) $cart->id <= 0) {
            $this->result['message'] = $this->translateShop('Cart error.');
            parent::initContent();

            return;
        }

        $update = $cart->updateQty(
            $qty,
            $idProduct,
            $idProductAttribute,
            false,
            'up',
            (int) $cart->id_address_delivery
        );

        if ($update !== true) {
            $this->result['message'] = $update === -1
                ? $this->translateShop('Minimum quantity not reached.')
                : $this->translateShop('Could not add to cart.');
            parent::initContent();

            return;
        }

        $resolvedInstallments = 0;
        if ($this->module instanceof UniPayment) {
            $resolvedInstallments = $this->module->resolveCheckoutInstallmentsBrowserPreference($installmentsRequested);
        }
        if ($this->module instanceof UniPayment) {
            $this->module->writeBrowserCookiesForPrepareCheckout($resolvedInstallments);
        } else {
            $this->setUnipaymentCheckoutPreferenceCookieLegacy();
        }

        $paymentOptionId = $this->findUnipaymentPaymentOptionId();
        $params = [];
        if ($paymentOptionId !== null && $paymentOptionId !== '') {
            $params['select_payment_option'] = $paymentOptionId;
        }

        $this->result['success'] = true;
        $this->result['checkout_url'] = $this->context->link->getPageLink('order', true, null, $params);

        parent::initContent();
    }

    /**
     * @see ModuleFrontController::displayAjax()
     */
    public function displayAjax()
    {
        header('Content-Type: application/json; charset=utf-8');
        exit((string) json_encode($this->result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Търси идентификатора на платежната опция на unipayment от {@see PaymentOptionsFinder}.
     */
    private function findUnipaymentPaymentOptionId(): ?string
    {
        $finder = new PaymentOptionsFinder();
        $presented = $finder->present(false);
        foreach ($presented as $moduleOptions) {
            if (!is_array($moduleOptions)) {
                continue;
            }
            foreach ($moduleOptions as $option) {
                if (!is_array($option)) {
                    continue;
                }
                if (($option['module_name'] ?? '') === 'unipayment' && isset($option['id'])) {
                    return (string) $option['id'];
                }
            }
        }

        return null;
    }

    /**
     * Ако модулът не е наличен като инстанция (неочаквано), само unipayment_pc.
     */
    private function setUnipaymentCheckoutPreferenceCookieLegacy(): void
    {
        setcookie('unipayment_pc', '1', [
            'expires' => time() + 1800,
            'path' => '/',
            'secure' => (bool) Tools::usingSecureMode(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

    private function initializeContextCart(): Cart
    {
        /** @var mixed $cartModel */
        $cartModel = new Cart();
        $cartModel->id_shop = (int) $this->context->shop->id;
        $cartModel->id_shop_group = (int) $this->getCurrentShopGroupId();
        $cartModel->id_lang = (int) $this->context->language->id;
        $cartModel->id_currency = (int) $this->context->currency->id;
        $cartModel->id_guest = (int) $this->context->cookie->id_guest;

        if (Validate::isLoadedObject($this->context->customer)) {
            $cartModel->id_customer = (int) $this->context->customer->id;
            $cartModel->secure_key = (string) $this->context->customer->secure_key;
        }

        $cartModel->add();

        $cartId = isset($cartModel->id) ? (int) $cartModel->id : 0;
        $cart = $cartId > 0 ? new Cart($cartId) : new Cart();
        if (Validate::isLoadedObject($cart) && (int) $cart->id > 0) {
            $this->context->cart = $cart;
            $this->context->cookie->id_cart = (int) $cart->id;
            $this->context->cookie->write();
        }

        return $cart;
    }

    private function translateShop(string $message): string
    {
        if ($this->module instanceof UniPayment) {
            return (string) $this->module->l($message, 'prepareinstallmentcheckout');
        }

        return $message;
    }

    private function getCurrentShopGroupId(): int
    {
        $shopId = (int) $this->context->shop->id;
        if ($shopId <= 0) {
            return 0;
        }
        $sql = 'SELECT s.id_shop_group FROM `' . _DB_PREFIX_ . 'shop` s WHERE s.id_shop = ' . $shopId;
        $value = Db::getInstance()->getValue($sql);
        if ($value === false || $value === null || $value === '') {
            return 0;
        }

        return (int) $value;
    }
}
