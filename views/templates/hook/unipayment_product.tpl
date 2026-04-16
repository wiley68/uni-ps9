{*
	* @File: unipayment.tpl
	* @Author: Ilko Ivanov
	* @Author e-mail: ilko.iv@gmail.com
	* @Publisher: Avalon Ltd
	* @Publisher e-mail: home@avalonbg.com
	* @Owner: Avalon Ltd
	* @Version: 1.4.0
*}
<input type="hidden" id="uni_cart" value="{$uni_cart}" />
<input type="hidden" id="uni_csrf_token" value="{$token|escape:'html':'UTF-8'}" />
<input type="hidden" id="uni_prepare_installmentcheckout_url"
    value="{$uni_prepare_installmentcheckout_url|escape:'html':'UTF-8'}" />
<input type="hidden" id="uni_get_product_link" value="{$uni_get_product_link|escape:'html':'UTF-8'}" />
{foreach from=$uni_kimb_hidden_fields item=kf}
    <input type="hidden" id="uni_param_glp_{$kf.m}" value="{$kf.glp|escape:'html':'UTF-8'}" />
    <input type="hidden" id="uni_param_kimb_{$kf.m}" value="{$kf.kimb|escape:'html':'UTF-8'}" />
{/foreach}
<input type="hidden" id="uni_eur" value="{$uni_eur}" />
<input type="hidden" id="uni_currency_code" value="{$uni_currency_code}" />
<input type="hidden" id="uni_sign" value="{$uni_sign|escape:'html':'UTF-8'}" />
<input type="hidden" id="uni_sign_second" value="{$uni_sign_second|escape:'html':'UTF-8'}" />
{if isset($uni_js_shop_strings)}
    <script type="text/javascript">
        window.uniPaymentShopStrings = {$uni_js_shop_strings nofilter};
    </script>
{/if}
<div class="unipayment-product-scope">
    <div id="uni-product-button-container" {if isset($UNIPAYMENT_GAP) && $UNIPAYMENT_GAP > 0}
        style="margin-top: {$UNIPAYMENT_GAP|intval}px;" {/if}>
        {if $uni_zaglavie ne ''}
            <div class="uni_zaglavie">
                {$uni_zaglavie}
            </div>
        {/if}
        {if $uni_vnoska eq 'Yes'}
            <div id="btn_uni" class="uni_button">
                <div class="uni_button_body">
                    <div class="uni_button_body_left">
                        <div class="uni_button_txt1"><span id="uni_button_installments_label">{$uni_shema_current}</span>
                            {l s='INSTALLMENTS' d='Modules.Unipayment.Shop'}
                        </div>
                        <div class="uni_button_line"></div>
                        <div class="uni_button_txt2">
                            <span id="uni_button_mesecna_main">{$uni_mesecna|number_format:2:".":""} {$uni_sign}</span><span
                                id="uni_button_mesecna_second" {if $uni_eur == 0 || $uni_eur == 3}style="display:none;"
                                    {else}style="font-size:80%;" {/if}>{if $uni_mesecna_second != 0}
                                ({$uni_mesecna_second|number_format:2:".":""} {$uni_sign_second}){/if}</span>
                        </div>
                    </div>
                    <div class="uni_button_body_right">
                        <img src="{$uni_mini_logo}" style="width:100%;float:right;" alt="" />
                    </div>
                </div>
            </div>
        {else}
            <div id="btn_uni" class="uni_button_without"></div>
        {/if}
    </div>
    <input type="hidden" name="product_id" id="product_id" value="{$uni_product_id}" />
    <div id="uni-product-popup-container" class="modalpayment_uni unipayment-product-scope">
        <div class="modalpayment_content_uni">
            <div id="uni_body" class="uni_body">
                <div>
                    <div class="uni_body">
                        <a target="_blank" rel="noopener noreferrer" href="{$uni_reklama_url}"><img class="uni_image"
                                title="{l s='UniCredit loan calculator %s' sprintf=[$uni_mod_version] d='Modules.Unipayment.Shop'}"
                                src="{$uni_picture}"
                                alt="{l s='UniCredit loan calculator %s' sprintf=[$uni_mod_version] d='Modules.Unipayment.Shop'}"></a>
                        <div class="uni_body_txt">
                            <div class="uni_gpr_container">
                                {if $uni_eur == 0}
                                    <div class="uni_title_head">
                                        {l s='Only a few clicks to the desired purchase from 150 BGN to 50 000 BGN:' d='Modules.Unipayment.Shop'}
                                    </div>
                                {elseif $uni_eur == 1}
                                    <div class="uni_title_head">
                                        {l s='Only a few clicks to the desired purchase from 150 BGN (75 EUR) to 50 000 BGN (25 000 EUR):' d='Modules.Unipayment.Shop'}
                                    </div>
                                {elseif $uni_eur == 2}
                                    <div class="uni_title_head">
                                        {l s='Only a few clicks to the desired purchase from 75 EUR (150 BGN) to 25 000 EUR (50 000 BGN):' d='Modules.Unipayment.Shop'}
                                    </div>
                                {elseif $uni_eur == 3}
                                    <div class="uni_title_head">
                                        {l s='Only a few clicks to the desired purchase from 75 EUR to 25 000 EUR:' d='Modules.Unipayment.Shop'}
                                    </div>
                                {/if}
                                <div class="uni_title">
                                    <p>{l s='1. Add the product you want to the cart.' d='Modules.Unipayment.Shop'}</p>
                                    <p>{l s='2. In the "Payment method" menu choose "On credit with UniCredit Consumer Financing".' d='Modules.Unipayment.Shop'}
                                    </p>
                                    <p>{l s='3. Choose the number of monthly installments according to your possibilities and preferences.' d='Modules.Unipayment.Shop'}
                                    </p>
                                    <p>{l s='4. Complete the application fully digitally or wait for a phone call.' d='Modules.Unipayment.Shop'}
                                    </p>
                                </div>
                                <div class="uni_calc_back">
                                    <div class="uni_calc_logo"></div>
                                    <div class="uni_calc">
                                        <div class="uni_gpr_container_row">
                                            <div class="uni_gpr_column">
                                                {l s='Item price' d='Modules.Unipayment.Shop'}
                                            </div>
                                            <div class="uni_gpr_column_right">
                                                <input type="hidden" id="uni_price" value="{$uni_price}" />
                                                {assign var="uniPriceStr" value=$uni_price|number_format:2:".":""}
                                                {assign var=uniPriceArr value="."|explode:$uniPriceStr}
                                                {assign var="uniPriceSecondStr" value=$uni_price_second|number_format:2:".":""}
                                                {assign var=uniPriceSecondArr value="."|explode:$uniPriceSecondStr}
                                                {if $uni_eur == 0 || $uni_eur == 3}
                                                    <span class="uni_red">
                                                        <span id="uni_price_int">{$uniPriceArr[0]}</span>.
                                                        <span class="uni_sub"><span
                                                                id="uni_price_dec">{$uniPriceArr[1]}</span>&nbsp;{$uni_sign}</span>
                                                    </span>
                                                {else}
                                                    <span class="uni_red">
                                                        <span id="uni_price_int">{$uniPriceArr[0]}</span>
                                                        <span class="uni_sub">.<span
                                                                id="uni_price_dec">{$uniPriceArr[1]}</span>&nbsp;{$uni_sign}</span>
                                                        <span style="font-size:70%;">
                                                            (<span id="uni_price_second_int">{$uniPriceSecondArr[0]}</span>
                                                            <span class="uni_sub">.<span
                                                                    id="uni_price_second_dec">{$uniPriceSecondArr[1]}</span>&nbsp;{$uni_sign_second}</span>)
                                                        </span>
                                                    </span>
                                                {/if}
                                            </div>
                                        </div>
                                        <div class="uni_gpr_container_row">
                                            <div class="uni_gpr_column">
                                                <span class="uni_red">{$uni_meseci_txt}</span>
                                            </div>
                                            <div class="uni_gpr_column_right">
                                                <select id="uni_pogasitelni_vnoski_input" class="uni_txt_right">
                                                    {foreach from=$uni_product_installment_options item=pio}
                                                        {if $pio.show_in_select}
                                                            <option value="{$pio.months|intval}"
                                                                {if $uni_shema_current eq $pio.months}selected{/if}>
                                                                {$pio.months}
                                                                {l s='months' d='Modules.Unipayment.Shop'}</option>
                                                        {/if}
                                                    {/foreach}
                                                </select>
                                            </div>
                                        </div>
                                        <div class="uni_gpr_container_row">
                                            <div class="uni_gpr_column">
                                                {$uni_vnoska_txt}
                                            </div>
                                            <div class="uni_gpr_column_right">
                                                {if $uni_eur == 0 or $uni_eur == 3}
                                                    <span class="uni_red">
                                                        <span id="uni_vnoska_int"></span>
                                                        <span class="uni_sub">.<span
                                                                id="uni_vnoska_dec"></span>&nbsp;{$uni_sign}</span>
                                                    </span>
                                                {else}
                                                    <span class="uni_red">
                                                        <span id="uni_vnoska_int"></span>
                                                        <span class="uni_sub">.<span
                                                                id="uni_vnoska_dec"></span>&nbsp;{$uni_sign}</span>
                                                        <span style="font-size:70%;">
                                                            (<span id="uni_vnoska_second_int"></span>
                                                            <span class="uni_sub">.<span
                                                                    id="uni_vnoska_second_dec"></span>&nbsp;{$uni_sign_second}</span>)
                                                        </span>
                                                    </span>
                                                {/if}
                                            </div>
                                        </div>
                                        <div class="uni_gpr_container_row">
                                            <div class="uni_gpr_column">
                                                {l s='GLP' d='Modules.Unipayment.Shop'}
                                            </div>
                                            <div class="uni_gpr_column_right">
                                                <span class="uni_red"><span id="uni_glp_int"></span>%</span>
                                            </div>
                                        </div>
                                        <div class="uni_gpr_container_row">
                                            <div class="uni_gpr_column">
                                                {l s='APR' d='Modules.Unipayment.Shop'}
                                            </div>
                                            <div class="uni_gpr_column_right">
                                                <span class="uni_red"><span id="uni_gpr_int"></span>%</span>
                                            </div>
                                        </div>
                                        <div class="uni_gpr_container_row">
                                            <div class="uni_panel_help_text">
                                                {l s='* The repayment term is chosen when you complete the order.' d='Modules.Unipayment.Shop'}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div style="padding-bottom:20px;"></div>
                            <div class="uni_buttons">
                                <div class="uni_btn_seccondary" id="uni_back_unicredit">
                                    <div class="uni_btn_seccondary_inner">
                                        <div class="uni_btn_seccondary_inner_text">
                                            {l s='Cancel' d='Modules.Unipayment.Shop'}</div>
                                    </div>
                                </div>
                                <div class="uni_btn_seccondary" id="uni_buy_unicredit">
                                    <div class="uni_btn_seccondary_inner">
                                        <div class="uni_btn_seccondary_inner_text">
                                            {l s='Add to cart' d='Modules.Unipayment.Shop'}</div>
                                    </div>
                                </div>
                                <div class="uni_btn_primary" id="uni_buy_on_installment">
                                    <div class="notify-badge"></div>
                                    <div class="uni_btn_primary_inner">
                                        <div class="uni_btn_primary_inner_text">
                                            {l s='Buy on installment' d='Modules.Unipayment.Shop'}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</div>
<!--  Show panel -->