<?php

declare(strict_types=1);

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\CurrencyGate;
use PrestaShop\Module\Unipayment\Cart\CartContextFactory;
use PrestaShop\Module\Unipayment\Cart\CartSchemeResolver;
use PrestaShop\Module\Unipayment\Checkout\CartSnapshot;
use PrestaShop\Module\Unipayment\Checkout\CartSnapshotSigner;
use PrestaShop\Module\Unipayment\Checkout\CheckoutLockLoserRecovery;
use PrestaShop\Module\Unipayment\Checkout\CheckoutPaymentValidator;
use PrestaShop\Module\Unipayment\Checkout\CheckoutPreferenceStore;
use PrestaShop\Module\Unipayment\Checkout\CheckoutSubmitLock;
use PrestaShop\Module\Unipayment\Checkout\CheckoutValidationException;
use PrestaShop\Module\Unipayment\Checkout\ConsentResolver;
use PrestaShop\Module\Unipayment\Checkout\CustomerFieldValidator;
use PrestaShop\Module\Unipayment\Order\ControlPanelOrderClientAdapter;
use PrestaShop\Module\Unipayment\Order\ControlPanelOrderPayloadBuilder;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotFactory;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotRepository;
use PrestaShop\Module\Unipayment\Order\NativePrestaShopOrderGateway;
use PrestaShop\Module\Unipayment\Order\OrderAttemptRepository;
use PrestaShop\Module\Unipayment\Order\OrderConfirmationUrlBuilder;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrationException;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrator;
use PrestaShop\Module\Unipayment\Order\PostControlPanelLifecycleContext;
use PrestaShop\Module\Unipayment\Order\PostControlPanelLifecycleService;
use PrestaShop\Module\Unipayment\Order\SensitiveDataCipher;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfSessionCoordinator;

/**
 * Phase 11 PaymentOption action: durable PS/CP order (Phase 10) then post-CP lifecycle.
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
            $this->handleLockLoser($idShop, $idCart);

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
            $cpClient = new ControlPanelOrderClientAdapter($cpApi);
            $orchestrator = new OrderOrchestrator(
                new OrderAttemptRepository(),
                new FinancingSnapshotRepository(),
                new NativePrestaShopOrderGateway($module, $this->context),
                $cpClient,
                new FinancingSnapshotFactory(new SensitiveDataCipher()),
                new ControlPanelOrderPayloadBuilder(),
                new PrestaShop\Module\Unipayment\Order\OrderBankStatusRepository()
            );

            $result = $orchestrator->orchestrate($idShop, $idCart, $request, $shop, 'checkout');
            (new CheckoutPreferenceStore())->clear($this->context->cookie);

            // Phase 11: post-CP lifecycle only after durable CP order exists (cp_created).
            $lifecycle = (new PostControlPanelLifecycleService())->handle(
                $result,
                $shop,
                new PostControlPanelLifecycleContext(
                    $idShop,
                    (string) $this->context->currency->iso_code
                ),
                new SmartUcfSessionCoordinator(
                    null,
                    null,
                    null,
                    null,
                    null,
                    $cpClient,
                    $module,
                    $this->context,
                    $cpApi
                )
            );

            if ($lifecycle->isProcess2()) {
                Tools::redirect(
                    (new OrderConfirmationUrlBuilder())->build($this->context, $module, $result->idOrder)
                );

                return;
            }

            if ($lifecycle->isCreated() && $lifecycle->redirectUrl() !== '') {
                Tools::redirect($lifecycle->redirectUrl());

                return;
            }

            if ($lifecycle->isFailed()) {
                Tools::redirect(
                    (new OrderConfirmationUrlBuilder())->build($this->context, $module, $result->idOrder)
                );

                return;
            }

            $orderResult = [
                'id_order' => $result->idOrder,
                'order_reference' => $result->orderReference,
                'control_panel_order_id' => $result->controlPanelOrderId,
            ];

            if ($lifecycle->isProcessing()) {
                $this->context->smarty->assign([
                    'unipayment_order_result' => $orderResult,
                    'unipayment_smartucf_processing' => true,
                    'unipayment_smartucf_message' => $lifecycle->customerMessage(),
                ]);
                $this->setTemplate('module:unipayment/views/templates/front/checkout_validated.tpl');

                return;
            }

            if ($lifecycle->isOutcomeUnknown()) {
                $this->context->smarty->assign([
                    'unipayment_order_result' => $orderResult,
                    'unipayment_smartucf_outcome_unknown' => true,
                    'unipayment_smartucf_message' => $lifecycle->customerMessage(),
                ]);
                $this->setTemplate('module:unipayment/views/templates/front/checkout_validated.tpl');

                return;
            }

            $this->context->smarty->assign(['unipayment_order_result' => $orderResult]);
            $this->setTemplate('module:unipayment/views/templates/front/checkout_validated.tpl');
        } catch (CheckoutValidationException $exception) {
            $lock->release($idShop, $idCart, $lockToken);
            $this->showPreOrderError($exception->getMessage());
        } catch (OrderOrchestrationException $exception) {
            if ($exception->isRetryable()) {
                $lock->release($idShop, $idCart, $lockToken);
            }
            PrestaShopLogger::addLog(
                'UniPayment order orchestration failed: ' . get_class($exception)
                    . '; retryable=' . ($exception->isRetryable() ? '1' : '0')
                    . '; post_order=' . ($exception->isPostOrder() ? '1' : '0')
                    . '; id_order=' . $exception->idOrder()
                    . '; id_attempt=' . $exception->attemptId()
                    . '; state=' . $exception->state(),
                2
            );
            if ($exception->isPostOrder()) {
                (new CheckoutPreferenceStore())->clear($this->context->cookie);
                /** @var Unipayment $module */
                $module = $this->module;
                Tools::redirect(
                    (new OrderConfirmationUrlBuilder())->build($this->context, $module, $exception->idOrder())
                );

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
            $recoveredOrderId = (int) Order::getIdByCartId($idCart);
            if ($recoveredOrderId > 0) {
                (new CheckoutPreferenceStore())->clear($this->context->cookie);
                /** @var Unipayment $module */
                $module = $this->module;
                Tools::redirect(
                    (new OrderConfirmationUrlBuilder())->build($this->context, $module, $recoveredOrderId)
                );

                return;
            }
            $this->showPreOrderError($this->module->getTranslator()->trans(
                'Изборът на финансиране не може да бъде валидиран.',
                [],
                'Modules.Unipayment.Shop'
            ));
        }
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

    private function handleLockLoser(int $idShop, int $idCart): void
    {
        $recovery = (new CheckoutLockLoserRecovery())->resolve($idShop, $idCart);
        $kind = (string) ($recovery['kind'] ?? CheckoutLockLoserRecovery::KIND_PROCESSING);

        if ($kind === CheckoutLockLoserRecovery::KIND_SMARTUCF_REDIRECT) {
            $url = (string) ($recovery['redirect_url'] ?? '');
            if ($url !== '') {
                (new CheckoutPreferenceStore())->clear($this->context->cookie);
                Tools::redirect($url);

                return;
            }
        }

        if (
            $kind === CheckoutLockLoserRecovery::KIND_CONFIRMATION
            && (int) ($recovery['id_order'] ?? 0) > 0
        ) {
            (new CheckoutPreferenceStore())->clear($this->context->cookie);
            /** @var Unipayment $module */
            $module = $this->module;
            Tools::redirect(
                (new OrderConfirmationUrlBuilder())->build(
                    $this->context,
                    $module,
                    (int) $recovery['id_order']
                )
            );

            return;
        }

        if ((int) ($recovery['id_order'] ?? 0) > 0) {
            (new CheckoutPreferenceStore())->clear($this->context->cookie);
            $orderResult = [
                'id_order' => (int) $recovery['id_order'],
                'order_reference' => (string) ($recovery['order_reference'] ?? ''),
                'control_panel_order_id' => (int) ($recovery['control_panel_order_id'] ?? 0),
            ];
            if ($kind === CheckoutLockLoserRecovery::KIND_OUTCOME_UNKNOWN) {
                $this->context->smarty->assign([
                    'unipayment_order_result' => $orderResult,
                    'unipayment_smartucf_outcome_unknown' => true,
                    'unipayment_smartucf_message' => (string) ($recovery['message'] ?? ''),
                ]);
            } else {
                $this->context->smarty->assign([
                    'unipayment_order_result' => $orderResult,
                    'unipayment_smartucf_processing' => true,
                    'unipayment_smartucf_message' => (string) ($recovery['message'] ?? ''),
                ]);
            }
            $this->setTemplate('module:unipayment/views/templates/front/checkout_validated.tpl');

            return;
        }

        // True pre-order lock contention: neutral processing, no resubmit CTA.
        $this->context->smarty->assign([
            'unipayment_checkout_processing_message' => $this->module->getTranslator()->trans(
                'Your financing request is currently being processed.',
                [],
                'Modules.Unipayment.Shop'
            ),
        ]);
        $this->setTemplate('module:unipayment/views/templates/front/checkout_processing.tpl');
    }

    private function showPreOrderError(string $message): void
    {
        $this->context->smarty->assign([
            'unipayment_checkout_error' => $message,
            'unipayment_checkout_return_url' => $this->context->link->getPageLink('order', true),
        ]);
        $this->setTemplate('module:unipayment/views/templates/front/checkout_validation_error.tpl');
    }
}
