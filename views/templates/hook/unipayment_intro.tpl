{*
    * @File: unipayment_intro.tpl
    * @Author: Ilko Ivanov
    * @Author e-mail: ilko.iv@gmail.com
    * @Publisher: Avalon Ltd
    * @Publisher e-mail: home@avalonbg.com
    * @Owner: Avalon Ltd
    * @Version: 1.0.0
*}
<input type="hidden" name="uni_liveurl" id="uni_liveurl" value="{$uni_liveurl|escape:'html':'UTF-8'}" />
<input type="hidden" name="uni_unicid" id="uni_unicid" value="{$uni_unicid|escape:'html':'UTF-8'}" />
<input type="hidden" name="link_to_calculateuni" id="link_to_calculateuni"
    value="{$link_to_calculateuni|escape:'html':'UTF-8'}" />
<input type="hidden" name="link_to_session" id="link_to_session" value="{$link_to_session|escape:'html':'UTF-8'}" />
<input type="hidden" name="uni_promo" id="uni_promo" value="{$uni_promo|escape:'html':'UTF-8'}" />
<input type="hidden" name="uni_promo_data" id="uni_promo_data" value="{$uni_promo_data|escape:'html':'UTF-8'}" />
<input type="hidden" name="uni_promo_meseci_znak" id="uni_promo_meseci_znak"
    value="{$uni_promo_meseci_znak|escape:'html':'UTF-8'}" />
<input type="hidden" name="uni_promo_meseci" id="uni_promo_meseci" value="{$uni_promo_meseci|escape:'html':'UTF-8'}" />
<input type="hidden" name="uni_promo_price" id="uni_promo_price" value="{$uni_promo_price|escape:'html':'UTF-8'}" />
<input type="hidden" name="uni_product_cat_id" id="uni_product_cat_id"
    value="{$uni_product_cat_id|escape:'html':'UTF-8'}" />
<input type="hidden" name="uni_product_category_ids" id="uni_product_category_ids"
    value="{$uni_product_category_ids|escape:'html':'UTF-8'}" />
<input type="hidden" name="uni_service" id="uni_service" value="{$uni_service|escape:'html':'UTF-8'}" />
<input type="hidden" name="uni_user" id="uni_user" value="{$uni_user|escape:'html':'UTF-8'}" />
<input type="hidden" name="uni_password" id="uni_password" value="{$uni_password|escape:'html':'UTF-8'}" />
<input type="hidden" name="uni_sertificat" id="uni_sertificat" value="{$uni_sertificat|escape:'html':'UTF-8'}" />
<input type="hidden" name="uni_real_ip" id="uni_real_ip" value="{$uni_real_ip|escape:'html':'UTF-8'}" />
<input type="hidden" name="uni_proces2" id="uni_proces2" value="{$uni_proces2|escape:'html':'UTF-8'}" />
<input type="hidden" name="uni_proces1" id="uni_proces1" value="{$uni_proces1|escape:'html':'UTF-8'}" />
<input type="hidden" name="uni_check" id="uni_check" value="{$uni_check|escape:'html':'UTF-8'}" />
<input type="hidden" name="uni_fname_get" id="uni_fname_get" value="{$uni_fname_get|escape:'html':'UTF-8'}" />
<input type="hidden" name="uni_lname_get" id="uni_lname_get" value="{$uni_lname_get|escape:'html':'UTF-8'}" />
<input type="hidden" name="uni_egn_get" id="uni_egn_get" value="{$uni_egn_get|escape:'html':'UTF-8'}" />
<input type="hidden" name="uni_phone_get" id="uni_phone_get" value="{$uni_phone_get|escape:'html':'UTF-8'}" />
<input type="hidden" name="uni_email_get" id="uni_email_get" value="{$uni_email_get|escape:'html':'UTF-8'}" />
<input type="hidden" name="uni_shema_current" id="uni_shema_current"
    value="{$uni_shema_current|escape:'html':'UTF-8'}" />
<input type="hidden" name="uni_eur" id="uni_eur" value="{$uni_eur|escape:'html':'UTF-8'}" />
{if isset($uni_checkout_js_strings)}
    <script type="text/javascript">
        window.unipaymentCheckoutStrings = {$uni_checkout_js_strings nofilter};
    </script>
{/if}
<div id="uni-checkout-container" class="unipayment-checkout-scope">
    {if $uni_proces1 eq 1}
        <div class="uni_title">
            {l s='You can select the loan term, your preferred monthly installment, and optionally an initial down payment. Then confirm your choice. You will be redirected to UniCredit to complete your credit purchase.' d='Modules.Unipayment.Shop'}
        </div>
    {else}
        <div class="uni_title">
            {l s='You can select the loan term, your preferred monthly installment, and optionally an initial down payment. Enter the required personal data and accept the terms of use. Then you can confirm your choice. A UniCredit representative will contact you to complete the process.' d='Modules.Unipayment.Shop'}
        </div>
    {/if}
    <div style="padding-bottom:5px;"></div>
    <table class="uni_table">
        <tr>
            <td class="uni_row_title">
                {if $uni_eur == 0 || $uni_eur == 3}
                    {l s='Product price /%s/' sprintf=[$uni_sign] d='Modules.Unipayment.Shop'}
                {else}
                    {l s='Product price /%s/(%s)/' sprintf=[$uni_sign, $uni_sign_second] d='Modules.Unipayment.Shop'}
                {/if}
            </td>
            <td class="uni_row_input">
                <input type="hidden" id="uni_price" value="{$uni_total}" />
                {if $uni_eur == 0 || $uni_eur == 3}
                    <input type="text" class="uni_input passive" readonly="readonly"
                        value="{$uni_total|number_format:2:'.':''}">
                {else}
                    <input type="text" class="uni_input passive" readonly="readonly"
                        value="{$uni_total|number_format:2:'.':''} ({$uni_price_second})">
                {/if}
            </td>
        </tr>
        <tr>
            <td class="uni_row_title">
                {l s='Loan term (months)' d='Modules.Unipayment.Shop'}
            </td>
            <td class="uni_row_input">
                <select name="uni_pogasitelni_vnoski" id="uni_pogasitelni_vnoski" class="uni_input">
                    {foreach from=$uni_checkout_installment_options item=cio}
                        {if $cio.enabled}
                            <option value="{$cio.months|intval}" {if $uni_shema_current eq $cio.months} selected{/if}>
                                {l s='%d months' sprintf=[$cio.months|intval] d='Modules.Unipayment.Shop'}</option>
                        {/if}
                    {/foreach}
                </select>
            </td>
        </tr>
        {if $uni_first_vnoska eq 'Yes'}
            <tr>
                <td class="uni_row_title">
                    <table style="width:100%;padding:0px;margin:0px;">
                        <tr>
                            <td class="uni_row">
                                <input type="checkbox" id="uni_parva_chec"
                                    title="{l s='Check this box if you want to use the initial down payment field.' d='Modules.Unipayment.Shop'}">
                            </td>
                            <td class="uni_row">
                                {l s='Initial payment to the merchant /%s/' sprintf=[$uni_sign] d='Modules.Unipayment.Shop'}
                            </td>
                        </tr>
                    </table>
                </td>
                <td class="uni_row_input">
                    <input class="uni_input" type="text" readonly="readonly" name="uni_parva" id="uni_parva" value="0.00">
                </td>
            </tr>
            <tr>
                <td class="uni_row_title">&nbsp;</td>
                <td class="uni_row_input">
                    <div type="button" class="uni_btn_pre" id="uni_parva_button">
                        {l s='Recalculate' d='Modules.Unipayment.Shop'}</div>
                </td>
            </tr>
        {else}
            <input type="hidden" id="uni_parva_chec" value="0">
            <input type="hidden" name="uni_parva" id="uni_parva" value="0.00">
        {/if}
        <tr>
            <td class="uni_row_title">
                {if $uni_eur == 0 || $uni_eur == 3}
                    {l s='Total loan amount /%s/' sprintf=[$uni_sign] d='Modules.Unipayment.Shop'}
                {else}
                    {l s='Total loan amount /%s/(%s)/' sprintf=[$uni_sign, $uni_sign_second] d='Modules.Unipayment.Shop'}
                {/if}
            </td>
            <td class="uni_row_input">
                <input type="hidden" name="uni_obshto" id="uni_obshto">
                <input class="uni_input passive" type="text" name="uni_obshto_second" id="uni_obshto_second"
                    readonly="readonly">
            </td>
        </tr>
        <tr>
            <td class="uni_row_title">
                {if $uni_eur == 0 || $uni_eur == 3}
                    {l s='Monthly installment /%s/' sprintf=[$uni_sign] d='Modules.Unipayment.Shop'}
                {else}
                    {l s='Monthly installment /%s/(%s)/' sprintf=[$uni_sign, $uni_sign_second] d='Modules.Unipayment.Shop'}
                {/if}
            </td>
            <td class="uni_row_input">
                <input type="hidden" name="uni_mesecna" id="uni_mesecna">
                <input class="uni_input passive" type="text" name="uni_mesecna_second" id="uni_mesecna_second"
                    readonly="readonly" value="">
            </td>
        </tr>
        <tr>
            <td class="uni_row_title">
                {if $uni_eur == 0 || $uni_eur == 3}
                    {l s='Total amount payable /%s/' sprintf=[$uni_sign] d='Modules.Unipayment.Shop'}
                {else}
                    {l s='Total amount payable /%s/(%s)/' sprintf=[$uni_sign, $uni_sign_second] d='Modules.Unipayment.Shop'}
                {/if}
            </td>
            <td class="uni_row_input">
                <input type="hidden" name="uni_obshtozaplashtane" id="uni_obshtozaplashtane">
                <input class="uni_input passive" type="text" name="uni_obshtozaplashtane_second"
                    id="uni_obshtozaplashtane_second" readonly="readonly">
            </td>
        </tr>
        <tr>
            <td class="uni_row_title">
                {l s='GLP /%/' d='Modules.Unipayment.Shop'}
            </td>
            <td class="uni_row_input">
                <input class="uni_input passive" type="text" name="uni_glp" id="uni_glp" readonly="readonly" />
            </td>
        </tr>
        <tr>
            <td class="uni_row_title">
                {l s='APR /%/' d='Modules.Unipayment.Shop'}
            </td>
            <td class="uni_row_input">
                <input class="uni_input passive" type="text" name="uni_gpr" id="uni_gpr" readonly="readonly" />
            </td>
        </tr>
    </table>
    {if $uni_proces2 eq 1}
        <div class="uni_hr">&nbsp;</div>
        <table class="uni_table">
            <tr>
                <td class="uni_row_title">
                    {l s='First name' d='Modules.Unipayment.Shop'}
                </td>
                <td class="uni_row_input">
                    <input id="uni_fname" name="uni_fname" required type="text" class="uni_input"
                        value="{$uni_firstname|escape:'html':'UTF-8'}">
                </td>
            </tr>
            <tr>
                <td class="uni_row_title">
                    {l s='Last name' d='Modules.Unipayment.Shop'}
                </td>
                <td class="uni_row_input">
                    <input id="uni_lname" name="uni_lname" required type="text" class="uni_input"
                        value="{$uni_lastname|escape:'html':'UTF-8'}">
                </td>
            </tr>
            <tr>
                <td class="uni_row_title">
                    {l s='Personal ID' d='Modules.Unipayment.Shop'}
                </td>
                <td class="uni_row_input">
                    <input id="uni_egn" name="uni_egn" required type="text" class="uni_input">
                </td>
            </tr>
            <tr>
                <td class="uni_row_title">
                    {l s='Phone' d='Modules.Unipayment.Shop'}
                </td>
                <td class="uni_row_input">
                    <input id="uni_phone" name="uni_phone" required type="text" class="uni_input"
                        value="{$uni_phone|escape:'html':'UTF-8'}">
                </td>
            </tr>
            <tr>
                <td class="uni_row_title">
                    {l s='Additional phone' d='Modules.Unipayment.Shop'}
                </td>
                <td class="uni_row_input">
                    <input id="uni_phone2" name="uni_phone2" type="text" class="uni_input">
                </td>
            </tr>
            <tr>
                <td class="uni_row_title">
                    {l s='E-mail' d='Modules.Unipayment.Shop'}
                </td>
                <td class="uni_row_input">
                    <input id="uni_email" name="uni_email" required type="text" class="uni_input"
                        value="{$uni_email|escape:'html':'UTF-8'}">
                </td>
            </tr>
            <tr>
                <td class="uni_row_title">
                    {l s='Comment' d='Modules.Unipayment.Shop'}
                </td>
                <td class="uni_row_input">
                    <textarea id="uni_description" name="uni_description" class="uni_input"></textarea>
                </td>
            </tr>
            <tr>
                <td colspan="2">
                    <div class="unipayment-checkout-terms-row">
                        <input type="checkbox" name="uni_uslovia" value="uni_uslovia" id="uni_uslovia" />
                        <a href="{$uni_uslovia|escape:'html':'UTF-8'}" class="unipayment-checkout-terms-link"
                            title="{l s='UniCredit general terms for leasing.' d='Modules.Unipayment.Shop'}" target="_blank"
                            rel="noopener noreferrer">
                            &nbsp;{l s='I have read and agree to the UniCredit terms and conditions' d='Modules.Unipayment.Shop'}
                        </a>
                    </div>
                </td>
            </tr>
        </table>
    {/if}
    <div class="uni_hr">&nbsp;</div>
    <div class="uni_text_cc">C.C.Ver. {$uni_mod_version}</div>
</div>