{*
 * Връщане от плащане: лизинг (uni_proces2) или пренасочване към UniCredit (uni_proces1).
 * PrestaShop 8 — променливите от hookPaymentReturn (unipayment.php).
*}
{if $uni_status eq 'ok'}
    <p>{l s='Your order has been completed.' d='Modules.Unipayment.Shop'}</p>
{else}
    <p class="alert alert-warning">
        {l s='There was a problem with your order. If you believe this is incorrect, you can contact' d='Modules.Unipayment.Shop'}
        <a
            href="{$link->getPageLink('contact', true)|escape:'html':'UTF-8'}">{l s='our support team' d='Modules.Unipayment.Shop'}</a>.
    </p>
{/if}
{if $uni_status eq 'ok' && isset($uni_process2_display) && $uni_process2_display}
    {$result_html nofilter}
{elseif $uni_status eq 'ok' && isset($uni_process1_redirect) && $uni_process1_redirect}
    <div id="unipay-fullscreen-overlay" class="unipay-fs-on" aria-busy="true" role="status" aria-live="polite"
        data-redirect-url="{$uni_redirect_url|escape:'html':'UTF-8'}">
        <div id="uniloaderpanel">
            <div id="uniloader"></div>
            <div id="uniloadertext">{$uni_pause_txt|escape:'html':'UTF-8'}</div>
            <div id="uniloaderimg"><img src="{$uni_logo|escape:'html':'UTF-8'}"
                    alt="{l s='UniCredit' d='Modules.Unipayment.Shop'}" width="120" height="40" loading="eager"
                    fetchpriority="high" /></div>
        </div>
    </div>
{elseif $uni_status eq 'ok' && ($uni_proces1 == 1 || $uni_proces2 == 1)}
    <p class="alert alert-warning">
        {l s='We could not redirect you to the UniCredit system. Please try again or contact us.' d='Modules.Unipayment.Shop'}
    </p>
{/if}