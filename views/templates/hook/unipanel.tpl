{*
    * @File: unipanel.tpl
    * @Author: Ilko Ivanov
    * @Author e-mail: ilko.iv@gmail.com
    * @Publisher: Avalon Ltd
    * @Publisher e-mail: home@avalonbg.com
    * @Owner: Avalon Ltd
    * @Version: 1.3.0
*}
{if $uni_status_cp eq 'Yes'}
    {if $uni_container_status eq 'Yes'}
        {if $deviceis eq 'pc'}
            <div class="uni_float" onclick="uniChangeContainer();" data-uni-backurl="{$uni_backurl|escape:'html':'UTF-8'}">
                <img src="{$uni_logo}" class="uni-my-float" alt="">
            </div>
        {else}
            <div class="uni_float" onclick="uniGoTo();" data-uni-backurl="{$uni_backurl|escape:'html':'UTF-8'}">
                <img src="{$uni_logo}" class="uni-my-float" alt="">
            </div>
        {/if}
        <div class="uni-label-container">
            <i class="fa fa-play fa-rotate-180 uni-label-arrow"></i>
            <div class="uni-label-text">
                <div style="padding-bottom:5px;"></div>
                <img src="{$uni_picture}" alt="">
                <div style="font-size:16px;padding-top:3px;">{$uni_container_txt1}</div>
                <p>{$uni_container_txt2}</p>
                <div class="uni-label-text-a"><a href="{$uni_backurl}" target="_blank"
                        title="{l s='Information about online shopping on credit' d='Modules.Unipayment.Shop'}">{l s='INFORMATION ABOUT ONLINE SHOPPING ON CREDIT' d='Modules.Unipayment.Shop'}</a></div>
            </div>
        </div>
    {/if}
{/if}
