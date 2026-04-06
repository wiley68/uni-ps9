{*
    * @File: unipanel.tpl
    * @Author: Ilko Ivanov
    * @Author e-mail: ilko.iv@gmail.com
    * @Publisher: Avalon Ltd
    * @Publisher e-mail: home@avalonbg.com
    * @Owner: Avalon Ltd
    * @Version: 1.4.0
*}
{if $uni_status_cp eq 'Yes'}
    {if $uni_container_status eq 'Yes'}
        <div class="unipayment-unipanel-root" role="region"
            aria-label="{l s='UniCredit promotional information' d='Modules.Unipayment.Shop'}">
            {if $deviceis eq 'pc'}
                <div class="uni_float" data-uni-action="toggle" data-uni-backurl="{$uni_backurl|escape:'html':'UTF-8'}"
                    role="button" tabindex="0">
                    <img src="{$uni_logo}" class="uni-my-float" alt="{l s='UniCredit' d='Modules.Unipayment.Shop'}">
                </div>
            {else}
                <div class="uni_float" data-uni-action="goto" data-uni-backurl="{$uni_backurl|escape:'html':'UTF-8'}" role="button"
                    tabindex="0">
                    <img src="{$uni_logo}" class="uni-my-float" alt="{l s='UniCredit' d='Modules.Unipayment.Shop'}">
                </div>
            {/if}
            <div class="uni-label-container">
                <i class="fa fa-play fa-rotate-180 uni-label-arrow" aria-hidden="true"></i>
                <div class="uni-label-text">
                    <div style="padding-bottom:5px;"></div>
                    <img src="{$uni_picture}" alt="">
                    <div style="font-size:16px;padding-top:3px;">{$uni_container_txt1}</div>
                    <p>{$uni_container_txt2}</p>
                    <div class="uni-label-text-a"><a href="{$uni_backurl}" target="_blank" rel="noopener noreferrer"
                            title="{l s='Information about online shopping on credit' d='Modules.Unipayment.Shop'}">{l s='INFORMATION ABOUT ONLINE SHOPPING ON CREDIT' d='Modules.Unipayment.Shop'}</a>
                    </div>
                </div>
            </div>
        </div>
    {/if}
{/if}