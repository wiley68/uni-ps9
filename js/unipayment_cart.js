/** UNI блок — страница количка (сума = общо продукти). */
let uni_old_vnoski_cart;
const UNIPAYMENT_EUR_BGN_RATE = 1.95583;

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
    uniCartPogasitelniInputChange();

    $(document).on("change", "#uni_pogasitelni_vnoski_input", function () {
        uniCartPogasitelniInputChange();
    });

    $(document).on("focus", "#uni_pogasitelni_vnoski_input", function () {
        uni_old_vnoski_cart = $(this).val();
    });

    $(document).on("click", "#btn_uni", function () {
        unipaymentRelinkCartPopup();
        $("#uni-cart-popup-container").first().show("slow");
        uniCartPogasitelniInputChange();
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
