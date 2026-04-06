<?php

/**
 * Copyright since 2024 Ilko Ivanov
 *
 * @author    Ilko Ivanov <ilko.iv@gmail.com>
 * @copyright Ilko Ivanov
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

use PrestaShop\Module\Unipayment\Helper\FinancialRateHelper;

/**
 * Front controller: AJAX месечни вноски и ГПР по срокове за продуктов блок (kimb от шаблона).
 *
 * @property UniPayment $module
 */
class UnipaymentGetproductModuleFrontController extends ModuleFrontController
{
    /**
     * @var array<string, mixed>|null
     */
    public $result = null;

    /**
     * @see FrontController::initContent()
     */
    public function initContent()
    {
        $this->ajax = true;
        $json = array();
        $json['success'] = 'unsuccess';

        if (null !== Tools::getValue('uni_vnoski')) {
            $uni_vnoski = Tools::getValue('uni_vnoski');
        } else {
            $uni_vnoski = 12;
        }
        if (null !== Tools::getValue('uni_price')) {
            $uni_price = Tools::getValue('uni_price');
        } else {
            $uni_price = 0;
        }
        if (null !== Tools::getValue('uni_param_kimb_3')) {
            $uni_param_kimb_3 = Tools::getValue('uni_param_kimb_3');
        } else {
            $uni_param_kimb_3 = 0;
        }
        if (null !== Tools::getValue('uni_param_kimb_4')) {
            $uni_param_kimb_4 = Tools::getValue('uni_param_kimb_4');
        } else {
            $uni_param_kimb_4 = 0;
        }
        if (null !== Tools::getValue('uni_param_kimb_5')) {
            $uni_param_kimb_5 = Tools::getValue('uni_param_kimb_5');
        } else {
            $uni_param_kimb_5 = 0;
        }
        if (null !== Tools::getValue('uni_param_kimb_6')) {
            $uni_param_kimb_6 = Tools::getValue('uni_param_kimb_6');
        } else {
            $uni_param_kimb_6 = 0;
        }
        if (null !== Tools::getValue('uni_param_kimb_9')) {
            $uni_param_kimb_9 = Tools::getValue('uni_param_kimb_9');
        } else {
            $uni_param_kimb_9 = 0;
        }
        if (null !== Tools::getValue('uni_param_kimb_10')) {
            $uni_param_kimb_10 = Tools::getValue('uni_param_kimb_10');
        } else {
            $uni_param_kimb_10 = 0;
        }
        if (null !== Tools::getValue('uni_param_kimb_12')) {
            $uni_param_kimb_12 = Tools::getValue('uni_param_kimb_12');
        } else {
            $uni_param_kimb_12 = 0;
        }
        if (null !== Tools::getValue('uni_param_kimb_18')) {
            $uni_param_kimb_18 = Tools::getValue('uni_param_kimb_18');
        } else {
            $uni_param_kimb_18 = 0;
        }
        if (null !== Tools::getValue('uni_param_kimb_24')) {
            $uni_param_kimb_24 = Tools::getValue('uni_param_kimb_24');
        } else {
            $uni_param_kimb_24 = 0;
        }
        if (null !== Tools::getValue('uni_param_kimb_30')) {
            $uni_param_kimb_30 = Tools::getValue('uni_param_kimb_30');
        } else {
            $uni_param_kimb_30 = 0;
        }
        if (null !== Tools::getValue('uni_param_kimb_36')) {
            $uni_param_kimb_36 = Tools::getValue('uni_param_kimb_36');
        } else {
            $uni_param_kimb_36 = 0;
        }

        $json['uni_mesecna_3'] = number_format($uni_price * floatval($uni_param_kimb_3), 2, ".", "");
        $uni_gprm_3 = ((FinancialRateHelper::periodicRate(3, -1 * (floatval($json['uni_mesecna_3'])), floatval($uni_price)) * 3)) / (3 / 12);
        $json['uni_gpr_3'] = FinancialRateHelper::formatGprPercentForDisplay((pow((1 + floatval($uni_gprm_3) / 12), 12) - 1) * 100);
        $json['uni_mesecna_4'] = number_format($uni_price * floatval($uni_param_kimb_4), 2, ".", "");
        $uni_gprm_4 = ((FinancialRateHelper::periodicRate(4, -1 * (floatval($json['uni_mesecna_4'])), floatval($uni_price)) * 4)) / (4 / 12);
        $json['uni_gpr_4'] = FinancialRateHelper::formatGprPercentForDisplay((pow((1 + floatval($uni_gprm_4) / 12), 12) - 1) * 100);
        $json['uni_mesecna_5'] = number_format($uni_price * floatval($uni_param_kimb_5), 2, ".", "");
        $uni_gprm_5 = ((FinancialRateHelper::periodicRate(5, -1 * (floatval($json['uni_mesecna_5'])), floatval($uni_price)) * 5)) / (5 / 12);
        $json['uni_gpr_5'] = FinancialRateHelper::formatGprPercentForDisplay((pow((1 + floatval($uni_gprm_5) / 12), 12) - 1) * 100);
        $json['uni_mesecna_6'] = number_format($uni_price * floatval($uni_param_kimb_6), 2, ".", "");
        $uni_gprm_6 = ((FinancialRateHelper::periodicRate(6, -1 * (floatval($json['uni_mesecna_6'])), floatval($uni_price)) * 6)) / (6 / 12);
        $json['uni_gpr_6'] = FinancialRateHelper::formatGprPercentForDisplay((pow((1 + floatval($uni_gprm_6) / 12), 12) - 1) * 100);
        $json['uni_mesecna_9'] = number_format($uni_price * floatval($uni_param_kimb_9), 2, ".", "");
        $uni_gprm_9 = ((FinancialRateHelper::periodicRate(9, -1 * (floatval($json['uni_mesecna_9'])), floatval($uni_price)) * 9)) / (9 / 12);
        $json['uni_gpr_9'] = FinancialRateHelper::formatGprPercentForDisplay((pow((1 + floatval($uni_gprm_9) / 12), 12) - 1) * 100);
        $json['uni_mesecna_10'] = number_format($uni_price * floatval($uni_param_kimb_10), 2, ".", "");
        $uni_gprm_10 = ((FinancialRateHelper::periodicRate(10, -1 * (floatval($json['uni_mesecna_10'])), floatval($uni_price)) * 10)) / (10 / 12);
        $json['uni_gpr_10'] = FinancialRateHelper::formatGprPercentForDisplay((pow((1 + floatval($uni_gprm_10) / 12), 12) - 1) * 100);
        $json['uni_mesecna_12'] = number_format($uni_price * floatval($uni_param_kimb_12), 2, ".", "");
        $uni_gprm_12 = ((FinancialRateHelper::periodicRate(12, -1 * (floatval($json['uni_mesecna_12'])), floatval($uni_price)) * 12)) / (12 / 12);
        $json['uni_gpr_12'] = FinancialRateHelper::formatGprPercentForDisplay((pow((1 + floatval($uni_gprm_12) / 12), 12) - 1) * 100);
        $json['uni_mesecna_18'] = number_format($uni_price * floatval($uni_param_kimb_18), 2, ".", "");
        $uni_gprm_18 = ((FinancialRateHelper::periodicRate(18, -1 * (floatval($json['uni_mesecna_18'])), floatval($uni_price)) * 18)) / (18 / 12);
        $json['uni_gpr_18'] = FinancialRateHelper::formatGprPercentForDisplay((pow((1 + floatval($uni_gprm_18) / 12), 12) - 1) * 100);
        $json['uni_mesecna_24'] = number_format($uni_price * floatval($uni_param_kimb_24), 2, ".", "");
        $uni_gprm_24 = ((FinancialRateHelper::periodicRate(24, -1 * (floatval($json['uni_mesecna_24'])), floatval($uni_price)) * 24)) / (24 / 12);
        $json['uni_gpr_24'] = FinancialRateHelper::formatGprPercentForDisplay((pow((1 + floatval($uni_gprm_24) / 12), 12) - 1) * 100);
        $json['uni_mesecna_30'] = number_format($uni_price * floatval($uni_param_kimb_30), 2, ".", "");
        $uni_gprm_30 = ((FinancialRateHelper::periodicRate(30, -1 * (floatval($json['uni_mesecna_30'])), floatval($uni_price)) * 30)) / (30 / 12);
        $json['uni_gpr_30'] = FinancialRateHelper::formatGprPercentForDisplay((pow((1 + floatval($uni_gprm_30) / 12), 12) - 1) * 100);
        $json['uni_mesecna_36'] = number_format($uni_price * floatval($uni_param_kimb_36), 2, ".", "");
        $uni_gprm_36 = ((FinancialRateHelper::periodicRate(36, -1 * (floatval($json['uni_mesecna_36'])), floatval($uni_price)) * 36)) / (36 / 12);
        $json['uni_gpr_36'] = FinancialRateHelper::formatGprPercentForDisplay((pow((1 + floatval($uni_gprm_36) / 12), 12) - 1) * 100);

        $json['success'] = 'success';
        $this->result = $json;

        parent::initContent();
    }

    /**
     * @see ModuleFrontController::displayAjax()
     */
    public function displayAjax()
    {
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode($this->result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}