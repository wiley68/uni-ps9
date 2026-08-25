<?php

declare(strict_types=1);

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Product\ProductCalculatorPresenter;
use PrestaShop\Module\Unipayment\Product\ProductContextFactory;

final class UnipaymentProductCalculatorModuleFrontController extends ModuleFrontController
{
    public $ajax = true;

    public function displayAjax(): void
    {
        $this->ajaxRender(json_encode($this->response(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    private function response(): array
    {
        if (!$this->module->active || !Tools::getIsset('id_product')) {
            return $this->errorResponse(400, 'Invalid calculator request.');
        }

        $productId = filter_var(Tools::getValue('id_product'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $attributeValue = Tools::getValue('id_product_attribute', 0);
        $attributeId = filter_var($attributeValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $quantity = filter_var(Tools::getValue('quantity', 1), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($productId === false || $attributeId === false || $quantity === false) {
            return $this->errorResponse(400, 'Invalid calculator request.');
        }

        try {
            $repository = new PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository();
            if (!$repository->isEnabled()) {
                return ['success' => true, 'calculator' => null];
            }
            /** @var Unipayment $module */
            $module = $this->module;
            $shop = $module->getShopConfigurationService()->get();
            $product = (new ProductContextFactory())->create((int) $productId, (int) $attributeId, (int) $quantity);
            $calculator = (new ProductCalculatorPresenter(new Calculator()))->present(
                $shop,
                $product,
                (string) $this->context->currency->iso_code
            );

            return ['success' => true, 'calculator' => $calculator];
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog('UniPayment product calculator request failed: ' . get_class($exception), 2);

            return $this->errorResponse(422, 'Calculator data is unavailable.');
        }
    }

    /** @return array{success:bool,message:string} */
    private function errorResponse(int $status, string $message): array
    {
        http_response_code($status);

        return ['success' => false, 'message' => $message];
    }
}
