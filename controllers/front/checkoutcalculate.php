<?php

declare(strict_types=1);

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Cart\CartContextFactory;
use PrestaShop\Module\Unipayment\Cart\CartSchemeResolver;
use PrestaShop\Module\Unipayment\Checkout\CartSnapshot;
use PrestaShop\Module\Unipayment\Checkout\CartSnapshotSigner;
use PrestaShop\Module\Unipayment\Checkout\CheckoutPaymentCalculator;
use PrestaShop\Module\Unipayment\Checkout\CheckoutPreferenceStore;

/**
 * Live checkout financing recalculation (authoritative cart + scheme).
 * Does not create orders. Optionally refreshes checkout preference for the selection.
 */
final class UnipaymentCheckoutCalculateModuleFrontController extends ModuleFrontController
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
            return $this->error(403, 'Invalid checkout calculate request.');
        }

        $schemeKey = trim((string) Tools::getValue('scheme_key', ''));
        $kopCode = trim((string) Tools::getValue('kop_code', ''));
        $firstRaw = Tools::getValue('first_installment', 0);
        if ($schemeKey === '' || $kopCode === '' || !is_numeric($firstRaw)) {
            return $this->error(400, 'Invalid checkout selection.');
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
            $cartContext = (new CartContextFactory())->createForCheckout($cart);
            if ($cartContext->lines === []) {
                return $this->error(422, 'The financing selection is unavailable.');
            }

            $currencyIso = (string) $this->context->currency->iso_code;
            $snapshot = new CartSnapshot();
            $signer = new CartSnapshotSigner(_COOKIE_KEY_);
            $fingerprint = $snapshot->fingerprint($cartContext, $currencyIso);
            $postedSnapshot = trim((string) Tools::getValue('cart_snapshot', ''));
            if ($postedSnapshot !== '' && !$signer->verify($postedSnapshot, $fingerprint)) {
                return $this->error(422, 'The cart changed. Please review the financing options again.');
            }

            $calculation = (new CheckoutPaymentCalculator($calculator, new CartSchemeResolver($calculator)))->calculate(
                $shop,
                $cartContext,
                $currencyIso,
                [
                    'scheme_key' => $schemeKey,
                    'kop_code' => $kopCode,
                    'first_installment' => $firstRaw,
                ]
            );

            $signed = $signer->sign($fingerprint);
            (new CheckoutPreferenceStore())->save(
                $this->context->cookie,
                [
                    'scheme_type' => (string) ($calculation['scheme_type'] ?? ''),
                    'kop_code' => (string) ($calculation['kop_code'] ?? ''),
                    'months' => (int) ($calculation['months'] ?? 0),
                    'filter_id' => (int) ($calculation['filter_id'] ?? 0),
                    'first_installment' => $calculation['first_installment'] ?? 0,
                    'cart_fingerprint' => $fingerprint,
                    'flow' => 'checkout',
                ],
                (int) $cart->id,
                (int) $this->context->customer->id
            );

            return [
                'success' => true,
                'calculation' => $calculation,
                'cart_snapshot' => $signed,
            ];
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog('UniPayment checkout calculate failed: ' . get_class($exception), 2);

            return $this->error(422, 'The financing selection is unavailable.');
        }
    }

    /** @return array{success:bool,message:string} */
    private function error(int $status, string $message): array
    {
        http_response_code($status);

        return ['success' => false, 'message' => $message];
    }
}
