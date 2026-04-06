<?php

/**
 * Copyright since 2024 Ilko Ivanov
 *
 * @author    Ilko Ivanov <ilko.iv@gmail.com>
 * @copyright Ilko Ivanov
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

use PrestaShop\Module\Unipayment\Service\UniCreditPostValidateService;

/**
 * Front controller: потвърждаване на плащане UNI Credit — validateOrder, банкови извиквания, пренасочване към order-confirmation.
 *
 * @property UniPayment $module
 */
class UnipaymentValidationModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    /**
     * Валидира cookie полета, създава поръчка и при нужда изпраща данни към UniCredit; винаги приключва с redirect/exit.
     *
     * @see FrontController::postProcess()
     */
    public function postProcess()
    {
        /** @var UniPayment $mod */
        $mod = $this->module;

        if (!$mod->active) {
            Tools::redirect($this->context->link->getPageLink('order', true, null, ['step' => 1]));
        }

        $cookie = $this->context->cookie;

        $uni_uslovia = false;
        if (isset($cookie->uni_uslovia)) {
            $v = $cookie->uni_uslovia;
            $uni_uslovia = $v === 'true' || $v === true || $v === '1' || $v === 1;
            $cookie->__set('uni_uslovia', null);
        }

        $uni_proces2 = '';
        if (isset($cookie->uni_proces2)) {
            $uni_proces2 = (string) $cookie->uni_proces2;
            $cookie->__set('uni_proces2', null);
        }

        $uni_fname_get = '';
        if (isset($cookie->uni_fname)) {
            $uni_fname_get = (string) $cookie->uni_fname;
            $cookie->__set('uni_fname', null);
        }
        $uni_lname_get = '';
        if (isset($cookie->uni_lname)) {
            $uni_lname_get = (string) $cookie->uni_lname;
            $cookie->__set('uni_lname', null);
        }
        $uni_phone_get = '';
        if (isset($cookie->uni_phone)) {
            $uni_phone_get = (string) $cookie->uni_phone;
            $cookie->__set('uni_phone', null);
        }
        $uni_email_get = '';
        if (isset($cookie->uni_email)) {
            $uni_email_get = (string) $cookie->uni_email;
            $cookie->__set('uni_email', null);
        }
        $uni_egn_get = '';
        if (isset($cookie->uni_egn)) {
            $uni_egn_get = (string) $cookie->uni_egn;
            $cookie->__set('uni_egn', null);
        }

        if ((int) $uni_proces2 === 1) {
            if (!$uni_uslovia) {
                Tools::redirect($this->context->link->getPageLink('order', true, null, ['step' => 1, 'uni_check' => 1]));
            }
            if ($uni_fname_get === '') {
                Tools::redirect($this->context->link->getPageLink('order', true, null, ['step' => 1, 'uni_fname_get' => 1]));
            }
            if ($uni_lname_get === '') {
                Tools::redirect($this->context->link->getPageLink('order', true, null, ['step' => 1, 'uni_lname_get' => 1]));
            }
            if ($uni_egn_get === '') {
                Tools::redirect($this->context->link->getPageLink('order', true, null, ['step' => 1, 'uni_egn_get' => 1]));
            }
            if ($uni_phone_get === '') {
                Tools::redirect($this->context->link->getPageLink('order', true, null, ['step' => 1, 'uni_phone_get' => 1]));
            }
            if ($uni_email_get === '') {
                Tools::redirect($this->context->link->getPageLink('order', true, null, ['step' => 1, 'uni_email_get' => 1]));
            }
        }

        $cart = $this->context->cart;
        if (
            !Validate::isLoadedObject($cart)
            || (int) $cart->id_customer === 0
            || (int) $cart->id_address_delivery === 0
            || (int) $cart->id_address_invoice === 0
        ) {
            Tools::redirect($this->context->link->getPageLink('order', true, null, ['step' => 1]));
        }

        $authorized = false;
        foreach (Module::getPaymentModules() as $paymentModule) {
            if (($paymentModule['name'] ?? '') === 'unipayment') {
                $authorized = true;
                break;
            }
        }
        if (!$authorized) {
            exit($mod->trans('This payment method is not available.', [], 'Modules.Unipayment.Shop'));
        }

        $customer = new Customer((int) $cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect($this->context->link->getPageLink('order', true, null, ['step' => 1]));
        }

        $idOrderState = (int) Configuration::get('PS_OS_UNIPAYMENT');
        if ($idOrderState <= 0) {
            Tools::redirect($this->context->link->getPageLink('order', true, null, ['step' => 1]));
        }

        $currency = $this->context->currency;
        $uni_total = (float) $cart->getOrderTotal(true, Cart::ONLY_PRODUCTS);

        $mailVars = [
            '{bankwire_owner}' => 'owner',
            '{bankwire_details}' => nl2br('details'),
            '{bankwire_address}' => nl2br('address'),
        ];

        $ok = $mod->validateOrder(
            (int) $cart->id,
            $idOrderState,
            $uni_total,
            $mod->displayName,
            null,
            $mailVars,
            (int) $currency->id,
            false,
            $customer->secure_key
        );

        if (!$ok || (int) $mod->currentOrder <= 0) {
            Tools::redirect($this->context->link->getPageLink('order', true, null, ['step' => 3]));
        }

        $calc = [
            'uni_mesecna' => '',
            'uni_gpr' => '',
            'uni_parva' => '',
            'uni_glp' => '',
            'uni_vnoski' => '',
            'uni_kop' => '',
        ];
        if (
            isset($cookie->uni_mesecna)
            && isset($cookie->uni_gpr_input)
            && isset($cookie->uni_parva_input)
            && isset($cookie->uni_obshtozaplashtane_input)
            && isset($cookie->uni_glp_input)
            && isset($cookie->uni_vnoski)
            && isset($cookie->uni_kop)
        ) {
            $calc['uni_mesecna'] = (string) $cookie->uni_mesecna;
            $cookie->__set('uni_mesecna', null);
            $calc['uni_gpr'] = (string) $cookie->uni_gpr_input;
            $cookie->__set('uni_gpr_input', null);
            $calc['uni_parva'] = (string) $cookie->uni_parva_input;
            $cookie->__set('uni_parva_input', null);
            $cookie->__set('uni_obshtozaplashtane_input', null);
            $calc['uni_glp'] = (string) $cookie->uni_glp_input;
            $cookie->__set('uni_glp_input', null);
            $calc['uni_vnoski'] = (string) $cookie->uni_vnoski;
            $cookie->__set('uni_vnoski', null);
            $calc['uni_kop'] = (string) $cookie->uni_kop;
            $cookie->__set('uni_kop', null);
        }

        $uni_phone2_get = '';
        if (isset($cookie->uni_phone2)) {
            $uni_phone2_get = (string) $cookie->uni_phone2;
            $cookie->__set('uni_phone2', null);
        }
        $uni_description_get = '';
        if (isset($cookie->uni_description)) {
            $uni_description_get = (string) $cookie->uni_description;
            $cookie->__set('uni_description', null);
        }

        if (isset($cookie->unipayment_checkout_payload)) {
            $cookie->__set('unipayment_checkout_payload', null);
        }

        $cookie->write();

        $formDisplay = [
            'uni_fname' => $uni_fname_get,
            'uni_lname' => $uni_lname_get,
            'uni_egn' => $uni_egn_get,
            'uni_phone' => $uni_phone_get,
            'uni_email' => $uni_email_get,
            'uni_phone2' => $uni_phone2_get,
            'uni_description' => $uni_description_get,
        ];

        $uni_address_delivery_id = (int) $cart->id_address_delivery;
        $uni_address_invoice_id = (int) $cart->id_address_invoice;
        $uni_shipping_addresses = [];
        $uni_billing_addresses = [];
        foreach ($customer->getAddresses((int) $this->context->language->id) as $uni_address) {
            if ((int) ($uni_address['id_address'] ?? 0) === $uni_address_delivery_id) {
                $uni_shipping_addresses = $uni_address;
            }
            if ((int) ($uni_address['id_address'] ?? 0) === $uni_address_invoice_id) {
                $uni_billing_addresses = $uni_address;
            }
        }

        $customerPhone = (string) ($uni_shipping_addresses['phone'] ?? '');
        $uni_shipping_address = (string) ($uni_shipping_addresses['address1'] ?? '');
        $uni_shipping_city = (string) ($uni_shipping_addresses['city'] ?? '');
        $uni_shipping_county = (string) ($uni_shipping_addresses['state'] ?? '');
        $uni_billing_address = (string) ($uni_billing_addresses['address1'] ?? '');
        $uni_billing_city = (string) ($uni_billing_addresses['city'] ?? '');
        $uni_billing_county = (string) ($uni_billing_addresses['state'] ?? '');

        $extras = [
            'uni_proces1' => 0,
            'uni_application' => '',
            'uni_api' => '',
            'uni_proces2' => 0,
            'uniresult_b64' => '',
        ];

        $paramsuni = $mod->getCachedUniParameters();
        if (is_array($paramsuni) && $paramsuni !== []) {
            $root = method_exists($mod, 'getLocalPath') ? rtrim($mod->getLocalPath(), '/') : (_PS_MODULE_DIR_ . $mod->name);
            $service = new UniCreditPostValidateService($root);
            $extras = $service->run(
                (int) $mod->currentOrder,
                $cart,
                $customer,
                $currency,
                $paramsuni,
                $calc,
                $formDisplay,
                $customerPhone,
                $uni_shipping_address,
                $uni_shipping_city,
                $uni_shipping_county,
                $uni_billing_address,
                $uni_billing_city,
                $uni_billing_county
            );
        }

        $mod->persistUniCreditBankRedirectCookie((int) $mod->currentOrder, $extras);

        Tools::redirect($this->context->link->getPageLink(
            'order-confirmation',
            true,
            null,
            [
                'id_cart' => (int) $cart->id,
                'id_module' => (int) $mod->id,
                'id_order' => (int) $mod->currentOrder,
                'key' => $customer->secure_key,
                'uni_proces1' => (int) $extras['uni_proces1'],
                'uni_application' => (string) $extras['uni_application'],
                'uni_api' => (string) $extras['uni_api'],
                'uni_proces2' => (int) $extras['uni_proces2'],
                'uniresult' => (string) $extras['uniresult_b64'],
            ]
        ));
    }
}
