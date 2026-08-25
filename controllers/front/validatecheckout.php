<?php

declare(strict_types=1);

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\CurrencyGate;
use PrestaShop\Module\Unipayment\Cart\CartContextFactory;
use PrestaShop\Module\Unipayment\Cart\CartSchemeResolver;
use PrestaShop\Module\Unipayment\Checkout\CartSnapshot;
use PrestaShop\Module\Unipayment\Checkout\CartSnapshotSigner;
use PrestaShop\Module\Unipayment\Checkout\CheckoutPaymentValidator;
use PrestaShop\Module\Unipayment\Checkout\CheckoutPreferenceStore;
use PrestaShop\Module\Unipayment\Checkout\CheckoutValidationException;
use PrestaShop\Module\Unipayment\Checkout\ConsentResolver;
use PrestaShop\Module\Unipayment\Checkout\CustomerFieldValidator;

/**
 * Phase 9 PaymentOption action boundary.
 *
 * Validates checkout financing selection / cart fingerprint, then stops without
 * creating PrestaShop or Control Panel orders (Phase 10).
 */
final class UnipaymentValidateCheckoutModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess(): void
    {
        if (
            !$this->module->active
            || !Tools::isSubmit('unipayment_checkout_submit')
            || !Tools::getIsset('unipayment_checkout_token')
            || !hash_equals(Tools::getToken(false), (string) Tools::getValue('unipayment_checkout_token'))
        ) {
            $this->showError($this->module->getTranslator()->trans(
                'Заявката за checkout е невалидна.',
                [],
                'Modules.Unipayment.Shop'
            ));

            return;
        }

        try {
            $repository = new PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository();
            if (!$repository->isEnabled()) {
                throw new CheckoutValidationException('This payment method is disabled.');
            }
            /** @var Unipayment $module */
            $module = $this->module;
            $shop = $module->getShopConfigurationService()->get();
            $cart = (new CartContextFactory())->createForCheckout($this->context->cart);
            $calculator = new Calculator();
            $validator = new CheckoutPaymentValidator(
                $calculator,
                new CartSchemeResolver($calculator),
                new CurrencyGate(),
                new CartSnapshot(),
                new CartSnapshotSigner(_COOKIE_KEY_),
                new CustomerFieldValidator(),
                new ConsentResolver()
            );
            // Validates selection + fingerprint + consents; does not create orders.
            $validator->validate(
                $shop,
                $cart,
                (string) $this->context->currency->iso_code,
                $this->postedSelection(),
                $module->getCheckoutCustomerData()
            );
            (new CheckoutPreferenceStore())->clear($this->context->cookie);
        } catch (CheckoutValidationException $exception) {
            $this->showError($exception->getMessage());

            return;
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog('UniPayment Phase 9 validatecheckout failed: ' . get_class($exception), 2);
            $this->showError($this->module->getTranslator()->trans(
                'Заявката не може да бъде обработена. Моля, опитайте отново.',
                [],
                'Modules.Unipayment.Shop'
            ));

            return;
        }

        // Phase 9 hard boundary: selection is valid, but order/CP/SmartUCF are Phase 10.
        $this->showError($this->module->getTranslator()->trans(
            'Изборът на финансиране е валиден, но създаването на поръчка все още не е активирано. Моля, опитайте по-късно.',
            [],
            'Modules.Unipayment.Shop'
        ));
    }

    /** @return array<string, mixed> */
    private function postedSelection(): array
    {
        return [
            'scheme_key' => Tools::getValue('unipayment_scheme_key', ''),
            'kop_code' => Tools::getValue('unipayment_kop_code', ''),
            'first_installment' => Tools::getValue('unipayment_first_installment', 0),
            'cart_snapshot' => Tools::getValue('unipayment_cart_snapshot', ''),
            'egn' => Tools::getValue('unipayment_egn', ''),
            'phone2' => Tools::getValue('unipayment_phone2', ''),
            'consent' => Tools::getValue('unipayment_consent', []),
        ];
    }

    private function showError(string $message): void
    {
        $this->context->smarty->assign([
            'unipayment_checkout_error' => $message,
            'unipayment_checkout_return_url' => $this->context->link->getPageLink('order', true),
        ]);
        $this->setTemplate('module:unipayment/views/templates/front/checkout_validation_error.tpl');
    }
}
