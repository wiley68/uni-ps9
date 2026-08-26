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
use PrestaShop\Module\Unipayment\Checkout\CheckoutSubmitLock;
use PrestaShop\Module\Unipayment\Checkout\CheckoutValidationException;
use PrestaShop\Module\Unipayment\Checkout\ConsentResolver;
use PrestaShop\Module\Unipayment\Checkout\CustomerFieldValidator;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationFlags;
use PrestaShop\Module\Unipayment\Order\ControlPanelOrderClientAdapter;
use PrestaShop\Module\Unipayment\Order\ControlPanelOrderPayloadBuilder;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotFactory;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotRepository;
use PrestaShop\Module\Unipayment\Order\NativePrestaShopOrderGateway;
use PrestaShop\Module\Unipayment\Order\OrderAttemptRepository;
use PrestaShop\Module\Unipayment\Order\OrderConfirmationUrlBuilder;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrationException;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrator;
use PrestaShop\Module\Unipayment\Order\Phase10CheckoutOutcome;
use PrestaShop\Module\Unipayment\Order\SensitiveDataCipher;

/**
 * Phase 10 PaymentOption action: durable checkout submission through PS order,
 * financing snapshot, and Control Panel create-order (no SmartUCF yet).
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
            $this->showPreOrderError($this->module->getTranslator()->trans(
                'Заявката за checkout е невалидна.',
                [],
                'Modules.Unipayment.Shop'
            ));

            return;
        }

        $idShop = (int) $this->context->shop->id;
        $idCart = (int) $this->context->cart->id;
        $lock = new CheckoutSubmitLock();
        $lockToken = $lock->acquire($idShop, $idCart);
        if ($lockToken === null) {
            $this->showPreOrderError($this->module->getTranslator()->trans(
                'The request is already being processed. Please wait.',
                [],
                'Modules.Unipayment.Shop'
            ), Phase10CheckoutOutcome::CONCURRENT_REQUEST_IN_PROGRESS);

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
            $request = $validator->validate(
                $shop,
                $cart,
                (string) $this->context->currency->iso_code,
                $this->postedSelection(),
                $module->getCheckoutCustomerData()
            );
            $shop['_is_mobile'] = $this->context->isMobile();
            $cpApi = $module->getControlPanelClient();
            $orchestrator = new OrderOrchestrator(
                new OrderAttemptRepository(),
                new FinancingSnapshotRepository(),
                new NativePrestaShopOrderGateway($module, $this->context),
                new ControlPanelOrderClientAdapter($cpApi),
                new FinancingSnapshotFactory(new SensitiveDataCipher()),
                new ControlPanelOrderPayloadBuilder(),
                new PrestaShop\Module\Unipayment\Order\OrderBankStatusRepository()
            );

            $result = $orchestrator->orchestrate($idShop, $idCart, $request, $shop, 'checkout');
            (new CheckoutPreferenceStore())->clear($this->context->cookie);

            $outcome = Phase10CheckoutOutcome::fromOrchestrationResult($result);
            $this->renderPostOrderSuccess($outcome, $shop);
        } catch (CheckoutValidationException $exception) {
            $lock->release($idShop, $idCart, $lockToken);
            $this->showPreOrderError($exception->getMessage());
        } catch (OrderOrchestrationException $exception) {
            if ($exception->isRetryable()) {
                $lock->release($idShop, $idCart, $lockToken);
            }
            PrestaShopLogger::addLog(
                'UniPayment order orchestration failed: ' . get_class($exception) . '; retryable=' . ($exception->isRetryable() ? '1' : '0'),
                2
            );
            if ($exception->isPostOrder()) {
                (new CheckoutPreferenceStore())->clear($this->context->cookie);
                $outcome = Phase10CheckoutOutcome::fromOrchestrationException($exception);
                $this->renderPostOrderFailure($outcome);

                return;
            }
            $this->showPreOrderError($this->module->getTranslator()->trans(
                $exception->isRetryable()
                    ? 'The financing order could not be submitted. You can safely try again.'
                    : 'The financing order could not be completed.',
                [],
                'Modules.Unipayment.Shop'
            ));
        } catch (Throwable $exception) {
            $lock->release($idShop, $idCart, $lockToken);
            PrestaShopLogger::addLog('UniPayment checkout validation failed: ' . get_class($exception), 2);
            $this->showPreOrderError($this->module->getTranslator()->trans(
                'Изборът на финансиране не може да бъде валидиран.',
                [],
                'Modules.Unipayment.Shop'
            ));
        }
    }

    /** @param array<string, mixed> $shop */
    private function renderPostOrderSuccess(Phase10CheckoutOutcome $outcome, array $shop): void
    {
        /** @var Unipayment $module */
        $module = $this->module;

        if (ShopConfigurationFlags::isProcess2($shop)) {
            Tools::redirect((new OrderConfirmationUrlBuilder())->build($this->context, $module, $outcome->idOrder));

            return;
        }

        $this->context->smarty->assign([
            'unipayment_order_result' => [
                'id_order' => $outcome->idOrder,
                'order_reference' => $outcome->orderReference,
                'control_panel_order_id' => $outcome->controlPanelOrderId,
            ],
            'unipayment_phase10_recovered' => $outcome->code === Phase10CheckoutOutcome::RECOVERED_EXISTING_ORDER,
        ]);
        $this->setTemplate('module:unipayment/views/templates/front/checkout_validated.tpl');
    }

    private function renderPostOrderFailure(Phase10CheckoutOutcome $outcome): void
    {
        /** @var Unipayment $module */
        $module = $this->module;

        if ($outcome->outcomeUnknown) {
            $this->context->smarty->assign([
                'unipayment_order_result' => [
                    'id_order' => $outcome->idOrder,
                    'order_reference' => $outcome->orderReference,
                    'control_panel_order_id' => 0,
                ],
                'unipayment_smartucf_outcome_unknown' => true,
                'unipayment_smartucf_message' => $this->module->getTranslator()->trans(
                    'Поръчката е създадена, но потвърждението от Control Panel не беше получено. Не изпращайте заявката повторно.',
                    [],
                    'Modules.Unipayment.Shop'
                ),
            ]);
            $this->setTemplate('module:unipayment/views/templates/front/checkout_validated.tpl');

            return;
        }

        Tools::redirect((new OrderConfirmationUrlBuilder())->build($this->context, $module, $outcome->idOrder));
    }

    /** @return array<string, mixed> */
    private function postedSelection(): array
    {
        return [
            'scheme_key' => (string) Tools::getValue('unipayment_scheme_key', ''),
            'kop_code' => (string) Tools::getValue('unipayment_kop_code', ''),
            'first_installment' => Tools::getValue('unipayment_first_installment', 0),
            'cart_snapshot' => (string) Tools::getValue('unipayment_cart_snapshot', ''),
            'egn' => (string) Tools::getValue('unipayment_egn', ''),
            'phone2' => (string) Tools::getValue('unipayment_phone2', ''),
            'consent' => Tools::getValue('unipayment_consent', []),
        ];
    }

    private function showPreOrderError(string $message, string $outcomeCode = Phase10CheckoutOutcome::VALIDATION_FAILED_BEFORE_ORDER): void
    {
        unset($outcomeCode);
        $this->context->smarty->assign([
            'unipayment_checkout_error' => $message,
            'unipayment_checkout_return_url' => $this->context->link->getPageLink('order', true),
        ]);
        $this->setTemplate('module:unipayment/views/templates/front/checkout_validation_error.tpl');
    }
}
