{if isset($unipayment_calculator) && $unipayment_calculator}
    <section
        class="unipayment-product-calculator{if $unipayment_calculator.dark_button} unipayment-product-calculator--dark{/if}{if !$unipayment_calculator.show_installment} unipayment-product-calculator--no-installment{/if}{if !$unipayment_calculator.buttons_in_row} unipayment-product-calculator--stacked{/if}"
        data-unipayment-calculator data-product-id="{$unipayment_calculator.product_id|intval}"
        data-endpoint="{$unipayment_calculator_url|escape:'htmlall':'UTF-8'}"
        data-calculator="{$unipayment_calculator_json|escape:'htmlall':'UTF-8'}"
        data-months-label="{l s='%d месеца' d='Modules.Unipayment.Shop'}"
        data-month-label="{l s='месец' d='Modules.Unipayment.Shop'}"
        data-logo-standard="{$unipayment_logo_url|escape:'htmlall':'UTF-8'}"
        data-logo-alternative="{$unipayment_logo_alternative_url|escape:'htmlall':'UTF-8'}"
        data-popup-endpoint="{$unipayment_popup_url|escape:'htmlall':'UTF-8'}"
        data-popup-token="{$unipayment_popup_token|escape:'htmlall':'UTF-8'}"
        data-button-action="{$unipayment_popup.button_action|escape:'htmlall':'UTF-8'}"
        data-checkout-url="{$unipayment_checkout_url|escape:'htmlall':'UTF-8'}"
        data-processing-title="{l s='Обработване на заявката' d='Modules.Unipayment.Shop'}"
        data-processing-message="{l s='Моля, изчакайте...' d='Modules.Unipayment.Shop'}"
        data-smartucf-error-default="{l s='Възникна грешка при обработката на заявката.' d='Modules.Unipayment.Shop'}"
        data-smartucf-error-retry="{l s='Моля, опитайте по-късно.' d='Modules.Unipayment.Shop'}"
        data-close-label="{l s='Затвори' d='Modules.Unipayment.Shop'}"
        data-required-field-message="{l s='Полето е задължително.' d='Modules.Unipayment.Shop'}"
        data-invalid-first-name-message="{l s='Името може да съдържа само букви, интервал, тире и апостроф.' d='Modules.Unipayment.Shop'}"
        data-invalid-last-name-message="{l s='Фамилията може да съдържа само букви, интервал, тире и апостроф.' d='Modules.Unipayment.Shop'}"
        data-invalid-address-message="{l s='Адресът може да съдържа букви, цифри, интервали и стандартни знаци. Не използвайте символи като <, >, =, +, @, {, }, _, $, %, !, ?.' d='Modules.Unipayment.Shop'}"
        data-invalid-phone-message="{l s='Телефонът може да съдържа цифри, интервали, +, -, ( и ).' d='Modules.Unipayment.Shop'}"
        data-invalid-email-message="{l s='Въведете валиден e-mail адрес, например name@example.com.' d='Modules.Unipayment.Shop'}"
        data-invalid-egn-message="{l s='ЕГН трябва да съдържа 10 цифри. Първите 8 трябва да са валидна дата във формат ГГГГММДД.' d='Modules.Unipayment.Shop'}"
        data-invalid-phone2-message="{l s='Вторият телефон може да съдържа цифри, интервали, +, -, ( и ).' d='Modules.Unipayment.Shop'}"
        data-calculate-failed-message="{l s='Неуспешно изчисление. Моля, опитайте отново.' d='Modules.Unipayment.Shop'}"
        data-add-to-cart-failed-message="{l s='Не може да се добави в количката.' d='Modules.Unipayment.Shop'}"
        data-customer-form-missing-message="{l s='Формата за лични данни не се зареди. Моля, презаредете страницата.' d='Modules.Unipayment.Shop'}"
        data-validation-failed-message="{l s='Данните не могат да бъдат валидирани.' d='Modules.Unipayment.Shop'}"
        data-consents-required-message="{l s='Моля, приемете всички задължителни съгласия.' d='Modules.Unipayment.Shop'}"
        data-order-number-label="{l s='Номер на поръчка:' d='Modules.Unipayment.Shop'}"
        data-order-confirmation-message="{l s='Очаквайте потвърждение от УниКредит.' d='Modules.Unipayment.Shop'}"
        data-order-success-title="{l s='Заявката е изпратена успешно' d='Modules.Unipayment.Shop'}"
        style="margin-top: {$unipayment_button_top_spacing|intval}px; --unipayment-button-width: {$unipayment_calculator.button_width|intval}px; --unipayment-button-height: {$unipayment_calculator.button_height|intval}px;">
        {if $unipayment_calculator.heading !== ''}<p class="unipayment-product-calculator__heading">
            {$unipayment_calculator.heading|escape:'htmlall':'UTF-8'}</p>{/if}
        <div class="unipayment-product-calculator__buttons">
            {foreach from=$unipayment_offer_types item=offer_type}
                <button type="button"
                    class="unipayment-product-calculator__button unipayment-product-calculator__button--{$offer_type|escape:'htmlall':'UTF-8'}"
                    data-unipayment-offer="{$offer_type|escape:'htmlall':'UTF-8'}"
                    {if !isset($unipayment_calculator.offers[$offer_type])} hidden{/if}>
                    <span class="unipayment-product-calculator__button-content">
                        <span
                            class="unipayment-product-calculator__button-title">{l s='Купи на изплащане' d='Modules.Unipayment.Shop'}</span>
                        <span class="unipayment-product-calculator__button-price"
                            data-unipayment-preferred-price>{if isset($unipayment_calculator.offers[$offer_type])}{$unipayment_calculator.offers[$offer_type].installment_label|escape:'htmlall':'UTF-8'}{/if}</span>
                    </span>
                    {if $offer_type === 'promo'}
                        <span class="unipayment-product-calculator__badge" aria-hidden="true">0%</span>
                    {else}
                        <span class="unipayment-product-calculator__logo">
                            <img src="{if $unipayment_calculator.dark_button}{$unipayment_logo_alternative_url|escape:'htmlall':'UTF-8'}{else}{$unipayment_logo_url|escape:'htmlall':'UTF-8'}{/if}"
                                alt="{l s='УниКредит' d='Modules.Unipayment.Shop'}" data-unipayment-logo>
                        </span>
                    {/if}
                </button>
            {/foreach}
        </div>

        <div class="unipayment-product-calculator__modal" data-unipayment-modal hidden aria-hidden="true">
            <div class="unipayment-product-calculator__overlay" aria-hidden="true"></div>
            <div class="unipayment-product-calculator__modal-scroll">
                <div class="unipayment-product-calculator__dialog" role="dialog" aria-modal="true" tabindex="-1"
                    aria-labelledby="unipayment-calculator-title-{$unipayment_calculator.product_id|intval}">
                    {if $unipayment_popup.banner_url || $unipayment_popup.banner_url_mobile}
                        <div class="unipayment-product-calculator__banner">
                            {if $unipayment_popup.banner_link}<a href="{$unipayment_popup.banner_link|escape:'htmlall':'UTF-8'}"
                                target="_blank" rel="noopener noreferrer">{/if}
                                <picture>
                                    {if $unipayment_popup.banner_url_mobile}
                                        <source media="(max-width: 768px)"
                                        srcset="{$unipayment_popup.banner_url_mobile|escape:'htmlall':'UTF-8'}">{/if}
                                    <img src="{if $unipayment_popup.banner_url}{$unipayment_popup.banner_url|escape:'htmlall':'UTF-8'}{else}{$unipayment_popup.banner_url_mobile|escape:'htmlall':'UTF-8'}{/if}"
                                        alt="{l s='УниКредит покупки на Кредит' d='Modules.Unipayment.Shop'}">
                                </picture>
                                {if $unipayment_popup.banner_link}
                            </a>{/if}
                        </div>
                    {/if}

                    <div class="unipayment-product-calculator__popup-panel">
                        <div class="unipayment-product-calculator__step unipayment-product-calculator__step--active"
                            data-unipayment-step="1">
                            <h2 id="unipayment-calculator-title-{$unipayment_calculator.product_id|intval}"
                                class="unipayment-product-calculator__popup-title">
                                {l s='Избор на схема за лизинг' d='Modules.Unipayment.Shop'}</h2>
                            <div class="unipayment-product-calculator__popup-calc">
                                <div class="unipayment-product-calculator__popup-row">
                                    <div class="unipayment-product-calculator__popup-label">
                                        {l s='Цена на артикула' d='Modules.Unipayment.Shop'}</div>
                                    <div class="unipayment-product-calculator__popup-value" data-unipayment-display="price">
                                    </div>
                                </div>
                                <div class="unipayment-product-calculator__popup-row">
                                    <label class="unipayment-product-calculator__popup-label"
                                        for="unipayment-months-{$unipayment_calculator.product_id|intval}"><span
                                            class="unipayment-product-calculator__label-desktop">{l s='Брой месеци за погасяване' d='Modules.Unipayment.Shop'}</span><span
                                            class="unipayment-product-calculator__label-mobile">{l s='Брой месеци' d='Modules.Unipayment.Shop'}</span></label>
                                    <div class="unipayment-product-calculator__popup-value"><select
                                            id="unipayment-months-{$unipayment_calculator.product_id|intval}"
                                            class="unipayment-product-calculator__popup-select"
                                            data-unipayment-schemes></select></div>
                                </div>
                                <div class="unipayment-product-calculator__popup-row" data-unipayment-first-row>
                                    <label class="unipayment-product-calculator__popup-label"
                                        for="unipayment-first-{$unipayment_calculator.product_id|intval}">{l s='Първоначална вноска /евро/' d='Modules.Unipayment.Shop'}</label>
                                    <div class="unipayment-product-calculator__popup-value"><input
                                            id="unipayment-first-{$unipayment_calculator.product_id|intval}"
                                            class="unipayment-product-calculator__popup-input" data-unipayment-first
                                            type="text" inputmode="numeric" pattern="[0-9]*" value="0"></div>
                                </div>
                                <div class="unipayment-product-calculator__popup-row">
                                    <div class="unipayment-product-calculator__popup-label">
                                        {l s='Обща сума на заема' d='Modules.Unipayment.Shop'}</div>
                                    <div class="unipayment-product-calculator__popup-value"
                                        data-unipayment-display="financed_amount"></div>
                                </div>
                                <div class="unipayment-product-calculator__popup-row">
                                    <div class="unipayment-product-calculator__popup-label"><span
                                            class="unipayment-product-calculator__label-desktop">{l s='Размер на погасителна вноска' d='Modules.Unipayment.Shop'}</span><span
                                            class="unipayment-product-calculator__label-mobile">{l s='Погасителна вноска' d='Modules.Unipayment.Shop'}</span>
                                    </div>
                                    <div class="unipayment-product-calculator__popup-value"
                                        data-unipayment-display="monthly_installment"></div>
                                </div>
                                <div class="unipayment-product-calculator__popup-row">
                                    <div class="unipayment-product-calculator__popup-label">
                                        {l s='Обща дължима сума' d='Modules.Unipayment.Shop'}</div>
                                    <div class="unipayment-product-calculator__popup-value"
                                        data-unipayment-display="total_payable"></div>
                                </div>
                                <div class="unipayment-product-calculator__popup-row">
                                    <div class="unipayment-product-calculator__popup-label">
                                        {l s='ГЛП' d='Modules.Unipayment.Shop'}</div>
                                    <div class="unipayment-product-calculator__popup-value unipayment-product-calculator__popup-value--red"
                                        data-unipayment-display="glp"></div>
                                </div>
                                <div class="unipayment-product-calculator__popup-row">
                                    <div class="unipayment-product-calculator__popup-label">
                                        {l s='ГПР' d='Modules.Unipayment.Shop'}</div>
                                    <div class="unipayment-product-calculator__popup-value unipayment-product-calculator__popup-value--red"
                                        data-unipayment-display="gpr"></div>
                                </div>
                                <div class="unipayment-product-calculator__popup-row unipayment-product-calculator__popup-row--note"
                                    data-unipayment-popup-error role="alert"></div>
                            </div>

                            <div class="unipayment-product-calculator__popup-actions">
                                <button type="button"
                                    class="unipayment-product-calculator__popup-button unipayment-product-calculator__popup-button--secondary"
                                    data-unipayment-close><span><b>{l s='Отказ' d='Modules.Unipayment.Shop'}</b></span></button>
                                <button type="button"
                                    class="unipayment-product-calculator__popup-button unipayment-product-calculator__popup-button--secondary"
                                    data-unipayment-secondary><span><b>{if $unipayment_popup.button_action == 'buy'}{l s='Купи' d='Modules.Unipayment.Shop'}{else}{l s='Добави в количката' d='Modules.Unipayment.Shop'}{/if}</b></span></button>
                                <button type="button"
                                    class="unipayment-product-calculator__popup-button unipayment-product-calculator__popup-button--primary"
                                    data-unipayment-apply
                                    disabled><span><b>{l s='Кандидатствай' d='Modules.Unipayment.Shop'}</b></span><i
                                        style="background-image:url('{$unipayment_popup_badge_url|escape:'htmlall':'UTF-8'}')"
                                        aria-hidden="true"></i></button>
                            </div>
                        </div>

                        <div class="unipayment-product-calculator__step" data-unipayment-step="2" hidden>
                            <h2 class="unipayment-product-calculator__popup-title">
                                {l s='Попълване на лични данни' d='Modules.Unipayment.Shop'}</h2>
                            <div class="unipayment-product-calculator__customer-form" data-unipayment-customer-form>
                                <div class="unipayment-product-calculator__customer-field">
                                    <label class="unipayment-product-calculator__customer-label"
                                        for="unipayment-first-name-{$unipayment_calculator.product_id|intval}">{l s='Име' d='Modules.Unipayment.Shop'}
                                        <span class="unipayment-product-calculator__required"
                                            aria-hidden="true">*</span></label>
                                    <input class="unipayment-product-calculator__customer-input"
                                        id="unipayment-first-name-{$unipayment_calculator.product_id|intval}"
                                        name="first_name" type="text"
                                        value="{$unipayment_popup.customer.first_name|escape:'htmlall':'UTF-8'}" required
                                        aria-required="true" autocomplete="given-name">
                                    <span class="unipayment-product-calculator__field-error"
                                        data-unipayment-field-error="first_name" role="alert"></span>
                                </div>
                                <div class="unipayment-product-calculator__customer-field">
                                    <label class="unipayment-product-calculator__customer-label"
                                        for="unipayment-last-name-{$unipayment_calculator.product_id|intval}">{l s='Фамилия' d='Modules.Unipayment.Shop'}
                                        <span class="unipayment-product-calculator__required"
                                            aria-hidden="true">*</span></label>
                                    <input class="unipayment-product-calculator__customer-input"
                                        id="unipayment-last-name-{$unipayment_calculator.product_id|intval}"
                                        name="last_name" type="text"
                                        value="{$unipayment_popup.customer.last_name|escape:'htmlall':'UTF-8'}" required
                                        aria-required="true" autocomplete="family-name">
                                    <span class="unipayment-product-calculator__field-error"
                                        data-unipayment-field-error="last_name" role="alert"></span>
                                </div>
                                <div class="unipayment-product-calculator__customer-field">
                                    <label class="unipayment-product-calculator__customer-label"
                                        for="unipayment-address-{$unipayment_calculator.product_id|intval}">{l s='Адрес' d='Modules.Unipayment.Shop'}
                                        <span class="unipayment-product-calculator__required"
                                            aria-hidden="true">*</span></label>
                                    <input class="unipayment-product-calculator__customer-input"
                                        id="unipayment-address-{$unipayment_calculator.product_id|intval}" name="address"
                                        type="text" value="{$unipayment_popup.customer.address|escape:'htmlall':'UTF-8'}"
                                        required aria-required="true" autocomplete="street-address">
                                    <span class="unipayment-product-calculator__field-error"
                                        data-unipayment-field-error="address" role="alert"></span>
                                </div>
                                <div class="unipayment-product-calculator__customer-field">
                                    <label class="unipayment-product-calculator__customer-label"
                                        for="unipayment-phone-{$unipayment_calculator.product_id|intval}">{l s='Мобилен телефон' d='Modules.Unipayment.Shop'}
                                        <span class="unipayment-product-calculator__required"
                                            aria-hidden="true">*</span></label>
                                    <input class="unipayment-product-calculator__customer-input"
                                        id="unipayment-phone-{$unipayment_calculator.product_id|intval}" name="phone"
                                        type="tel" value="{$unipayment_popup.customer.phone|escape:'htmlall':'UTF-8'}"
                                        required aria-required="true" autocomplete="tel" inputmode="tel">
                                    <span class="unipayment-product-calculator__field-error"
                                        data-unipayment-field-error="phone" role="alert"></span>
                                </div>
                                <div class="unipayment-product-calculator__customer-field">
                                    <label class="unipayment-product-calculator__customer-label"
                                        for="unipayment-email-{$unipayment_calculator.product_id|intval}">{l s='E-Mail' d='Modules.Unipayment.Shop'}
                                        <span class="unipayment-product-calculator__required"
                                            aria-hidden="true">*</span></label>
                                    <input class="unipayment-product-calculator__customer-input"
                                        id="unipayment-email-{$unipayment_calculator.product_id|intval}" name="email"
                                        type="email" value="{$unipayment_popup.customer.email|escape:'htmlall':'UTF-8'}"
                                        required aria-required="true" autocomplete="email">
                                    <span class="unipayment-product-calculator__field-error"
                                        data-unipayment-field-error="email" role="alert"></span>
                                </div>
                                {if $unipayment_require_egn}
                                    <div class="unipayment-product-calculator__customer-field">
                                        <label class="unipayment-product-calculator__customer-label"
                                            for="unipayment-egn-{$unipayment_calculator.product_id|intval}">{l s='ЕГН' d='Modules.Unipayment.Shop'}
                                            <span class="unipayment-product-calculator__required"
                                                aria-hidden="true">*</span></label>
                                        <input class="unipayment-product-calculator__customer-input"
                                            id="unipayment-egn-{$unipayment_calculator.product_id|intval}" name="egn"
                                            type="text" inputmode="numeric" pattern="[0-9]*" maxlength="10" value="" required
                                            aria-required="true">
                                        <span class="unipayment-product-calculator__field-error"
                                            data-unipayment-field-error="egn" role="alert"></span>
                                    </div>
                                    <div class="unipayment-product-calculator__customer-field">
                                        <label class="unipayment-product-calculator__customer-label"
                                            for="unipayment-phone2-{$unipayment_calculator.product_id|intval}">{l s='Втори телефон' d='Modules.Unipayment.Shop'}
                                            <span class="unipayment-product-calculator__required"
                                                aria-hidden="true">*</span></label>
                                        <input class="unipayment-product-calculator__customer-input"
                                            id="unipayment-phone2-{$unipayment_calculator.product_id|intval}" name="phone2"
                                            type="tel" value="" required aria-required="true" autocomplete="tel"
                                            inputmode="tel">
                                        <span class="unipayment-product-calculator__field-error"
                                            data-unipayment-field-error="phone2" role="alert"></span>
                                    </div>
                                {/if}
                                <span class="unipayment-product-calculator__field-error" data-unipayment-submit-error
                                    role="alert"></span>
                            </div>
                            {if isset($unipayment_popup.consents) && $unipayment_popup.consents}
                                <div class="unipayment-product-calculator__consents" data-unipayment-consents
                                    aria-label="{l s='Съгласия' d='Modules.Unipayment.Shop'}">
                                    {foreach from=$unipayment_popup.consents item=consent}
                                        <div
                                            class="unipayment-product-calculator__consent{if !$consent.has_checkbox} unipayment-product-calculator__consent--info{/if}">
                                            {if $consent.has_checkbox}
                                                <input type="checkbox" class="unipayment-product-calculator__consent-checkbox"
                                                    id="unipayment-popup-consent-{$unipayment_calculator.product_id|intval}-{$consent.id|intval}"
                                                    name="unipayment_consent[]" value="{$consent.id|intval}"
                                                    data-unipayment-consent-checkbox data-unipayment-consent-id="{$consent.id|intval}">
                                                <label class="unipayment-product-calculator__consent-label"
                                                    for="unipayment-popup-consent-{$unipayment_calculator.product_id|intval}-{$consent.id|intval}">
                                                    {if $consent.url}
                                                        <a href="{$consent.url|escape:'htmlall':'UTF-8'}" target="_blank"
                                                            rel="noopener noreferrer">{$consent.name|escape:'htmlall':'UTF-8'}</a>
                                                    {else}
                                                        {$consent.name|escape:'htmlall':'UTF-8'}
                                                    {/if}
                                                </label>
                                            {else}
                                                <p class="unipayment-product-calculator__consent-text">
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
                            <div
                                class="unipayment-product-calculator__popup-actions unipayment-product-calculator__popup-actions--step2">
                                <div class="unipayment-product-calculator__popup-actions-group">
                                    <button type="button"
                                        class="unipayment-product-calculator__popup-button unipayment-product-calculator__popup-button--secondary"
                                        data-unipayment-back><span><b>{l s='Назад' d='Modules.Unipayment.Shop'}</b></span></button>
                                    <button type="button"
                                        class="unipayment-product-calculator__popup-button unipayment-product-calculator__popup-button--secondary"
                                        data-unipayment-close><span><b>{l s='Отказ' d='Modules.Unipayment.Shop'}</b></span></button>
                                </div>
                                <button type="button"
                                    class="unipayment-product-calculator__popup-button unipayment-product-calculator__popup-button--primary"
                                    data-unipayment-submit disabled
                                    aria-disabled="true"><span><b>{l s='Изпрати' d='Modules.Unipayment.Shop'}</b></span><i
                                        style="background-image:url('{$unipayment_popup_badge_url|escape:'htmlall':'UTF-8'}')"
                                        aria-hidden="true"></i></button>
                            </div>
                        </div>
                        <div class="unipayment-product-calculator__step" data-unipayment-step="3" hidden aria-live="polite"
                            aria-label="{l s='Валидирани данни на заявката' d='Modules.Unipayment.Shop'}"></div>
                    </div>
                </div>
            </div>
            <div class="unipayment-product-calculator__processing" data-unipayment-processing hidden>
                <div class="unipayment-product-calculator__processing-panel" role="status" aria-live="polite"
                    aria-busy="true">
                    <span class="unipayment-product-calculator__processing-spinner" aria-hidden="true"></span>
                    <p class="unipayment-product-calculator__processing-text">
                        {l s='Обработване на заявката. Моля, изчакайте...' d='Modules.Unipayment.Shop'}
                    </p>
                </div>
            </div>
        </div>
    </section>
{/if}
