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
<style>
    #unipay-fullscreen-overlay {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 2147483000;
        width: 100%;
        height: 100%;
        box-sizing: border-box;
        margin: 0;
        padding: 16px;
        align-items: center;
        justify-content: center;
        flex-direction: column;
        background: rgba(255, 255, 255, 0.94);
        pointer-events: auto;
        cursor: wait;
        -webkit-user-select: none;
        user-select: none;
    }

    #unipay-fullscreen-overlay.unipay-fs-on {
        display: flex;
    }

    #uniloaderpanel {
        position: relative;
        background: #fff;
        border: 2px solid #f3f3f3;
        width: 400px;
        max-width: 100%;
        min-height: 90px;
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
    }

    #uniloader {
        position: absolute;
        top: 10px;
        left: 10px;
        border: 16px solid #f3f3f3;
        border-radius: 50%;
        border-top: 16px solid #f07524;
        width: 70px;
        height: 70px;
        -webkit-animation: spin 2s linear infinite;
        animation: spin 2s linear infinite;
    }

    #uniloadertext {
        position: absolute;
        top: 0px;
        left: 90px;
        padding: 10px;
        width: calc(100% - 90px);
        font-size: 12px;
        font-weight: bold;
        text-align: center;
        color: #f07524;
    }

    #uniloaderimg {
        position: absolute;
        top: 45px;
        width: 100%;
        text-align: center;
    }

    @-webkit-keyframes spin {
        0% {
            -webkit-transform: rotate(0deg);
        }

        100% {
            -webkit-transform: rotate(360deg);
        }
    }

    @keyframes spin {
        0% {
            transform: rotate(0deg);
        }

        100% {
            transform: rotate(360deg);
        }
    }
</style>
{if $uni_status eq 'ok' && isset($uni_process2_display) && $uni_process2_display}
    {$result_html nofilter}
{elseif $uni_status eq 'ok' && isset($uni_process1_redirect) && $uni_process1_redirect}
    <div id="unipay-fullscreen-overlay" class="unipay-fs-on" aria-busy="true" role="status" aria-live="polite">
        <div id="uniloaderpanel">
            <div id="uniloader"></div>
            <div id="uniloadertext">{$uni_pause_txt|escape:'html':'UTF-8'}</div>
            <div id="uniloaderimg"><img src="{$uni_logo|escape:'html':'UTF-8'}"
                    alt="{l s='UniCredit' d='Modules.Unipayment.Shop'}" width="120" height="40" loading="eager"
                    fetchpriority="high" /></div>
        </div>
    </div>
    <script>
        (function() {
            var overlay = document.getElementById('unipay-fullscreen-overlay');
            var html = document.documentElement;
            var body = document.body;
            if (overlay) {
                overlay.classList.add('unipay-fs-on');
            }
            html.style.overflow = 'hidden';
            body.style.overflow = 'hidden';
            var target = {$uni_redirect_json nofilter};
            if (typeof target === 'string' && (target.indexOf('https://') === 0 || target.indexOf('http://') === 0)) {
                window.location.href = target;
            } else {
                html.style.overflow = '';
                body.style.overflow = '';
                if (overlay) {
                    overlay.classList.remove('unipay-fs-on');
                    overlay.style.display = 'none';
                }
            }
        })();
    </script>
{elseif $uni_status eq 'ok' && ($uni_proces1 == 1 || $uni_proces2 == 1)}
    <p class="alert alert-warning">
        {l s='We could not redirect you to the UniCredit system. Please try again or contact us.' d='Modules.Unipayment.Shop'}
    </p>
{/if}