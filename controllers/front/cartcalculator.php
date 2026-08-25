<?php

declare(strict_types=1);

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Cart\CartCalculatorPresenter;
use PrestaShop\Module\Unipayment\Cart\CartContextFactory;
use PrestaShop\Module\Unipayment\Cart\CartSchemeResolver;

/**
 * AJAX cart financing presentation refresh.
 * Authoritative amount comes from Context cart (getOrderTotal BOTH), never from client POST.
 */
final class UnipaymentCartCalculatorModuleFrontController extends ModuleFrontController
{
    public $ajax = true;

    public function displayAjax(): void
    {
        $this->ajaxRender(json_encode($this->response(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    private function response(): array
    {
        try {
            $repository = new PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository();
            if (!$this->module->active || !$repository->isEnabled()) {
                return ['success' => true, 'calculator' => null];
            }
            $cart = $this->context->cart;
            if (!$cart instanceof Cart || (int) $cart->id <= 0) {
                return ['success' => true, 'calculator' => null];
            }
            /** @var Unipayment $module */
            $module = $this->module;
            $shop = $module->getShopConfigurationService()->get();
            $calculator = new Calculator();
            $view = (new CartCalculatorPresenter(new CartSchemeResolver($calculator), $calculator))->present(
                $shop,
                (new CartContextFactory())->create($cart),
                (string) $this->context->currency->iso_code
            );

            return ['success' => true, 'calculator' => $view];
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog('UniPayment cart calculator request failed: ' . get_class($exception), 2);
            http_response_code(422);

            return ['success' => false, 'message' => 'Calculator data is unavailable.'];
        }
    }
}
