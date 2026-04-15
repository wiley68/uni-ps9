/** UNI блок — страница количка (сума = общо продукти). */
let uni_old_vnoski_cart;
const UNIPAYMENT_EUR_BGN_RATE = 1.95583;
/** @type {ReturnType<typeof setTimeout>|null} */
let unipaymentCartRefreshTimer = null;
let unipaymentCartInstallmentInitialized = false;

function unipaymentUpdateCartButtonLabels(uniVnoski, uniMesecna) {
    const elCnt = document.getElementById("uni_button_installments_label");
    const elMain = document.getElementById("uni_button_mesecna_main");
    const elSecond = document.getElementById("uni_button_mesecna_second");
    const uniSignEl = document.getElementById("uni_sign");
    const uniSignSecondEl = document.getElementById("uni_sign_second");
    const uniEurEl = document.getElementById("uni_eur");
    if (!elCnt || !elMain || !uniSignEl || !uniEurEl) {
        return;
    }
    elCnt.textContent = String(uniVnoski);
    const uni_sign = uniSignEl.value;
    elMain.textContent = uniMesecna.toFixed(2) + " " + uni_sign;

    if (!elSecond || !uniSignSecondEl) {
        return;
    }
    const uni_eur = parseInt(uniEurEl.value, 10);
    const uni_sign_second = uniSignSecondEl.value;
    if (uni_eur === 1) {
        const s = (uniMesecna / UNIPAYMENT_EUR_BGN_RATE).toFixed(2);
        elSecond.textContent = " (" + s + " " + uni_sign_second + ")";
        elSecond.style.display = "";
        elSecond.style.fontSize = "80%";
    } else if (uni_eur === 2) {
        const s = (uniMesecna * UNIPAYMENT_EUR_BGN_RATE).toFixed(2);
        elSecond.textContent = " (" + s + " " + uni_sign_second + ")";
        elSecond.style.display = "";
        elSecond.style.fontSize = "80%";
    } else {
        elSecond.textContent = "";
        elSecond.style.display = "none";
    }
}

function unipaymentRelinkCartPopup() {
    const $all = $("#uni-cart-popup-container");
    if ($all.length === 0) {
        return;
    }
    const $host = $(".cart-grid, #wrapper, #content-wrapper, body").first();
    let $keep = $all.last();
    if ($host.length) {
        const $inside = $all.filter(function () {
            return $.contains($host[0], this);
        });
        if ($inside.length) {
            $keep = $inside.last();
        }
    }
    $all.not($keep).remove();
    $keep.prependTo("body");
}

function unipaymentGetPopupInstallmentsMonthsCart() {
    const el = document.getElementById("uni_pogasitelni_vnoski_input");
    if (!el) {
        return 0;
    }
    const v = parseInt(String(el.value), 10);
    return Number.isNaN(v) ? 0 : v;
}

/**
 * Parse валутна стойност от DOM текст (поддържа "1 234,56", "1,234.56", "1234.56").
 *
 * @param {string} raw
 * @returns {number}
 */
function unipaymentParseLocalizedAmount(raw) {
    if (!raw) {
        return NaN;
    }
    let s = String(raw)
        .replace(/\u00A0/g, " ")
        .replace(/\s+/g, "")
        .replace(/[^\d,.-]/g, "");
    if (!s) {
        return NaN;
    }
    const hasComma = s.indexOf(",") !== -1;
    const hasDot = s.indexOf(".") !== -1;
    if (hasComma && hasDot) {
        // Последният разделител е десетичният; другият се третира като хиляден.
        if (s.lastIndexOf(",") > s.lastIndexOf(".")) {
            s = s.replace(/\./g, "").replace(",", ".");
        } else {
            s = s.replace(/,/g, "");
        }
    } else if (hasComma) {
        s = s.replace(",", ".");
    }
    const n = parseFloat(s);
    return Number.isNaN(n) ? NaN : n;
}

/**
 * Чете текущата сума "само продукти" от summary в количката.
 *
 * @returns {number}
 */
function unipaymentReadCartProductsTotalFromDom() {
    const selectors = [
        // Hummingbird / нови теми
        '[data-role="cart-summary-total-products"] .value',
        '[data-role="cart-subtotal-products"] .value',
        '[data-cart-subtotal="products"] .value',
        '[data-cart-subtotal="products"] [class*="value"]',
        '[data-role="cart-subtotal-products"]',
        '[data-cart-subtotal="products"]',
        '[data-cart-total-value]',
        '[data-cart-total]',
        // Classic / стандартни теми
        ".cart-summary-subtotals-container .cart-summary-line .value",
        ".cart-summary-line#cart-subtotal-products .value",
        "#cart-subtotal-products .value",
        ".cart-subtotal-products .value",
        ".cart-subtotal-products",
    ];

    for (let i = 0; i < selectors.length; i += 1) {
        const el = document.querySelector(selectors[i]);
        if (!el || !el.textContent) {
            continue;
        }
        const rawFromDataAttr =
            (el.getAttribute && el.getAttribute("data-cart-total-value")) || "";
        const candidateRaw = rawFromDataAttr || el.textContent;
        const parsed = unipaymentParseLocalizedAmount(candidateRaw);
        if (!Number.isNaN(parsed) && parsed > 0) {
            return parsed;
        }
    }

    // Hummingbird fallback: смятаме тотала от редовете в количката (unit price * qty).
    let linesTotal = 0;
    let hasLines = false;
    const rows = document.querySelectorAll(".cart__item .product-line, .product-line");
    rows.forEach(function (row) {
        const unitPriceEl = row.querySelector(".product-line__current .price");
        const qtyEl = row.querySelector(".js-cart-line-product-quantity");
        if (!unitPriceEl || !qtyEl) {
            return;
        }
        const unit = unipaymentParseLocalizedAmount(unitPriceEl.textContent || "");
        const qty = parseFloat(String(qtyEl.value).replace(",", "."));
        if (Number.isNaN(unit) || Number.isNaN(qty) || qty <= 0) {
            return;
        }
        hasLines = true;
        linesTotal += unit * qty;
    });
    if (hasLines && linesTotal > 0) {
        return linesTotal;
    }

    return NaN;
}

/**
 * @param {number} total
 * @returns {boolean}
 */
function unipaymentApplyCartTotalToPopupDom(total) {
    const uniPriceEl = document.getElementById("uni_price");
    const uniPriceIntEl = document.getElementById("uni_price_int");
    const uniPriceDecEl = document.getElementById("uni_price_dec");
    const uniEurEl = document.getElementById("uni_eur");
    if (!uniPriceEl || !uniPriceIntEl || !uniPriceDecEl || !uniEurEl) {
        return false;
    }

    const uni_eur = parseInt(uniEurEl.value, 10);
    uniPriceEl.value = String(total);
    uniPriceIntEl.textContent = String(Math.floor(total));
    uniPriceDecEl.textContent = String(
        Math.ceil((total - Math.trunc(total)) * 100),
    ).padStart(2, "0");

    const uni_price_second_int = document.getElementById("uni_price_second_int");
    const uni_price_second_dec = document.getElementById("uni_price_second_dec");
    if (uni_price_second_int !== null && uni_price_second_dec !== null) {
        if (uni_eur === 1) {
            const second = (total / UNIPAYMENT_EUR_BGN_RATE).toFixed(2).split(".");
            uni_price_second_int.textContent = second[0];
            uni_price_second_dec.textContent = second[1];
        } else if (uni_eur === 2) {
            const second = (total * UNIPAYMENT_EUR_BGN_RATE).toFixed(2).split(".");
            uni_price_second_int.textContent = second[0];
            uni_price_second_dec.textContent = second[1];
        }
    }

    return true;
}

function unipaymentRefreshCartInstallmentFromCurrentTotal() {
    const uniPriceEl = document.getElementById("uni_price");
    if (!uniPriceEl) {
        return;
    }

    let total = unipaymentReadCartProductsTotalFromDom();
    if (Number.isNaN(total) || total <= 0) {
        total = parseFloat(String(uniPriceEl.value).replace(",", "."));
    }
    if (Number.isNaN(total) || total <= 0) {
        return;
    }

    const currentHidden = parseFloat(String(uniPriceEl.value).replace(",", "."));
    const nextRounded = Math.round(total * 100);
    const currentRounded = Number.isNaN(currentHidden)
        ? NaN
        : Math.round(currentHidden * 100);
    if (
        unipaymentCartInstallmentInitialized &&
        !Number.isNaN(currentRounded) &&
        currentRounded === nextRounded
    ) {
        return;
    }

    if (!unipaymentApplyCartTotalToPopupDom(total)) {
        return;
    }
    unipaymentCartInstallmentInitialized = true;
    uniCartPogasitelniInputChange();
}

function scheduleUnipaymentCartRefresh() {
    if (unipaymentCartRefreshTimer !== null) {
        clearTimeout(unipaymentCartRefreshTimer);
    }
    unipaymentCartRefreshTimer = setTimeout(function () {
        unipaymentCartRefreshTimer = null;
        unipaymentRelinkCartPopup();
        unipaymentRefreshCartInstallmentFromCurrentTotal();
    }, 120);
}

function uniCartPogasitelniInputChange() {
    const uniGetProductLinkEl = document.getElementById("uni_get_product_link");
    const uniVnoskiEl = document.getElementById("uni_pogasitelni_vnoski_input");
    const uniPriceEl = document.getElementById("uni_price");
    if (!uniGetProductLinkEl || !uniVnoskiEl || !uniPriceEl) {
        return;
    }
    const uni_get_product_link = uniGetProductLinkEl.value;
    const uni_vnoski = parseFloat(uniVnoskiEl.value);
    const uni_price = parseFloat(uniPriceEl.value);
    const uni_param_kimb_3 = parseFloat(
        document.getElementById("uni_param_kimb_3").value,
    );
    const uni_param_kimb_4 = parseFloat(
        document.getElementById("uni_param_kimb_4").value,
    );
    const uni_param_kimb_5 = parseFloat(
        document.getElementById("uni_param_kimb_5").value,
    );
    const uni_param_kimb_6 = parseFloat(
        document.getElementById("uni_param_kimb_6").value,
    );
    const uni_param_kimb_9 = parseFloat(
        document.getElementById("uni_param_kimb_9").value,
    );
    const uni_param_kimb_10 = parseFloat(
        document.getElementById("uni_param_kimb_10").value,
    );
    const uni_param_kimb_12 = parseFloat(
        document.getElementById("uni_param_kimb_12").value,
    );
    const uni_param_kimb_18 = parseFloat(
        document.getElementById("uni_param_kimb_18").value,
    );
    const uni_param_kimb_24 = parseFloat(
        document.getElementById("uni_param_kimb_24").value,
    );
    const uni_param_kimb_30 = parseFloat(
        document.getElementById("uni_param_kimb_30").value,
    );
    const uni_param_kimb_36 = parseFloat(
        document.getElementById("uni_param_kimb_36").value,
    );

    $.ajax({
        url: uni_get_product_link,
        type: "POST",
        dataType: "json",
        data: {
            uni_vnoski: uni_vnoski,
            uni_price: uni_price,
            uni_param_kimb_3: uni_param_kimb_3,
            uni_param_kimb_4: uni_param_kimb_4,
            uni_param_kimb_5: uni_param_kimb_5,
            uni_param_kimb_6: uni_param_kimb_6,
            uni_param_kimb_9: uni_param_kimb_9,
            uni_param_kimb_10: uni_param_kimb_10,
            uni_param_kimb_12: uni_param_kimb_12,
            uni_param_kimb_18: uni_param_kimb_18,
            uni_param_kimb_24: uni_param_kimb_24,
            uni_param_kimb_30: uni_param_kimb_30,
            uni_param_kimb_36: uni_param_kimb_36,
        },
        success: function (json) {
            if (json.success == "success") {
                const uni_eur = parseInt(
                    document.getElementById("uni_eur").value,
                );
                let uni_mesecna = 0;
                let uni_glp = 0;
                let uni_gpr = 0;
                switch (uni_vnoski) {
                    case 3:
                        uni_mesecna = parseFloat(json.uni_mesecna_3);
                        uni_gpr = parseFloat(json.uni_gpr_3);
                        uni_glp = parseFloat(
                            document.getElementById("uni_param_glp_3").value,
                        );
                        break;
                    case 4:
                        uni_mesecna = parseFloat(json.uni_mesecna_4);
                        uni_gpr = parseFloat(json.uni_gpr_4);
                        uni_glp = parseFloat(
                            document.getElementById("uni_param_glp_4").value,
                        );
                        break;
                    case 5:
                        uni_mesecna = parseFloat(json.uni_mesecna_5);
                        uni_gpr = parseFloat(json.uni_gpr_5);
                        uni_glp = parseFloat(
                            document.getElementById("uni_param_glp_5").value,
                        );
                        break;
                    case 6:
                        uni_mesecna = parseFloat(json.uni_mesecna_6);
                        uni_gpr = parseFloat(json.uni_gpr_6);
                        uni_glp = parseFloat(
                            document.getElementById("uni_param_glp_6").value,
                        );
                        break;
                    case 9:
                        uni_mesecna = parseFloat(json.uni_mesecna_9);
                        uni_gpr = parseFloat(json.uni_gpr_9);
                        uni_glp = parseFloat(
                            document.getElementById("uni_param_glp_9").value,
                        );
                        break;
                    case 10:
                        uni_mesecna = parseFloat(json.uni_mesecna_10);
                        uni_gpr = parseFloat(json.uni_gpr_10);
                        uni_glp = parseFloat(
                            document.getElementById("uni_param_glp_10").value,
                        );
                        break;
                    case 12:
                        uni_mesecna = parseFloat(json.uni_mesecna_12);
                        uni_gpr = parseFloat(json.uni_gpr_12);
                        uni_glp = parseFloat(
                            document.getElementById("uni_param_glp_12").value,
                        );
                        break;
                    case 18:
                        uni_mesecna = parseFloat(json.uni_mesecna_18);
                        uni_gpr = parseFloat(json.uni_gpr_18);
                        uni_glp = parseFloat(
                            document.getElementById("uni_param_glp_18").value,
                        );
                        break;
                    case 24:
                        uni_mesecna = parseFloat(json.uni_mesecna_24);
                        uni_gpr = parseFloat(json.uni_gpr_24);
                        uni_glp = parseFloat(
                            document.getElementById("uni_param_glp_24").value,
                        );
                        break;
                    case 30:
                        uni_mesecna = parseFloat(json.uni_mesecna_30);
                        uni_gpr = parseFloat(json.uni_gpr_30);
                        uni_glp = parseFloat(
                            document.getElementById("uni_param_glp_30").value,
                        );
                        break;
                    case 36:
                        uni_mesecna = parseFloat(json.uni_mesecna_36);
                        uni_gpr = parseFloat(json.uni_gpr_36);
                        uni_glp = parseFloat(
                            document.getElementById("uni_param_glp_36").value,
                        );
                        break;
                    default:
                        uni_mesecna = parseFloat(json.uni_mesecna_3);
                        uni_gpr = parseFloat(json.uni_gpr_3);
                        uni_glp = parseFloat(
                            document.getElementById("uni_param_glp_3").value,
                        );
                }
                const uni_vnoska_int =
                    document.getElementById("uni_vnoska_int");
                const uni_vnoska_dec =
                    document.getElementById("uni_vnoska_dec");
                const uni_vnoska_second_int = document.getElementById(
                    "uni_vnoska_second_int",
                );
                const uni_vnoska_second_dec = document.getElementById(
                    "uni_vnoska_second_dec",
                );
                const uni_glp_int = document.getElementById("uni_glp_int");
                const uni_gpr_int = document.getElementById("uni_gpr_int");
                const uni_vnoska_arr = uni_mesecna.toFixed(2).split(".");
                uni_vnoska_int.textContent = uni_vnoska_arr[0];
                uni_vnoska_dec.textContent = uni_vnoska_arr[1];
                if (
                    uni_vnoska_second_int !== null &&
                    uni_vnoska_second_dec !== null
                ) {
                    if (uni_eur == 1) {
                        const uni_vnoska_second_arr = (
                            uni_mesecna / UNIPAYMENT_EUR_BGN_RATE
                        )
                            .toFixed(2)
                            .split(".");
                        uni_vnoska_second_int.textContent =
                            uni_vnoska_second_arr[0];
                        uni_vnoska_second_dec.textContent =
                            uni_vnoska_second_arr[1];
                    }
                    if (uni_eur == 2) {
                        const uni_vnoska_second_arr = (
                            uni_mesecna * UNIPAYMENT_EUR_BGN_RATE
                        )
                            .toFixed(2)
                            .split(".");
                        uni_vnoska_second_int.textContent =
                            uni_vnoska_second_arr[0];
                        uni_vnoska_second_dec.textContent =
                            uni_vnoska_second_arr[1];
                    }
                }
                uni_glp_int.textContent = uni_glp.toFixed(2);
                uni_gpr_int.textContent = uni_gpr.toFixed(2);
                unipaymentUpdateCartButtonLabels(uni_vnoski, uni_mesecna);
            }
        },
    });
}

$(document).ready(function () {
    if (!document.getElementById("uni_prepare_cart_checkout_url")) {
        return;
    }
    const uniPriceEl = document.getElementById("uni_price");
    if (!uniPriceEl) {
        return;
    }

    $("#uni-cart-popup-container").prependTo("body");
    unipaymentRelinkCartPopup();
    unipaymentRefreshCartInstallmentFromCurrentTotal();

    $(document).on("change", "#uni_pogasitelni_vnoski_input", function () {
        uniCartPogasitelniInputChange();
    });

    $(document).on("focus", "#uni_pogasitelni_vnoski_input", function () {
        uni_old_vnoski_cart = $(this).val();
    });

    $(document).on("click", "#btn_uni", function () {
        unipaymentRelinkCartPopup();
        $("#uni-cart-popup-container").first().show("slow");
        unipaymentRefreshCartInstallmentFromCurrentTotal();
    });

    // Hummingbird: директни UI действия в количката.
    $(document).on(
        "click",
        ".js-increment-button, .js-decrement-button, .remove-from-cart, [data-link-action='delete-from-cart']",
        function () {
            scheduleUnipaymentCartRefresh();
            setTimeout(scheduleUnipaymentCartRefresh, 220);
            setTimeout(scheduleUnipaymentCartRefresh, 500);
        },
    );
    $(document).on("change input", ".js-cart-line-product-quantity", function () {
        scheduleUnipaymentCartRefresh();
        setTimeout(scheduleUnipaymentCartRefresh, 220);
    });

    // Ajax количка (напр. Hummingbird): обновяваме вноската след client-side refresh на summary.
    if (
        typeof prestashop !== "undefined" &&
        typeof prestashop.on === "function"
    ) {
        prestashop.on("updatedCart", function () {
            scheduleUnipaymentCartRefresh();
        });
        prestashop.on("updateCart", function () {
            scheduleUnipaymentCartRefresh();
        });
        prestashop.on("cartUpdated", function () {
            scheduleUnipaymentCartRefresh();
        });
    }

    // Fallback за теми, които не emit-ват горните събития.
    // Игнорираме module ajax повикванията, за да не се получава рекурсивен refresh.
    $(document).ajaxComplete(function (_evt, _xhr, settings) {
        const url = settings && settings.url ? String(settings.url) : "";
        if (
            url.indexOf("module/unipayment/getproduct") !== -1 ||
            url.indexOf("module/unipayment/preparecartcheckout") !== -1
        ) {
            return;
        }
        if (
            url.indexOf("module/ps_shoppingcart/ajax") !== -1 ||
            url.indexOf("controller=cart") !== -1 ||
            url.indexOf("/cart") !== -1 ||
            url.indexOf("action=update") !== -1
        ) {
            // Темите често обновяват DOM-а малко по-късно след AJAX отговора.
            scheduleUnipaymentCartRefresh();
            setTimeout(scheduleUnipaymentCartRefresh, 180);
        }
    });

    $(document).on("click", "#uni_back_unicredit_cart", function () {
        $("#uni-cart-popup-container").hide("slow");
    });

    $(document).on("click", "#uni_cart_buy_on_installment", function (e) {
        e.preventDefault();
        e.stopPropagation();
        const urlEl = document.getElementById("uni_prepare_cart_checkout_url");
        const tokenEl = document.getElementById("uni_csrf_token");
        if (!urlEl || !tokenEl) {
            return;
        }
        const $btn = $(this);
        if ($btn.data("uniBusy")) {
            return;
        }
        $btn.data("uniBusy", true);
        $.ajax({
            url: urlEl.value,
            type: "POST",
            dataType: "json",
            data: {
                token: tokenEl.value,
                installments: unipaymentGetPopupInstallmentsMonthsCart(),
            },
        })
            .done(function (data) {
                if (data && data.success && data.checkout_url) {
                    $("#uni-cart-popup-container").hide("slow");
                    window.location.href = data.checkout_url;
                    return;
                }
                const _str =
                    typeof window.uniPaymentShopStrings === "object" &&
                        window.uniPaymentShopStrings !== null
                        ? window.uniPaymentShopStrings
                        : {};
                const msg =
                    (data && data.message) ||
                    _str.checkoutRedirectFailed ||
                    "Could not redirect to checkout. Please try again.";
                window.alert(msg);
            })
            .fail(function () {
                const _str =
                    typeof window.uniPaymentShopStrings === "object" &&
                        window.uniPaymentShopStrings !== null
                        ? window.uniPaymentShopStrings
                        : {};
                window.alert(
                    _str.storeError ||
                    "An error occurred while contacting the store.",
                );
            })
            .always(function () {
                $btn.data("uniBusy", false);
            });
    });
});
