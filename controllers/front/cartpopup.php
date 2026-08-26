<?php

declare(strict_types=1);

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\UnavailableSchemeException;
use PrestaShop\Module\Unipayment\Cart\CartContextFactory;
use PrestaShop\Module\Unipayment\Cart\CartPopupApplyService;
use PrestaShop\Module\Unipayment\Cart\CartPopupCalculator;
use PrestaShop\Module\Unipayment\Cart\CartSchemeResolver;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationFlags;
use PrestaShop\Module\Unipayment\Order\ControlPanelOrderClientAdapter;
use PrestaShop\Module\Unipayment\Order\ControlPanelOrderPayloadBuilder;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotFactory;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotRepository;
use PrestaShop\Module\Unipayment\Order\NativePrestaShopOrderGateway;
use PrestaShop\Module\Unipayment\Order\OrderAttemptRepository;
use PrestaShop\Module\Unipayment\Order\OrderConfirmationUrlBuilder;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrationException;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrationResult;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrator;
use PrestaShop\Module\Unipayment\Order\PostControlPanelLifecycleContext;
use PrestaShop\Module\Unipayment\Order\PostControlPanelLifecyclePopupMapper;
use PrestaShop\Module\Unipayment\Order\PostControlPanelLifecycleService;
use PrestaShop\Module\Unipayment\Order\PostOrderPopupFailureResponse;
use PrestaShop\Module\Unipayment\Order\SensitiveDataCipher;
use PrestaShop\Module\Unipayment\Product\GuestCustomerFactory;
use PrestaShop\Module\Unipayment\Product\PopupSubmissionBindingFactory;
use PrestaShop\Module\Unipayment\Product\PopupSubmissionRepository;
use PrestaShop\Module\Unipayment\Product\PopupSubmissionSelectionHash;
use PrestaShop\Module\Unipayment\Product\PopupSubmissionStates;
use PrestaShop\Module\Unipayment\Product\ProductPopupCustomerValidator;
use PrestaShop\Module\Unipayment\Product\ProductPopupOperationGuard;
use PrestaShop\Module\Unipayment\Product\ProductPopupValidationException;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfSessionCoordinator;

/**
 * Cart popup: calculate / issue_submission_token / validate_step2 / apply.
 * Apply completes the same durable financing lifecycle as checkout (Phases 10–12).
 */
final class UnipaymentCartPopupModuleFrontController extends ModuleFrontController
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

        $months = filter_var(Tools::getValue('months'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $filterId = filter_var(Tools::getValue('filter_id', 0), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $popupType = (string) Tools::getValue('popup_offer_type', '');
        $schemeType = (string) Tools::getValue('scheme_type', '');
        $kopCode = trim((string) Tools::getValue('kop_code', ''));
        $schemeKey = trim((string) Tools::getValue('scheme_key', ''));
        $firstRaw = Tools::getValue('first_installment', 0);
        if (
            $months === false || $filterId === false
            || !in_array($popupType, ['standard', 'promo'], true) || !in_array($schemeType, ['standard', 'promo'], true)
            || $kopCode === '' || $schemeKey === '' || !is_numeric($firstRaw)
        ) {
            return $this->error(400, 'Invalid popup selection.');
        }

        try {
            $repository = new PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository();
            if (!$repository->isEnabled()) {
                return $this->error(403, 'The module is unavailable.');
            }
            $cart = $this->context->cart;
            if (!$cart instanceof Cart || (int) $cart->id <= 0) {
                return $this->error(422, 'The financing selection is unavailable.');
            }
            /** @var Unipayment $module */
            $module = $this->module;
            $shop = $module->getShopConfigurationService()->get();
            $calculator = new Calculator();
            $resolver = new CartSchemeResolver($calculator);
            $cartContext = (new CartContextFactory())->create($cart);
            if ($cartContext->lines === []) {
                return $this->error(422, 'The financing selection is unavailable.');
            }
            $popupCalculator = new CartPopupCalculator($calculator, $resolver);
            $calculation = $popupCalculator->calculate(
                $shop,
                $cartContext,
                (string) $this->context->currency->iso_code,
                $popupType,
                $schemeType,
                $kopCode,
                (int) $months,
                (int) $filterId,
                $schemeKey,
                (float) $firstRaw
            );

            $action = (string) Tools::getValue('popup_action', 'calculate');
            if ($action === 'issue_submission_token') {
                return $this->handleIssueSubmissionToken($calculation, $cart, $cartContext);
            }
            if ($action === 'apply') {
                return $this->handleApply($shop, $calculation, $cart, $cartContext, $calculator, $popupCalculator);
            }
            if ($action === 'validate_step2') {
                $requireEgn = ((int) ($shop['uni_proces'] ?? 0)) === 1;
                $customer = (new ProductPopupCustomerValidator())->validate([
                    'first_name' => Tools::getValue('first_name', ''),
                    'last_name' => Tools::getValue('last_name', ''),
                    'address' => Tools::getValue('address', ''),
                    'phone' => Tools::getValue('phone', ''),
                    'email' => Tools::getValue('email', ''),
                    'egn' => Tools::getValue('egn', ''),
                    'phone2' => Tools::getValue('phone2', ''),
                ], $requireEgn);
                unset($customer['egn']);

                return [
                    'success' => true,
                    'step' => 'final_placeholder',
                    'calculation' => $calculation,
                    'customer' => $customer,
                ];
            }
            if ($action !== 'calculate') {
                return $this->error(400, 'Невалидно действие на калкулатора.');
            }

            return ['success' => true, 'calculation' => $calculation];
        } catch (ProductPopupValidationException $exception) {
            http_response_code(422);
            $errors = $exception->errors();

            return [
                'success' => false,
                'message' => $this->customerValidationMessage($errors),
                'errors' => $errors,
            ];
        } catch (UnavailableSchemeException $exception) {
            return $this->error(422, 'The financing selection is unavailable.');
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog(
                'UniPayment cart popup request failed: ' . get_class($exception) . ' ' . $this->sanitizeExceptionMessage($exception),
                2
            );

            return $this->error(500, 'Заявката не може да бъде обработена. Моля, опитайте отново.');
        }
    }

    /**
     * @param array<string, mixed> $calculation
     * @return array<string, mixed>
     */
    private function handleIssueSubmissionToken(
        array $calculation,
        Cart $cart,
        \PrestaShop\Module\Unipayment\Cart\CartContext $cartContext
    ): array {
        $resolved = (new PopupSubmissionBindingFactory())->fromCartSelection([
            'id_cart' => (int) $cart->id,
            'cart_total' => $cartContext->total,
            'scheme_type' => (string) ($calculation['scheme_type'] ?? ''),
            'kop_code' => (string) ($calculation['kop_code'] ?? ''),
            'months' => (int) ($calculation['months'] ?? 0),
            'filter_id' => (int) ($calculation['filter_id'] ?? 0),
            'scheme_key' => (string) ($calculation['scheme_key'] ?? trim((string) Tools::getValue('scheme_key', ''))),
            'first_installment' => $calculation['first_installment'] ?? 0,
        ], $this->context);

        $preferred = trim((string) Tools::getValue('popup_submission_token', ''));
        $row = (new PopupSubmissionRepository())->issueOrReuse(
            (int) $this->context->shop->id,
            $resolved['hash'],
            $resolved['id_guest'],
            $resolved['id_customer'],
            $preferred,
            PopupSubmissionSelectionHash::FLOW_CART_POPUP
        );

        return [
            'success' => true,
            'step' => 'submission_token_issued',
            'popup_submission_token' => (string) $row['submission_token'],
            'calculation' => $calculation,
        ];
    }

    /**
     * @param array<string, mixed> $shop
     * @param array<string, mixed> $calculation
     * @return array<string, mixed>
     */
    private function handleApply(
        array $shop,
        array $calculation,
        Cart $cart,
        \PrestaShop\Module\Unipayment\Cart\CartContext $cartContext,
        Calculator $calculator,
        CartPopupCalculator $popupCalculator
    ): array {
        $posted = [
            'popup_offer_type' => Tools::getValue('popup_offer_type', ''),
            'scheme_type' => Tools::getValue('scheme_type', ''),
            'kop_code' => Tools::getValue('kop_code', ''),
            'months' => Tools::getValue('months', 0),
            'filter_id' => Tools::getValue('filter_id', 0),
            'scheme_key' => Tools::getValue('scheme_key', ''),
            'first_installment' => Tools::getValue('first_installment', 0),
            'first_name' => Tools::getValue('first_name', ''),
            'last_name' => Tools::getValue('last_name', ''),
            'address' => Tools::getValue('address', ''),
            'phone' => Tools::getValue('phone', ''),
            'email' => Tools::getValue('email', ''),
            'egn' => Tools::getValue('egn', ''),
            'phone2' => Tools::getValue('phone2', ''),
            'id_address' => Tools::getValue('id_address', 0),
            'consent' => Tools::getValue('unipayment_consent', []),
        ];

        $token = trim((string) Tools::getValue('popup_submission_token', ''));
        $submissions = new PopupSubmissionRepository();
        $resolved = (new PopupSubmissionBindingFactory())->fromCartSelection([
            'id_cart' => (int) $cart->id,
            'cart_total' => $cartContext->total,
            'scheme_type' => (string) $posted['scheme_type'],
            'kop_code' => (string) $posted['kop_code'],
            'months' => (int) $posted['months'],
            'filter_id' => (int) $posted['filter_id'],
            'scheme_key' => (string) $posted['scheme_key'],
            'first_installment' => $posted['first_installment'],
        ], $this->context);

        /** @var Unipayment $module */
        $module = $this->module;
        $cpApi = $module->getControlPanelClient();
        $cpClient = new ControlPanelOrderClientAdapter($cpApi);

        $gate = $this->resolvePopupSubmissionGate($submissions, $token, $resolved['hash'], $shop, $module, $cpClient);
        if (isset($gate['response'])) {
            return $gate['response'];
        }

        /** @var array<string, mixed> $submission */
        $submission = $gate['submission'];
        $submissionId = (int) $submission['id_submission'];

        $orchestrator = new OrderOrchestrator(
            new OrderAttemptRepository(),
            new FinancingSnapshotRepository(),
            new NativePrestaShopOrderGateway($module, $this->context),
            $cpClient,
            new FinancingSnapshotFactory(new SensitiveDataCipher()),
            new ControlPanelOrderPayloadBuilder(),
            new PrestaShop\Module\Unipayment\Order\OrderBankStatusRepository()
        );
        $service = new CartPopupApplyService(
            $calculator,
            $popupCalculator,
            new ProductPopupCustomerValidator(),
            new GuestCustomerFactory(),
            $orchestrator
        );

        try {
            if ((int) ($submission['id_cart'] ?? 0) <= 0) {
                $submissions->attachCart($submissionId, (int) $cart->id);
            }

            $result = $service->apply($shop, $posted, $cartContext, $this->context);

            $submissions->markOrderCreated(
                $submissionId,
                $result->attemptId,
                $result->idOrder,
                $result->orderReference,
                $result->controlPanelOrderId
            );

            return $this->buildApplySuccessResponse($shop, $module, $cpClient, $result, true);
        } catch (ProductPopupValidationException $exception) {
            $submissions->revertProcessingWithoutCart($submissionId);
            http_response_code(422);
            $errors = $exception->errors();

            return [
                'success' => false,
                'message' => $this->customerValidationMessage($errors),
                'errors' => $errors,
            ];
        } catch (UnavailableSchemeException $exception) {
            $submissions->revertProcessingWithoutCart($submissionId);
            http_response_code(422);

            return ['success' => false, 'message' => 'The financing selection is unavailable.'];
        } catch (OrderOrchestrationException $exception) {
            PrestaShopLogger::addLog('UniPayment cart popup apply orchestration failed: ' . get_class($exception), 2);
            if ($exception->isPostOrder()) {
                $submissions->markOrderCreated(
                    $submissionId,
                    $exception->attemptId(),
                    $exception->idOrder(),
                    $exception->orderReference(),
                    0
                );

                return PostOrderPopupFailureResponse::fromException($exception);
            }
            if ($exception->isRetryable()) {
                $submissions->revertProcessingWithoutCart($submissionId);
            } else {
                $submissions->markFailed($submissionId);
            }
            http_response_code(500);

            return ['success' => false, 'message' => 'The financing request could not be processed. Please try again.'];
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog(
                'UniPayment cart popup apply failed: ' . get_class($exception) . ' ' . $this->sanitizeExceptionMessage($exception),
                2
            );
            $rowAfter = $submissions->findByToken($token);
            if (is_array($rowAfter) && (int) ($rowAfter['id_cart'] ?? 0) <= 0) {
                $submissions->revertProcessingWithoutCart($submissionId);
            } else {
                $submissions->markFailed($submissionId);
            }
            http_response_code(500);

            return [
                'success' => false,
                'message' => 'Заявката не може да бъде обработена. Моля, опитайте отново.',
            ];
        }
    }

    /**
     * @param array<string, mixed> $shop
     * @return array{response?: array<string, mixed>, submission?: array<string, mixed>}
     */
    private function resolvePopupSubmissionGate(
        PopupSubmissionRepository $submissions,
        string $token,
        string $selectionHash,
        array $shop,
        Unipayment $module,
        ControlPanelOrderClientAdapter $cpClient
    ): array {
        if ($token === '') {
            http_response_code(400);

            return ['response' => ['success' => false, 'message' => 'Missing popup submission token.']];
        }

        $row = $submissions->findByToken($token);
        if ($row === null) {
            http_response_code(400);

            return ['response' => ['success' => false, 'message' => 'Invalid popup submission token.']];
        }

        if (!hash_equals((string) $row['selection_hash'], $selectionHash)) {
            http_response_code(409);

            return [
                'response' => [
                    'success' => false,
                    'message' => 'The financing selection changed. Please continue from Step 1.',
                    'selection_changed' => true,
                ],
            ];
        }

        $idGuest = (int) ($this->context->cookie->id_guest ?? 0);
        $idCustomer = (int) ($this->context->customer->id ?? 0);
        if (!PopupSubmissionRepository::identityMatches($row, $idGuest, $idCustomer)) {
            http_response_code(403);

            return ['response' => ['success' => false, 'message' => 'Invalid popup submission token.']];
        }

        $state = (string) $row['state'];
        if ($state === PopupSubmissionStates::ORDER_CREATED && (int) ($row['id_order'] ?? 0) > 0) {
            return [
                'response' => $this->existingOrderResponse($row, $shop, $module, $cpClient),
            ];
        }

        if ($state === PopupSubmissionStates::FAILED) {
            http_response_code(409);

            return [
                'response' => [
                    'success' => false,
                    'message' => 'This financing submission can no longer be used. Please start again.',
                ],
            ];
        }

        if ($state === PopupSubmissionStates::IDENTITY_ACCEPTED) {
            // Legacy Phase 7 terminal rows: do not invent a fresh order from this token.
            return [
                'response' => (new ProductPopupOperationGuard($submissions))->identityAcceptedResponse($row, true),
            ];
        }

        if ($state === PopupSubmissionStates::PROCESSING) {
            $idCart = (int) ($row['id_cart'] ?? 0);
            if ($idCart <= 0) {
                return ['response' => $this->processingResponse($token)];
            }

            return ['submission' => $row];
        }

        if ($state === PopupSubmissionStates::ISSUED) {
            if ($submissions->isExpired($row)) {
                http_response_code(409);

                return [
                    'response' => [
                        'success' => false,
                        'message' => 'The popup submission token expired. Please continue from Step 1.',
                    ],
                ];
            }

            $claimed = $submissions->claimForProcessing($token);
            if ($claimed !== null) {
                return ['submission' => $claimed];
            }

            $latest = $submissions->findByToken($token);
            if (is_array($latest) && (string) $latest['state'] === PopupSubmissionStates::ORDER_CREATED) {
                return ['response' => $this->existingOrderResponse($latest, $shop, $module, $cpClient)];
            }
            if (is_array($latest) && (string) $latest['state'] === PopupSubmissionStates::PROCESSING) {
                if ((int) ($latest['id_cart'] ?? 0) > 0) {
                    return ['submission' => $latest];
                }

                return ['response' => $this->processingResponse($token)];
            }

            return ['response' => $this->processingResponse($token)];
        }

        http_response_code(409);

        return [
            'response' => [
                'success' => false,
                'message' => 'The popup submission is in an unknown state.',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $shop
     * @return array<string, mixed>
     */
    private function existingOrderResponse(
        array $row,
        array $shop,
        Unipayment $module,
        ControlPanelOrderClientAdapter $cpClient
    ): array {
        $response = [
            'success' => true,
            'step' => 'order_created',
            'replay' => true,
            'popup_submission_token' => (string) $row['submission_token'],
            'order' => [
                'id_order' => (int) $row['id_order'],
                'order_reference' => (string) $row['order_reference'],
                'control_panel_order_id' => (int) ($row['control_panel_order_id'] ?? 0),
                'id_attempt' => (int) ($row['id_attempt'] ?? 0),
            ],
        ];

        if ((int) ($row['control_panel_order_id'] ?? 0) <= 0 && (int) ($row['id_order'] ?? 0) > 0) {
            return PostOrderPopupFailureResponse::fromPersistedOrder(
                (int) $row['id_order'],
                (string) $row['order_reference']
            );
        }

        $attemptId = (int) ($row['id_attempt'] ?? 0);
        if ($attemptId <= 0) {
            return $response;
        }

        $lifecycle = $this->runPostControlPanelLifecycle(
            new OrderOrchestrationResult(
                $attemptId,
                'cp_created',
                (int) $row['id_order'],
                (string) $row['order_reference'],
                (int) ($row['control_panel_order_id'] ?? 0)
            ),
            $shop,
            $module,
            $cpClient,
            true
        );
        if (ShopConfigurationFlags::isProcess2($shop)) {
            $response['redirect_url'] = (new OrderConfirmationUrlBuilder())->build(
                $this->context,
                $module,
                (int) $row['id_order']
            );
        }
        PostControlPanelLifecyclePopupMapper::apply($response, $lifecycle);

        return $response;
    }

    /**
     * @param array<string, mixed> $shop
     * @return array<string, mixed>
     */
    private function buildApplySuccessResponse(
        array $shop,
        Unipayment $module,
        ControlPanelOrderClientAdapter $cpClient,
        OrderOrchestrationResult $result,
        bool $runPostOrderSteps
    ): array {
        $response = [
            'success' => true,
            'step' => 'order_created',
            'order' => [
                'id_order' => $result->idOrder,
                'order_reference' => $result->orderReference,
                'control_panel_order_id' => $result->controlPanelOrderId,
            ],
        ];

        if (!$runPostOrderSteps) {
            return $response;
        }

        $lifecycle = $this->runPostControlPanelLifecycle($result, $shop, $module, $cpClient, false);
        if (ShopConfigurationFlags::isProcess2($shop)) {
            $response['redirect_url'] = (new OrderConfirmationUrlBuilder())->build(
                $this->context,
                $module,
                $result->idOrder
            );
        }
        PostControlPanelLifecyclePopupMapper::apply($response, $lifecycle);

        return $response;
    }

    /**
     * @param array<string, mixed> $shop
     */
    private function runPostControlPanelLifecycle(
        OrderOrchestrationResult $result,
        array $shop,
        Unipayment $module,
        ControlPanelOrderClientAdapter $cpClient,
        bool $replayExistingOrder
    ): \PrestaShop\Module\Unipayment\Order\PostControlPanelLifecycleResult {
        return (new PostControlPanelLifecycleService())->handle(
            $result,
            $shop,
            new PostControlPanelLifecycleContext(
                (int) $this->context->shop->id,
                (string) $this->context->currency->iso_code,
                $replayExistingOrder,
                !$replayExistingOrder
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
                $module->getControlPanelClient()
            )
        );
    }

    /** @return array<string, mixed> */
    private function processingResponse(string $token): array
    {
        return [
            'success' => true,
            'step' => 'processing',
            'popup_submission_token' => $token,
            'message' => SmartUcfSessionCoordinator::CUSTOMER_PROCESSING,
        ];
    }

    /**
     * @param array<string, string> $errors
     */
    private function customerValidationMessage(array $errors): string
    {
        if (isset($errors['consents']) && $errors['consents'] !== '') {
            return $errors['consents'];
        }
        foreach ($errors as $message) {
            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        return 'Данните не могат да бъдат валидирани.';
    }

    /** @return array{success:bool,message:string} */
    private function error(int $status, string $message): array
    {
        http_response_code($status);

        return ['success' => false, 'message' => $message];
    }

    private function sanitizeExceptionMessage(\Throwable $exception): string
    {
        $message = trim(strip_tags($exception->getMessage()));
        $message = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[redacted-email]', $message) ?? $message;
        $message = preg_replace(
            '/\b(popup_submission_token|token|secret|passwd|password)=[^\s&]+/i',
            '$1=[redacted]',
            $message
        ) ?? $message;

        return mb_substr($message, 0, 500);
    }
}
