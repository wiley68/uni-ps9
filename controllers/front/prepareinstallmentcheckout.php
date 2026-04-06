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
            $this->result['message'] = $this->module instanceof UniPayment
                ? $this->module->trans('Invalid security token.', [], 'Modules.Unipayment.Shop')
                : 'Invalid security token.';
            parent::initContent();

            return;
        }

        $idProduct = (int) Tools::getValue('id_product');
        $idProductAttribute = (int) Tools::getValue('id_product_attribute');
        $qty = (int) Tools::getValue('qty', 1);
        $installmentsRequested = (int) Tools::getValue('installments', 0);

        if ($idProduct <= 0 || $qty < 1) {
            $this->result['message'] = $this->module instanceof UniPayment
                ? $this->module->trans('Invalid product or quantity.', [], 'Modules.Unipayment.Shop')
                : 'Invalid product or quantity.';
            parent::initContent();

            return;
        }

        $product = new Product($idProduct, true, $this->context->language->id, $this->context->shop->id);
        if (!Validate::isLoadedObject($product)) {
            $this->result['message'] = $this->module instanceof UniPayment
                ? $this->module->trans('Product not available.', [], 'Modules.Unipayment.Shop')
                : 'Product not available.';
            parent::initContent();

            return;
        }
        $activeInShop = (bool) Db::getInstance()->getValue(
            'SELECT `active` FROM `' . _DB_PREFIX_ . 'product_shop` WHERE `id_product` = ' . (int) $idProduct
            . ' AND `id_shop` = ' . (int) $this->context->shop->id
        );
        if (!$activeInShop) {
            $this->result['message'] = $this->module instanceof UniPayment
                ? $this->module->trans('Product not available.', [], 'Modules.Unipayment.Shop')
                : 'Product not available.';
            parent::initContent();

            return;
        }

        /** @var Cart $cart */
        $cart = $this->context->cart;
        if (!Validate::isLoadedObject($cart) || (int) $cart->id <= 0) {
            $this->result['message'] = $this->module instanceof UniPayment
                ? $this->module->trans('Cart error.', [], 'Modules.Unipayment.Shop')
                : 'Cart error.';
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
            $this->result['message'] = $this->module instanceof UniPayment
                ? ($update === -1
                    ? $this->module->trans('Minimum quantity not reached.', [], 'Modules.Unipayment.Shop')
                    : $this->module->trans('Could not add to cart.', [], 'Modules.Unipayment.Shop'))
                : ($update === -1 ? 'Minimum quantity not reached.' : 'Could not add to cart.');
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
}
