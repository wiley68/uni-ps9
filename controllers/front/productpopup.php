<?php

declare(strict_types=1);

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\UnavailableSchemeException;
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
use PrestaShop\Module\Unipayment\Order\PopupSubmissionPostOrderBinder;
use PrestaShop\Module\Unipayment\Order\SensitiveDataCipher;
use PrestaShop\Module\Unipayment\Product\GuestCustomerFactory;
use PrestaShop\Module\Unipayment\Product\PopupSubmissionBindingFactory;
use PrestaShop\Module\Unipayment\Product\PopupSubmissionRepository;
use PrestaShop\Module\Unipayment\Product\PopupSubmissionStates;
use PrestaShop\Module\Unipayment\Product\ProductContextFactory;
use PrestaShop\Module\Unipayment\Product\ProductPopupApplyService;
use PrestaShop\Module\Unipayment\Product\ProductPopupCustomerValidator;
use PrestaShop\Module\Unipayment\Product\ProductPopupCalculator;
use PrestaShop\Module\Unipayment\Product\ProductPopupCheckoutPreselectionException;
use PrestaShop\Module\Unipayment\Product\ProductPopupCheckoutPreselectionService;
use PrestaShop\Module\Unipayment\Product\ProductPopupValidationException;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfSessionCoordinator;

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

            $action = (string) Tools::getValue('popup_action', 'calculate');
            if ($action === 'issue_submission_token') {
                return $this->handleIssueSubmissionToken(
                    $calculation,
                    (int) $productId,
                    (int) $attributeId,
                    (int) $quantity
                );
            }
            if ($action === 'apply') {
                return $this->handleApply($shop, $product, (int) $productId, (int) $attributeId, (int) $quantity);
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

            if ($action === 'preselect') {
                $preselection = (new ProductPopupCheckoutPreselectionService())->execute(
                    $calculation,
                    (int) $productId,
                    (int) $attributeId,
                    (int) $quantity,
                    trim((string) Tools::getValue('preselect_operation_token', '')),
                    $this->context,
                    $this->context->link
                );

                return [
                    'success' => true,
                    'calculation' => $calculation,
                    'checkout_url' => $preselection['checkout_url'],
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
            $this->logPopupSelectionFailure($exception);

            return $this->error(422, 'The financing selection is unavailable.');
        } catch (ProductPopupCheckoutPreselectionException $exception) {
            PrestaShopLogger::addLog(
                'UniPayment product popup preselect cart failed: ' . $this->sanitizeExceptionMessage($exception),
                2
            );

            return $this->error(422, $exception->customerMessage());
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog(
                'UniPayment product popup request failed: ' . get_class($exception) . ' ' . $this->sanitizeExceptionMessage($exception),
                2
            );
            $this->logPopupSelectionFailure($exception);

            return $this->error(500, 'Заявката не може да бъде обработена. Моля, опитайте отново.');
        }
    }

    /**
     * @param array<string, mixed> $calculation
     * @return array<string, mixed>
     */
    private function handleIssueSubmissionToken(array $calculation, int $productId, int $attributeId, int $quantity): array
    {
        $resolved = (new PopupSubmissionBindingFactory())->fromSelection([
            'id_product' => $productId,
            'id_product_attribute' => $attributeId,
            'quantity' => $quantity,
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
            $preferred
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
     * @return array<string, mixed>
     */
    private function handleApply(array $shop, \PrestaShop\Module\Unipayment\Calculator\ProductContext $product, int $productId, int $attributeId, int $quantity): array
    {
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
            'consent' => Tools::getValue('unipayment_consent', []),
        ];

        $token = trim((string) Tools::getValue('popup_submission_token', ''));
        $submissions = new PopupSubmissionRepository();
        $resolved = (new PopupSubmissionBindingFactory())->fromSelection([
            'id_product' => $productId,
            'id_product_attribute' => $attributeId,
            'quantity' => $quantity,
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
        $reuseCartId = (int) ($submission['id_cart'] ?? 0);
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
        $service = new ProductPopupApplyService(
            new Calculator(),
            new ProductPopupCustomerValidator(),
            new GuestCustomerFactory(),
            $orchestrator,
            new SensitiveDataCipher(),
            null,
            null,
            null,
            $submissions
        );

        try {
            $result = $service->apply(
                $shop,
                $posted,
                $product,
                $productId,
                $attributeId,
                $quantity,
                $this->context,
                $submissionId,
                $reuseCartId
            );

            PopupSubmissionPostOrderBinder::bind(
                $submissions,
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
            $this->logPopupSelectionFailure($exception);

            return ['success' => false, 'message' => 'The financing selection is unavailable.'];
        } catch (OrderOrchestrationException $exception) {
            PrestaShopLogger::addLog(
                'UniPayment popup apply orchestration failed: ' . get_class($exception)
                    . ' post_order=' . ($exception->isPostOrder() ? '1' : '0')
                    . ' id_order=' . $exception->idOrder()
                    . ' id_attempt=' . $exception->attemptId()
                    . ' state=' . $exception->state(),
                2
            );
            if ($exception->isPostOrder()) {
                PopupSubmissionPostOrderBinder::bind(
                    $submissions,
                    $submissionId,
                    $exception->attemptId(),
                    $exception->idOrder(),
                    $exception->orderReference(),
                    0
                );

                return PostOrderPopupFailureResponse::fromException($exception);
            }
            if ($exception->isRetryable()) {
                return $this->processingResponse($token);
            }
            $submissions->markFailed($submissionId);
            http_response_code(500);

            return ['success' => false, 'message' => 'The financing request could not be processed. Please try again.'];
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog(
                'UniPayment popup apply failed: ' . get_class($exception) . ' ' . $this->sanitizeExceptionMessage($exception),
                2
            );
            $this->logPopupSelectionFailure($exception);
            $recoveredOrderId = $this->recoverPopupNativeOrderId($reuseCartId);
            if ($recoveredOrderId > 0) {
                $order = new Order($recoveredOrderId);
                $reference = Validate::isLoadedObject($order) ? (string) $order->reference : '';
                PopupSubmissionPostOrderBinder::bind(
                    $submissions,
                    $submissionId,
                    0,
                    $recoveredOrderId,
                    $reference,
                    0
                );

                return PostOrderPopupFailureResponse::fromPersistedOrder($recoveredOrderId, $reference);
            }
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

    private function recoverPopupNativeOrderId(int $idCart): int
    {
        if ($idCart <= 0) {
            return 0;
        }

        return (int) Order::getIdByCartId($idCart);
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
     * @param array<string, mixed> $shop
     * @return array<string, mixed>
     */
    private function buildApplySuccessResponse(
        array $shop,
        Unipayment $module,
        ControlPanelOrderClientAdapter $cpClient,
        \PrestaShop\Module\Unipayment\Order\OrderOrchestrationResult $result,
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

        if ($this->isDebugResponseEnabled()) {
            if (!empty($response['smartucf_error'])) {
                $response['debug_smartucf_error'] = $response['smartucf_error'];
            }
            if (!empty($response['email_error'])) {
                $response['debug_email_error'] = $response['email_error'];
            }
        }

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
            $this->createSmartUcfCoordinator($module, $cpClient)
        );
    }

    private function createSmartUcfCoordinator(
        Unipayment $module,
        ControlPanelOrderClientAdapter $cpClient
    ): SmartUcfSessionCoordinator {
        return new SmartUcfSessionCoordinator(
            null,
            null,
            null,
            null,
            null,
            $cpClient,
            $module,
            $this->context,
            $module->getControlPanelClient()
        );
    }

    private function logPopupSelectionFailure(\Throwable $exception): void
    {
        try {
            $configuration = new \PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository();
            if (!$configuration->isDebugEnabled()) {
                return;
            }
            $payload = [
                'popup_action' => (string) Tools::getValue('popup_action', ''),
                'popup_offer_type' => (string) Tools::getValue('popup_offer_type', ''),
                'scheme_type' => (string) Tools::getValue('scheme_type', ''),
                'scheme_key' => (string) Tools::getValue('scheme_key', ''),
                'kop_code' => (string) Tools::getValue('kop_code', ''),
                'months' => (string) Tools::getValue('months', ''),
                'filter_id' => (string) Tools::getValue('filter_id', ''),
                'first_installment' => (string) Tools::getValue('first_installment', ''),
                'id_product' => (string) Tools::getValue('id_product', ''),
                'id_product_attribute' => (string) Tools::getValue('id_product_attribute', ''),
                'quantity' => (string) Tools::getValue('quantity', ''),
                'token_present' => Tools::getValue('token', '') !== '',
            ];
            $safeMessage = $this->sanitizeExceptionMessage($exception);
            PrestaShopLogger::addLog(
                'UniPayment popup selection debug failure: '
                    . json_encode(
                        [
                            'exception' => get_class($exception),
                            'message' => $safeMessage,
                            'payload' => $payload,
                        ],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),
                1
            );
            $journal = new \PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDiagnosticJournal(
                $configuration,
                new \PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDebugLogRepository()
            );
            $journal->record(
                0,
                'popup-selection',
                422,
                $payload,
                ['source' => 'productpopup', 'error' => $safeMessage],
                $safeMessage
            );
        } catch (\Throwable $ignored) {
            unset($ignored);
        }
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

    private function logPopupPostOrderFailure(int $idOrder, string $orderReference, \Throwable $exception, string $source = 'post-order', $request = null): void
    {
        try {
            $configuration = new \PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository();
            $journal = new \PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDiagnosticJournal(
                $configuration,
                new \PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDebugLogRepository()
            );
            $journal->record(
                $idOrder,
                $orderReference,
                500,
                $request ?? ['source' => 'productpopup-' . $source],
                ['exception' => get_class($exception), 'message' => $exception->getMessage()],
                $exception->getMessage()
            );
        } catch (\Throwable $ignored) {
            PrestaShopLogger::addLog(
                'UniPayment popup debug journal write failed: ' . $ignored->getMessage(),
                2
            );
        }
    }

    private function isDebugResponseEnabled(): bool
    {
        try {
            return (new \PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository())->isDebugEnabled();
        } catch (\Throwable $exception) {
            return false;
        }
    }

    /** @return array{success:bool,message:string} */
    private function error(int $status, string $message): array
    {
        http_response_code($status);

        return ['success' => false, 'message' => $message];
    }
}
