<?php

/**
 * Copyright since 2024 Ilko Ivanov
 *
 * @author    Ilko Ivanov <ilko.iv@gmail.com>
 * @copyright Ilko Ivanov
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

/**
 * Front controller: AJAX запис на полета от калкулатора в cookie + JSON payload за validation.
 *
 * @property UniPayment $module
 */
class UnipaymentSendModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    /**
     * @var array<string, string>
     */
    public $result = ['success' => 'unsuccess'];

    /**
     * @see FrontController::initContent()
     */
    public function initContent()
    {
        $this->ajax = true;

        $uniMesecna = Tools::getValue('uni_mesecna');
        if ($uniMesecna === false || $uniMesecna === '' || $uniMesecna === null) {
            $this->result = ['success' => 'unsuccess'];
            parent::initContent();

            return;
        }

        $cookie = $this->context->cookie;

        $cookie->__set('uni_mesecna', $uniMesecna);
        $cookie->__set('uni_gpr_input', Tools::getValue('uni_gpr_input'));
        $cookie->__set('uni_parva_input', Tools::getValue('uni_parva_input'));
        $cookie->__set('uni_obshtozaplashtane_input', Tools::getValue('uni_obshtozaplashtane_input'));
        $cookie->__set('uni_glp_input', Tools::getValue('uni_glp_input'));
        $cookie->__set('uni_vnoski', Tools::getValue('uni_vnoski'));

        if (Tools::getIsset('uni_fname')) {
            $cookie->__set('uni_fname', Tools::getValue('uni_fname'));
        }
        if (Tools::getIsset('uni_lname')) {
            $cookie->__set('uni_lname', Tools::getValue('uni_lname'));
        }
        if (Tools::getIsset('uni_phone')) {
            $cookie->__set('uni_phone', Tools::getValue('uni_phone'));
        }
        if (Tools::getIsset('uni_phone2')) {
            $cookie->__set('uni_phone2', Tools::getValue('uni_phone2'));
        }
        if (Tools::getIsset('uni_email')) {
            $cookie->__set('uni_email', Tools::getValue('uni_email'));
        }
        if (Tools::getIsset('uni_egn')) {
            $cookie->__set('uni_egn', Tools::getValue('uni_egn'));
        }
        if (Tools::getIsset('uni_description')) {
            $cookie->__set('uni_description', Tools::getValue('uni_description'));
        }
        if (Tools::getIsset('uni_uslovia')) {
            $cookie->__set('uni_uslovia', Tools::getValue('uni_uslovia'));
        }
        if (Tools::getIsset('uni_proces2')) {
            $cookie->__set('uni_proces2', Tools::getValue('uni_proces2'));
        }

        $cookie->__set('uni_kop', Tools::getValue('uni_kop'));

        $payload = [
            'uni_mesecna' => (string) $uniMesecna,
            'uni_gpr_input' => (string) Tools::getValue('uni_gpr_input', ''),
            'uni_parva_input' => (string) Tools::getValue('uni_parva_input', ''),
            'uni_obshtozaplashtane_input' => (string) Tools::getValue('uni_obshtozaplashtane_input', ''),
            'uni_glp_input' => (string) Tools::getValue('uni_glp_input', ''),
            'uni_vnoski' => (string) Tools::getValue('uni_vnoski', ''),
            'uni_kop' => (string) Tools::getValue('uni_kop', ''),
        ];
        if (Tools::getIsset('uni_fname')) {
            $payload['uni_fname'] = (string) Tools::getValue('uni_fname');
        }
        if (Tools::getIsset('uni_lname')) {
            $payload['uni_lname'] = (string) Tools::getValue('uni_lname');
        }
        if (Tools::getIsset('uni_phone')) {
            $payload['uni_phone'] = (string) Tools::getValue('uni_phone');
        }
        if (Tools::getIsset('uni_phone2')) {
            $payload['uni_phone2'] = (string) Tools::getValue('uni_phone2');
        }
        if (Tools::getIsset('uni_email')) {
            $payload['uni_email'] = (string) Tools::getValue('uni_email');
        }
        if (Tools::getIsset('uni_egn')) {
            $payload['uni_egn'] = (string) Tools::getValue('uni_egn');
        }
        if (Tools::getIsset('uni_description')) {
            $payload['uni_description'] = (string) Tools::getValue('uni_description');
        }
        if (Tools::getIsset('uni_uslovia')) {
            $payload['uni_uslovia'] = (string) Tools::getValue('uni_uslovia');
        }
        if (Tools::getIsset('uni_proces2')) {
            $payload['uni_proces2'] = (string) Tools::getValue('uni_proces2');
        }

        $cookie->unipayment_checkout_payload = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $cookie->write();

        $this->result = ['success' => 'success'];

        parent::initContent();
    }

    /**
     * @see ModuleFrontController::displayAjax()
     */
    public function displayAjax()
    {
        header('Content-Type: application/json; charset=utf-8');
        exit((string) json_encode(['result' => $this->result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
