<?php

declare(strict_types=1);

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\UnavailableSchemeException;
use PrestaShop\Module\Unipayment\Product\ProductContextFactory;
use PrestaShop\Module\Unipayment\Product\ProductPopupCalculator;

/**
 * Phase 6: calculate-only product popup endpoint.
 * Apply / preselect / submission / validate_step2 are deferred to later phases.
 */
final class UnipaymentProductPopupModuleFrontController extends ModuleFrontController
{
    public $ajax = true;

    public function displayAjax(): void
    {
        $this->ajaxRender(json_encode($this->response(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    private function response(): array
    {
        if (
            $_SERVER['REQUEST_METHOD'] !== 'POST'
            || !$this->module->active
            || !hash_equals(Tools::getToken(false), (string) Tools::getValue('token', ''))
        ) {
            return $this->error(403, 'Invalid popup request.');
        }

        $productId = filter_var(Tools::getValue('id_product'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $attributeId = filter_var(Tools::getValue('id_product_attribute', 0), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $quantity = filter_var(Tools::getValue('quantity', 1), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $months = filter_var(Tools::getValue('months'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $filterId = filter_var(Tools::getValue('filter_id', 0), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $popupType = (string) Tools::getValue('popup_offer_type', '');
        $schemeType = (string) Tools::getValue('scheme_type', '');
        $kopCode = trim((string) Tools::getValue('kop_code', ''));
        $schemeKey = trim((string) Tools::getValue('scheme_key', ''));
        $firstRaw = Tools::getValue('first_installment', 0);
        if (
            $productId === false || $attributeId === false || $quantity === false || $months === false || $filterId === false
            || !in_array($popupType, ['standard', 'promo'], true) || !in_array($schemeType, ['standard', 'promo'], true)
            || $kopCode === '' || $schemeKey === '' || !is_numeric($firstRaw)
        ) {
            return $this->error(400, 'Invalid popup selection.');
        }

        $action = (string) Tools::getValue('popup_action', 'calculate');
        if ($action !== 'calculate') {
            return $this->error(501, 'This action is not available yet.');
        }

        try {
            $repository = new PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository();
            if (!$repository->isEnabled()) {
                return $this->error(403, 'The module is unavailable.');
            }
            /** @var Unipayment $module */
            $module = $this->module;
            $shop = $module->getShopConfigurationService()->get();
            $product = (new ProductContextFactory())->create((int) $productId, (int) $attributeId, (int) $quantity);
            $calculation = (new ProductPopupCalculator(new Calculator()))->calculate(
                $shop,
                $product,
                (string) $this->context->currency->iso_code,
                $popupType,
                $schemeType,
                $kopCode,
                (int) $months,
                (int) $filterId,
                $schemeKey,
                (float) $firstRaw
            );

            return ['success' => true, 'calculation' => $calculation];
        } catch (UnavailableSchemeException $exception) {
            PrestaShopLogger::addLog('UniPayment product popup scheme unavailable: ' . get_class($exception), 2);

            return $this->error(422, 'The selected financing scheme is unavailable.');
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog('UniPayment product popup request failed: ' . get_class($exception), 2);

            return $this->error(422, 'Calculator data is unavailable.');
        }
    }

    /** @return array{success:bool,message:string} */
    private function error(int $status, string $message): array
    {
        http_response_code($status);

        return ['success' => false, 'message' => $message];
    }
}
