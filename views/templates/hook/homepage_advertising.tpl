<div class="unipayment-advertising" data-unipayment-advertising>
    {if $unipayment_advertising.is_mobile}
        <button type="button" class="unipayment-advertising__float" {if $unipayment_advertising.backurl}
            data-unipayment-advertising-open="{$unipayment_advertising.backurl|escape:'htmlall':'UTF-8'}" {/if}>
            <span class="unipayment-advertising__logo">
                <img src="{$unipayment_advertising.float_image_url|escape:'htmlall':'UTF-8'}"
                    alt="{l s='УниКредит покупки на Кредит' d='Modules.Unipayment.Shop'}">
            </span>
        </button>
    {else}
        <button type="button" class="unipayment-advertising__float" data-unipayment-advertising-toggle
            aria-controls="unipayment-advertising-panel" aria-expanded="false">
            <span class="unipayment-advertising__logo">
                <img src="{$unipayment_advertising.float_image_url|escape:'htmlall':'UTF-8'}"
                    alt="{l s='УниКредит покупки на Кредит' d='Modules.Unipayment.Shop'}">
            </span>
        </button>
        <div id="unipayment-advertising-panel" class="unipayment-advertising__panel" role="dialog"
            aria-label="{l s='Информация за онлайн пазаруване на кредит' d='Modules.Unipayment.Shop'}">
            <div class="unipayment-advertising__arrow" aria-hidden="true"></div>
            <div class="unipayment-advertising__body">
                <div class="unipayment-advertising__spacer"></div>
                {if $unipayment_advertising.picture_url}
                    <img class="unipayment-advertising__picture" alt=""
                        src="{$unipayment_advertising.picture_url|escape:'htmlall':'UTF-8'}">
                {else}
                    <img class="unipayment-advertising__picture" alt="">
                {/if}
                {if $unipayment_advertising.txt1}
                    <div class="unipayment-advertising__title">{$unipayment_advertising.txt1|escape:'htmlall':'UTF-8'}</div>
                {/if}
                {if $unipayment_advertising.txt2}
                    <p>{$unipayment_advertising.txt2|escape:'htmlall':'UTF-8'}</p>
                {/if}
                <div class="unipayment-advertising__link">
                    {if $unipayment_advertising.backurl}
                        <a href="{$unipayment_advertising.backurl|escape:'htmlall':'UTF-8'}" target="_blank" rel="noopener noreferrer">{l s='ИНФОРМАЦИЯ ЗА ОНЛАЙН ПАЗАРУВАНЕ НА КРЕДИТ' d='Modules.Unipayment.Shop'}!</a>
                    {else}
                        {l s='ИНФОРМАЦИЯ ЗА ОНЛАЙН ПАЗАРУВАНЕ НА КРЕДИТ' d='Modules.Unipayment.Shop'}!
                    {/if}
                </div>
            </div>
        </div>
    {/if}
</div>
