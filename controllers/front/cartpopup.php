<?php

declare(strict_types=1);

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\UnavailableSchemeException;
use PrestaShop\Module\Unipayment\Cart\CartContextFactory;
use PrestaShop\Module\Unipayment\Cart\CartPopupCalculator;
use PrestaShop\Module\Unipayment\Cart\CartSchemeResolver;
use PrestaShop\Module\Unipayment\Product\PopupSubmissionBindingFactory;
use PrestaShop\Module\Unipayment\Product\PopupSubmissionRepository;
use PrestaShop\Module\Unipayment\Product\ProductPopupApplyIdentityService;
use PrestaShop\Module\Unipayment\Product\ProductPopupCustomerValidator;
use PrestaShop\Module\Unipayment\Product\ProductPopupOperationGuard;
use PrestaShop\Module\Unipayment\Product\ProductPopupValidationException;

/**
 * Cart popup: calculate / issue_submission_token / validate_step2 / apply.
 * Apply stops at identity_accepted (Phase 7 boundary). No PS/CP order / SmartUCF.
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
                return $this->handleApply($shop, $calculation, $cart, $cartContext);
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
                'UniPayment cart popup request failed: ' . get_class($exception),
                2
            );

            return $this->error(500, 'Заявката не може да бъде обработена. Моля, опитайте отново.');
        }
    }

    /**
     * @param array<string, mixed> $calculation
     * @return array<string, mixed>
     */
    private function handleIssueSubmissionToken(array $calculation, Cart $cart, \PrestaShop\Module\Unipayment\Cart\CartContext $cartContext): array
    {
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
            \PrestaShop\Module\Unipayment\Product\PopupSubmissionSelectionHash::FLOW_CART_POPUP
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
        \PrestaShop\Module\Unipayment\Cart\CartContext $cartContext
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
                'UniPayment cart popup apply identity failed: ' . get_class($exception),
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
}
