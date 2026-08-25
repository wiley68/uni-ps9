<form method="post" action="{$unipayment_checkout_action|escape:'htmlall':'UTF-8'}" data-unipayment-checkout-form>
    <div class="unipayment-checkout" data-unipayment-checkout
        data-config="{$unipayment_checkout_json|escape:'htmlall':'UTF-8'}"
        data-calculate-endpoint="{$unipayment_checkout_calculate_url|escape:'htmlall':'UTF-8'}"
        data-popup-token="{$unipayment_checkout_token|escape:'htmlall':'UTF-8'}"
        data-consents-required-message="{l s='Моля, приемете всички задължителни съгласия.' d='Modules.Unipayment.Shop'}"
        data-consents-tooltip="{l s='Моля, първо приемете общите условия, за да продължите с поръчката.' d='Modules.Unipayment.Shop'}"
        data-egn-required-message="{l s='Полето „ЕГН“ е задължително.' d='Modules.Unipayment.Shop'}"
        data-egn-invalid-message="{l s='Въведете валидно ЕГН (10 цифри, първите 8 — дата YYYYMMDD).' d='Modules.Unipayment.Shop'}"
        data-phone2-required-message="{l s='Полето „Втори телефон“ е задължително.' d='Modules.Unipayment.Shop'}"
        data-phone2-invalid-message="{l s='Въведете валиден втори телефонен номер.' d='Modules.Unipayment.Shop'}"
        data-calculate-failed-message="{l s='Неуспешно изчисление. Моля, опитайте отново.' d='Modules.Unipayment.Shop'}"
        data-submitting-message="{l s='Заявката вече се обработва. Моля, изчакайте.' d='Modules.Unipayment.Shop'}">
        <input type="hidden" name="unipayment_checkout_submit" value="1">
        <input type="hidden" name="unipayment_checkout_token"
            value="{$unipayment_checkout_token|escape:'htmlall':'UTF-8'}">
        <input type="hidden" name="unipayment_cart_snapshot"
            value="{$unipayment_checkout.cart_snapshot|escape:'htmlall':'UTF-8'}">
        <input type="hidden" name="unipayment_kop_code" data-unipayment-kop value="">
        <input type="hidden" name="unipayment_scheme_key" data-unipayment-scheme-hidden
            value="{$unipayment_checkout.default_scheme_key|escape:'htmlall':'UTF-8'}">

        <div class="unipayment-checkout__panel">
            <p class="unipayment-checkout__intro">
                {l s="Можете да изберете 'Срок за кредита', предпочитаната от Вас 'Месечна вноска', както и при желание 'Първоначална вноска'. След което да потвърдите избора си. Ще бъдете прехвърлени към страницата на UniCredit за довършване на покупката си на кредит." d='Modules.Unipayment.Shop'}
            </p>

            {if !$unipayment_checkout.has_schemes}
                <p class="unipayment-checkout__notice">
                    {l s='Няма налични схеми за текущата поръчка.' d='Modules.Unipayment.Shop'}</p>
            {else}
                <div class="unipayment-checkout__calc">
                    <div class="unipayment-checkout__calc-fields">
                        <div class="unipayment-checkout__row">
                            <div class="unipayment-checkout__label">{l s='Цена на поръчката' d='Modules.Unipayment.Shop'}
                            </div>
                            <div
                                class="unipayment-checkout__value{if $unipayment_checkout.currency_dual} unipayment-checkout__value--dual{/if}">
                                <span class="unipayment-checkout__amount-primary"
                                    data-unipayment-display="price-primary"></span>
                                <span class="unipayment-checkout__amount-secondary"
                                    data-unipayment-display="price-secondary"></span>
                            </div>
                        </div>

                        <div class="unipayment-checkout__row">
                            <div class="unipayment-checkout__label">
                                <label
                                    for="unipayment-checkout-months">{l s='Брой месеци за погасяване' d='Modules.Unipayment.Shop'}</label>
                            </div>
                            <div class="unipayment-checkout__value">
                                <select id="unipayment-checkout-months" class="unipayment-checkout__select"
                                    data-unipayment-scheme required>
                                    {foreach from=$unipayment_checkout.schemes item=scheme}
                                        <option value="{$scheme.key|escape:'htmlall':'UTF-8'}"
                                            data-kop="{$scheme.kop_code|escape:'htmlall':'UTF-8'}"
                                            {if $scheme.key === $unipayment_checkout.default_scheme_key} selected{/if}>
                                            {$scheme.months|intval}
                                            {l s='месеца' d='Modules.Unipayment.Shop'}{if $scheme.description} -
                                            {$scheme.description|escape:'htmlall':'UTF-8'}{/if}&nbsp;&nbsp;&nbsp;</option>
                                    {/foreach}
                                </select>
                            </div>
                        </div>

                        <div class="unipayment-checkout__row{if !$unipayment_checkout.show_first_installment} unipayment-checkout__row--hidden{/if}"
                            data-unipayment-first-row>
                            <div class="unipayment-checkout__label">
                                <label
                                    for="unipayment-checkout-first">{l s='Първоначална вноска /евро/' d='Modules.Unipayment.Shop'}</label>
                            </div>
                            <div class="unipayment-checkout__value">
                                <input id="unipayment-checkout-first" class="unipayment-checkout__input" type="text"
                                    inputmode="numeric" pattern="[0-9]*" name="unipayment_first_installment"
                                    data-unipayment-first
                                    value="{if $unipayment_checkout.default_first_installment > 0}{$unipayment_checkout.default_first_installment|string_format:'%.0f'}{else}0{/if}">
                            </div>
                        </div>

                        <div class="unipayment-checkout__row">
                            <div class="unipayment-checkout__label">{l s='Обща сума на заема' d='Modules.Unipayment.Shop'}
                            </div>
                            <div
                                class="unipayment-checkout__value{if $unipayment_checkout.currency_dual} unipayment-checkout__value--dual{/if}">
                                <span class="unipayment-checkout__amount-primary"
                                    data-unipayment-display="financed_amount-primary"></span>
                                <span class="unipayment-checkout__amount-secondary"
                                    data-unipayment-display="financed_amount-secondary"></span>
                            </div>
                        </div>

                        <div class="unipayment-checkout__row">
                            <div class="unipayment-checkout__label">
                                {l s='Размер на погасителна вноска' d='Modules.Unipayment.Shop'}</div>
                            <div
                                class="unipayment-checkout__value{if $unipayment_checkout.currency_dual} unipayment-checkout__value--dual{/if}">
                                <span class="unipayment-checkout__amount-primary"
                                    data-unipayment-display="monthly_installment-primary"></span>
                                <span class="unipayment-checkout__amount-secondary"
                                    data-unipayment-display="monthly_installment-secondary"></span>
                            </div>
                        </div>

                        <div class="unipayment-checkout__row">
                            <div class="unipayment-checkout__label">{l s='Обща дължима сума' d='Modules.Unipayment.Shop'}
                            </div>
                            <div
                                class="unipayment-checkout__value{if $unipayment_checkout.currency_dual} unipayment-checkout__value--dual{/if}">
                                <span class="unipayment-checkout__amount-primary"
                                    data-unipayment-display="total_payable-primary"></span>
                                <span class="unipayment-checkout__amount-secondary"
                                    data-unipayment-display="total_payable-secondary"></span>
                            </div>
                        </div>

                        <div class="unipayment-checkout__row">
                            <div class="unipayment-checkout__label">{l s='ГЛП' d='Modules.Unipayment.Shop'}</div>
                            <div class="unipayment-checkout__value">
                                <span class="unipayment-checkout__percent" data-unipayment-display="glp"></span>
                            </div>
                        </div>

                        <div class="unipayment-checkout__row">
                            <div class="unipayment-checkout__label">{l s='ГПР' d='Modules.Unipayment.Shop'}</div>
                            <div class="unipayment-checkout__value">
                                <span class="unipayment-checkout__percent" data-unipayment-display="gpr"></span>
                            </div>
                        </div>

                        {if $unipayment_checkout.process2}
                            <div class="unipayment-checkout__row">
                                <div class="unipayment-checkout__label">
                                    <label for="unipayment-checkout-egn">{l s='ЕГН' d='Modules.Unipayment.Shop'} <span
                                            class="unipayment-checkout__required" aria-hidden="true">*</span></label>
                                </div>
                                <div class="unipayment-checkout__value">
                                    <input id="unipayment-checkout-egn" class="unipayment-checkout__input" type="text"
                                        name="unipayment_egn" maxlength="10" pattern="[0-9]{10}" inputmode="numeric"
                                        autocomplete="off" required aria-required="true" data-unipayment-egn>
                                </div>
                            </div>
                            <div class="unipayment-checkout__row">
                                <div class="unipayment-checkout__label">
                                    <label for="unipayment-checkout-phone2">{l s='Втори телефон' d='Modules.Unipayment.Shop'}
                                        <span class="unipayment-checkout__required" aria-hidden="true">*</span></label>
                                </div>
                                <div class="unipayment-checkout__value">
                                    <input id="unipayment-checkout-phone2" class="unipayment-checkout__input" type="tel"
                                        name="unipayment_phone2" inputmode="tel" autocomplete="tel" required
                                        aria-required="true" data-unipayment-phone2>
                                </div>
                            </div>
                        {else}
                            <input type="hidden" name="unipayment_egn" value="">
                            <input type="hidden" name="unipayment_phone2" value="">
                        {/if}
                    </div>
                </div>
            {/if}

            {if $unipayment_checkout.consents}
                <div class="unipayment-checkout__consents" data-unipayment-consents
                    aria-label="{l s='Съгласия' d='Modules.Unipayment.Shop'}">
                    {foreach from=$unipayment_checkout.consents item=consent}
                        <div
                            class="unipayment-checkout__consent{if !$consent.has_checkbox} unipayment-checkout__consent--info{/if}">
                            {if $consent.has_checkbox}
                                <input type="checkbox" class="unipayment-checkout__consent-checkbox"
                                    id="unipayment-checkout-consent-{$consent.id|intval}" name="unipayment_consent[]"
                                    value="{$consent.id|intval}" data-unipayment-consent-checkbox
                                    data-unipayment-consent-id="{$consent.id|intval}">
                                <label class="unipayment-checkout__consent-label"
                                    for="unipayment-checkout-consent-{$consent.id|intval}">
                                    {if $consent.url}
                                        <a href="{$consent.url|escape:'htmlall':'UTF-8'}" target="_blank"
                                            rel="noopener noreferrer">{$consent.name|escape:'htmlall':'UTF-8'}</a>
                                    {else}
                                        {$consent.name|escape:'htmlall':'UTF-8'}
                                    {/if}
                                </label>
                            {else}
                                <p class="unipayment-checkout__consent-text">
                                    {if $consent.url}
                                        <a href="{$consent.url|escape:'htmlall':'UTF-8'}" target="_blank"
                                            rel="noopener noreferrer">{$consent.name|escape:'htmlall':'UTF-8'}</a>
                                    {else}
                                        {$consent.name|escape:'htmlall':'UTF-8'}
                                    {/if}
                                </p>
                            {/if}
                        </div>
                    {/foreach}
                </div>
            {/if}

            <p class="unipayment-checkout__error" data-unipayment-checkout-error role="alert" hidden></p>
        </div>
    </div>
</form>
