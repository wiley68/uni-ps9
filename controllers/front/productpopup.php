<?php

declare(strict_types=1);

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\UnavailableSchemeException;
use PrestaShop\Module\Unipayment\Product\PopupSubmissionBindingFactory;
use PrestaShop\Module\Unipayment\Product\PopupSubmissionRepository;
use PrestaShop\Module\Unipayment\Product\ProductContextFactory;
use PrestaShop\Module\Unipayment\Product\ProductPopupApplyIdentityService;
use PrestaShop\Module\Unipayment\Product\ProductPopupCalculator;
use PrestaShop\Module\Unipayment\Product\ProductPopupCheckoutPreselectionException;
use PrestaShop\Module\Unipayment\Product\ProductPopupCheckoutPreselectionService;
use PrestaShop\Module\Unipayment\Product\ProductPopupCustomerValidator;
use PrestaShop\Module\Unipayment\Product\ProductPopupOperationGuard;
use PrestaShop\Module\Unipayment\Product\ProductPopupValidationException;

/**
 * Product popup endpoint: calculate, token issue, Step 2 identity, apply guard, preselect.
 * Order creation / SmartUCF / emails remain deferred.
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
                return $this->handleApply($shop, $calculation, (int) $productId, (int) $attributeId, (int) $quantity);
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

            return $this->error(422, 'The selected financing scheme is unavailable.');
        } catch (ProductPopupCheckoutPreselectionException $exception) {
            PrestaShopLogger::addLog(
                'UniPayment product popup preselect cart failed: ' . $this->sanitizeExceptionMessage($exception),
                2
            );

            return $this->error(422, $exception->customerMessage());
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog(
                'UniPayment product popup request failed: ' . get_class($exception),
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
     * @param array<string, mixed> $calculation
     * @return array<string, mixed>
     */
    private function handleApply(array $shop, array $calculation, int $productId, int $attributeId, int $quantity): array
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
            'id_address' => Tools::getValue('id_address', 0),
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

        $guard = new ProductPopupOperationGuard($submissions);
        $gate = $guard->resolve(
            $token,
            $resolved['hash'],
            (int) $this->context->shop->id,
            $resolved['id_guest'],
            $resolved['id_customer']
        );
        if (isset($gate['response'])) {
            return $gate['response'];
        }

        /** @var array<string, mixed> $submission */
        $submission = $gate['submission'];
        $submissionId = (int) $submission['id_submission'];

        try {
            $accepted = (new ProductPopupApplyIdentityService())->accept($shop, $posted, $this->context);
            $submissions->markIdentityAccepted($submissionId);
            $row = $submissions->requireById($submissionId);
            $response = $guard->identityAcceptedResponse($row, false);
            $response['calculation'] = $calculation;
            $response['customer'] = $accepted['customer'];
            $response['identity'] = $accepted['identity'];

            return $response;
        } catch (ProductPopupValidationException $exception) {
            $submissions->revertProcessingWithoutCart($submissionId);
            http_response_code(422);
            $errors = $exception->errors();

            return [
                'success' => false,
                'message' => $this->customerValidationMessage($errors),
                'errors' => $errors,
            ];
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog(
                'UniPayment popup apply identity failed: ' . get_class($exception),
                2
            );
            $this->logPopupSelectionFailure($exception);
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
        } catch (Throwable $ignored) {
            unset($ignored);
        }
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
        $message = preg_replace('/\b\d{10}\b/', '[redacted-id]', $message) ?? $message;

        return mb_substr($message, 0, 500);
    }

    /** @return array{success:bool,message:string} */
    private function error(int $status, string $message): array
    {
        http_response_code($status);

        return ['success' => false, 'message' => $message];
    }
}
