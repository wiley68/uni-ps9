<?php

/**
 * Copyright since 2024 Ilko Ivanov
 *
 * @author    Ilko Ivanov <ilko.iv@gmail.com>
 * @copyright Ilko Ivanov
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

use PrestaShop\Module\Unipayment\Service\CheckoutUniCalculationService;

/**
 * Front controller: AJAX калкулатор за UNI Credit в checkout (КОП/коефициенти, JSON).
 *
 * @property UniPayment $module
 */
class UnipaymentCalculateuniModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    /**
     * Отговор за {@see self::displayAjax()}: success + result с полета за uniorder.js.
     *
     * @var array<string, mixed>
     */
    public $result = ['success' => 'unsuccess', 'result' => []];

    /**
     * @see FrontController::initContent()
     */
    public function initContent()
    {
        $this->ajax = true;

        $cart = $this->context->cart;
        if (!Validate::isLoadedObject($cart) || $cart->nbProducts() <= 0) {
            parent::initContent();

            return;
        }

        $installments = (int) Tools::getValue('uni_meseci', 0);
        $totalPrice = (float) str_replace(',', '.', (string) Tools::getValue('uni_total_price', '0'));
        $parva = (float) str_replace(',', '.', (string) Tools::getValue('uni_parva', '0'));
        $module = $this->module;
        if (!$module instanceof UniPayment) {
            parent::initContent();

            return;
        }
        $langId = (int) $this->context->language->id;
        $productCategoryIds = $module->getCheckoutKopCategoryIdsForCartProducts($cart->getProducts(true), $langId);
        $uniEur = (int) Tools::getValue('uni_eur', 0);
        $currencyIso = (string) ($this->context->currency->iso_code ?? 'BGN');

        $uniPromo = (string) Tools::getValue('uni_promo', '');
        $uniPromoData = (string) Tools::getValue('uni_promo_data', '');
        $uniPromoMeseciZnak = (string) Tools::getValue('uni_promo_meseci_znak', '');
        $uniPromoMeseci = (string) Tools::getValue('uni_promo_meseci', '');
        $uniPromoPrice = (string) Tools::getValue('uni_promo_price', '');

        $uniService = (string) Tools::getValue('uni_service', '');
        $uniUser = htmlspecialchars_decode((string) Tools::getValue('uni_user', ''), ENT_QUOTES);
        $uniPassword = htmlspecialchars_decode((string) Tools::getValue('uni_password', ''), ENT_QUOTES);
        $uniSertificat = (string) Tools::getValue('uni_sertificat', 'No');

        $root = method_exists($this->module, 'getLocalPath') ? $this->module->getLocalPath() : (_PS_MODULE_DIR_ . $this->module->name . '/');
        $service = new CheckoutUniCalculationService(rtrim($root, '/'));
        $built = $service->buildAjaxResponse(
            $totalPrice,
            $parva,
            $installments,
            $productCategoryIds,
            $uniEur,
            $currencyIso,
            $uniPromo,
            $uniPromoData,
            $uniPromoMeseciZnak,
            $uniPromoMeseci,
            $uniPromoPrice,
            $uniService,
            $uniUser,
            $uniPassword,
            $uniSertificat
        );

        if ($built !== null) {
            $this->result = $built;
        }

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
}
