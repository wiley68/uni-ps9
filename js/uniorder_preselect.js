/**
 * След „Купи на изплащане“: бисквитка unipayment_pc — при отваряне на checkout избира UNI плащане.
 * Премахва бисквитката при избор на друг метод.
 */
(function ($) {
    "use strict";

    /** Име на бисквитката за месеци — съвпада с UniPayment::BROWSER_COOKIE_CHECKOUT_INSTALLMENTS */
    var UNIPAYMENT_PC_INST = "unipayment_pc_inst";

    function clearPrefCookie() {
        var secure =
            window.location.protocol === "https:" ? "; Secure" : "";
        document.cookie =
            "unipayment_pc=; Max-Age=0; path=/; SameSite=Lax" + secure;
        document.cookie =
            UNIPAYMENT_PC_INST +
            "=; Max-Age=0; path=/; SameSite=Lax" +
            secure;
    }

    function hasPrefCookie() {
        return document.cookie.split(";").some(function (c) {
            return c.trim().indexOf("unipayment_pc=1") === 0;
        });
    }

    function bindPaymentChangeClear() {
        if (typeof $ === "undefined" || !$.fn) {
            return;
        }
        $(document).on("change", 'input[name="payment-option"]', function () {
            var m = this.getAttribute("data-module-name");
            if (m && m !== "unipayment") {
                clearPrefCookie();
            }
        });
    }

    function trySelectUnipayment() {
        if (!hasPrefCookie()) {
            return;
        }
        if (typeof $ === "undefined" || !$.fn) {
            return;
        }
        var $uni = $(
            'input[name="payment-option"][data-module-name="unipayment"]',
        );
        if (!$uni.length) {
            return;
        }
        if (!$uni.prop("checked")) {
            $uni.prop("checked", true).trigger("change");
        }
    }

    bindPaymentChangeClear();

    if (typeof $ !== "undefined" && $.fn) {
        $(function () {
            trySelectUnipayment();
        });
    } else if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", function () {
            if (!hasPrefCookie()) {
                return;
            }
            var el = document.querySelector(
                'input[name="payment-option"][data-module-name="unipayment"]',
            );
            if (el && !el.checked) {
                el.checked = true;
                el.dispatchEvent(new Event("change", { bubbles: true }));
            }
        });
    }
})(typeof jQuery !== "undefined" ? jQuery : null);
