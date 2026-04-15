let uni_old_vnoski;
let unipaymentPrepareCheckoutBusy = false;
const UNIPAYMENT_ADD_TO_CART_LOCK_KEY = "__unipaymentAddToCartBusy";

/** @type {ReturnType<typeof setTimeout>|null} */
let unipaymentProductPriceRefreshTimer = null;

const UNIPAYMENT_EUR_BGN_RATE = 1.95583;

/**
 * След AJAX на продукта PrestaShop вмъква нов HTML на модула; старият попъп остава в body → дублирани id.
 * Оставяме единствения актуален #uni-product-popup-container и го преместваме в body.
 */
function unipaymentRelinkProductPopup() {
    const $all = $("#uni-product-popup-container");
    if ($all.length === 0) {
        return;
    }
    const $productHost = $(".product-container, .js-product-container").first();
    let $keep = $all.last();
    if ($productHost.length) {
        const $inside = $all.filter(function () {
            return $.contains($productHost[0], this);
        });
        if ($inside.length) {
            $keep = $inside.last();
        }
    }
    $all.not($keep).remove();
    $keep.prependTo("body");
}

/**
 * Единична цена от DOM (PS теми: .current-price-value / itemprop=price content).
 *
 * @returns {string}
 */
function unipaymentReadRawUnitPriceStringFromPage() {
    let priceContent = null;
    const elCurrent = document.querySelector(".current-price-value");
    if (elCurrent) {
        priceContent = elCurrent.getAttribute("content");
    }
    if (priceContent == null || priceContent === "") {
        const elItemprop = document.querySelector('[itemprop="price"]');
        if (elItemprop) {
            priceContent = elItemprop.getAttribute("content");
        }
    }
    if (typeof priceContent === "string" && priceContent !== "") {
        return priceContent;
    }

    // Fallback for themes (e.g. Hummingbird) where content attr is missing.
    const textSelectors = [
        ".current-price .price",
        ".product-prices .current-price .price",
        ".product-price",
        ".price[itemprop='price']",
    ];
    for (let i = 0; i < textSelectors.length; i += 1) {
        const el = document.querySelector(textSelectors[i]);
        if (!el) {
            continue;
        }
        const txt = (el.textContent || "").trim();
        if (txt !== "") {
            return txt;
        }
    }

    // Fallback to product JSON payload used by PrestaShop themes.
    const details = document.querySelector(
        "#product-details[data-product], .js-product-details[data-product]",
    );
    if (details && details.dataset && details.dataset.product) {
        try {
            const data = JSON.parse(details.dataset.product);
            if (data) {
                const candidates = [
                    data.price_amount,
                    data.price,
                    data.price_tax_exc,
                    data.price_tax_inc,
                    data.unit_price,
                ];
                for (let i = 0; i < candidates.length; i += 1) {
                    const val = candidates[i];
                    if (
                        val !== null &&
                        val !== undefined &&
                        String(val) !== ""
                    ) {
                        return String(val);
                    }
                }
            }
        } catch (e) {
            // ignore malformed JSON
        }
    }

    // Fallback to OpenGraph/meta amount.
    const metaPrice = document.querySelector(
        'meta[property="product:price:amount"], meta[itemprop="price"]',
    );
    if (metaPrice) {
        const metaContent = metaPrice.getAttribute("content");
        if (typeof metaContent === "string" && metaContent.trim() !== "") {
            return metaContent.trim();
        }
    }

    // Last fallback: existing hidden value in popup.
    const uniPriceEl = document.getElementById("uni_price");
    if (uniPriceEl && String(uniPriceEl.value).trim() !== "") {
        return String(uniPriceEl.value).trim();
    }

    return "";
}

function unipaymentGetProductPageQuantity() {
    const qtyEl = document.querySelector(
        "#quantity_wanted, input[name='qty'], input[name=qty]",
    );
    return qtyEl ? parseFloat(String(qtyEl.value).replace(",", ".")) || 1 : 1;
}

/**
 * Избран брой месеци от попъпа (срок на кредита) — за бисквитка в чекаут.
 *
 * @returns {number}
 */
function unipaymentGetPopupInstallmentsMonths() {
    const el = document.getElementById("uni_pogasitelni_vnoski_input");
    if (!el) {
        return 0;
    }
    const v = parseInt(String(el.value), 10);
    return Number.isNaN(v) ? 0 : v;
}

/**
 * Текуща комбинация за количката. Classic/PS8 няма hidden name="id_product_attribute" във формата —
 * стойността е в #product-details[data-product] (embedded_attributes.id_product_attribute).
 *
 * @returns {number}
 */
function unipaymentGetSelectedProductAttributeId() {
    const paInput = document.querySelector(
        '#add-to-cart-or-refresh input[name="id_product_attribute"], input[name="id_product_attribute"]',
    );
    if (paInput) {
        const fromInput = parseInt(String(paInput.value), 10);
        if (!Number.isNaN(fromInput)) {
            return fromInput;
        }
    }
    const details = document.querySelector(
        "#product-details[data-product], .js-product-details[data-product]",
    );
    if (details && details.dataset && details.dataset.product) {
        try {
            const data = JSON.parse(details.dataset.product);
            if (
                data &&
                Object.prototype.hasOwnProperty.call(
                    data,
                    "id_product_attribute",
                )
            ) {
                const fromJson = parseInt(
                    String(data.id_product_attribute),
                    10,
                );
                if (!Number.isNaN(fromJson)) {
                    return fromJson;
                }
            }
        } catch (e) {
            // ignore malformed JSON
        }
    }
    return 0;
}

/**
 * POST към prepareinstallmentcheckout: количка + бисквитки + пренасочване към order с избран UNI метод.
 *
 * @param {JQuery} $busyTarget елемент за uniBusy (предотвратява двойни заявки).
 * @param {{ hidePopupOnSuccess?: boolean }} [opts]
 */
function unipaymentRunPrepareInstallmentCheckout($busyTarget, opts) {
    opts = opts || {};
    const hidePopupOnSuccess = opts.hidePopupOnSuccess === true;
    const urlEl = document.getElementById(
        "uni_prepare_installmentcheckout_url",
    );
    const tokenEl = document.getElementById("uni_csrf_token");
    const productEl = document.getElementById("product_id");
    if (!urlEl || !tokenEl || !productEl) {
        return;
    }
    const idProduct = parseInt(productEl.value, 10);
    if (!idProduct) {
        return;
    }
    if (unipaymentPrepareCheckoutBusy) {
        return;
    }
    if ($busyTarget.data("uniBusy")) {
        return;
    }
    unipaymentPrepareCheckoutBusy = true;
    $busyTarget.data("uniBusy", true);
    $.ajax({
        url: urlEl.value,
        type: "POST",
        dataType: "json",
        data: {
            token: tokenEl.value,
            id_product: idProduct,
            id_product_attribute: unipaymentGetSelectedProductAttributeId(),
            qty: unipaymentGetProductPageQuantity(),
            installments: unipaymentGetPopupInstallmentsMonths(),
        },
    })
        .done(function (data) {
            if (data && data.success && data.checkout_url) {
                if (hidePopupOnSuccess) {
                    $("#uni-product-popup-container").hide("slow");
                }
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
                _str.cartAddFailed ||
                "Could not add to cart. Please try again.";
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
            unipaymentPrepareCheckoutBusy = false;
            $busyTarget.data("uniBusy", false);
        });
}

function unipaymentAddToCartFromPopup($busyTarget) {
    const urlEl = document.getElementById(
        "uni_prepare_installmentcheckout_url",
    );
    const tokenEl = document.getElementById("uni_csrf_token");
    const productEl = document.getElementById("product_id");
    if (!urlEl || !tokenEl || !productEl) {
        return;
    }
    const idProduct = parseInt(productEl.value, 10);
    if (!idProduct) {
        return;
    }
    if (unipaymentIsAddToCartLocked()) {
        return;
    }
    if ($busyTarget && $busyTarget.data && $busyTarget.data("uniBusy")) {
        return;
    }
    unipaymentLockAddToCart();
    if ($busyTarget && $busyTarget.data) {
        $busyTarget.data("uniBusy", true);
    }
    $.ajax({
        url: urlEl.value,
        type: "POST",
        dataType: "json",
        data: {
            token: tokenEl.value,
            id_product: idProduct,
            id_product_attribute: unipaymentGetSelectedProductAttributeId(),
            qty: unipaymentGetProductPageQuantity(),
            add_to_cart_only: 1,
        },
    })
        .done(function (data) {
            if (data && data.success) {
                if (data.cart_url) {
                    window.location.href = data.cart_url;
                    return;
                }
                if (
                    typeof prestashop !== "undefined" &&
                    typeof prestashop.emit === "function"
                ) {
                    prestashop.emit("updateCart", {
                        reason: {
                            idProduct: idProduct,
                            idProductAttribute:
                                unipaymentGetSelectedProductAttributeId(),
                            linkAction: "add-to-cart",
                        },
                        resp: data,
                    });
                }
                return;
            }
            const _str =
                typeof window.uniPaymentShopStrings === "object" &&
                window.uniPaymentShopStrings !== null
                    ? window.uniPaymentShopStrings
                    : {};
            window.alert(
                (data && data.message) ||
                    _str.cartAddFailed ||
                    "Could not add to cart. Please try again.",
            );
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
            if ($busyTarget && $busyTarget.data) {
                $busyTarget.data("uniBusy", false);
            }
            window.setTimeout(unipaymentReleaseAddToCartLock, 300);
        });
}

function unipaymentReleaseAddToCartLock() {
    window[UNIPAYMENT_ADD_TO_CART_LOCK_KEY] = false;
}

function unipaymentIsAddToCartLocked() {
    return window[UNIPAYMENT_ADD_TO_CART_LOCK_KEY] === true;
}

function unipaymentLockAddToCart() {
    window[UNIPAYMENT_ADD_TO_CART_LOCK_KEY] = true;
}

/**
 * @param {number} total
 * @param {number} uni_eur
 * @param {string} uni_currency_code
 * @returns {number}
 */
function unipaymentApplyEurConversionToTotal(
    total,
    uni_eur,
    uni_currency_code,
) {
    let uni_priceall = total;
    switch (uni_eur) {
        case 0:
            break;
        case 1:
            if (uni_currency_code == "EUR") {
                uni_priceall = uni_priceall * UNIPAYMENT_EUR_BGN_RATE;
            }
            break;
        case 2:
        case 3:
            if (uni_currency_code == "BGN") {
                uni_priceall = uni_priceall / UNIPAYMENT_EUR_BGN_RATE;
            }
            break;
    }
    return uni_priceall;
}

/**
 * Обща сума за калкулатора (бройка × цена от страницата + конверсия според uni_eur).
 *
 * @returns {number}
 */
function unipaymentComputeProductTotalPriceForCalculator() {
    const raw = unipaymentReadRawUnitPriceStringFromPage();
    if (raw === "") {
        return NaN;
    }
    const normalized = raw.replace(/\u00A0/g, " ").trim();
    let uni_price1 = normalized;

    // If both separators are present, treat the right-most one as decimal separator.
    if (normalized.indexOf(",") !== -1 && normalized.indexOf(".") !== -1) {
        if (normalized.lastIndexOf(",") > normalized.lastIndexOf(".")) {
            uni_price1 = normalized.replace(/\./g, "").replace(/,/g, ".");
        } else {
            uni_price1 = normalized.replace(/,/g, "");
        }
    } else if (normalized.indexOf(",") !== -1) {
        // Only comma present -> decimal comma.
        uni_price1 = normalized.replace(/,/g, ".");
    }
    uni_price1 = uni_price1.replace(/[^\d.-]/g, "");
    const uni_quantity = unipaymentGetProductPageQuantity();
    let uni_priceall = parseFloat(uni_price1) * uni_quantity;
    if (Number.isNaN(uni_priceall)) {
        return NaN;
    }
    const uni_eurEl = document.getElementById("uni_eur");
    const uni_ccEl = document.getElementById("uni_currency_code");
    if (!uni_eurEl || !uni_ccEl) {
        return NaN;
    }
    const uni_eur = parseInt(uni_eurEl.value, 10);
    const uni_currency_code = uni_ccEl.value;
    return unipaymentApplyEurConversionToTotal(
        uni_priceall,
        uni_eur,
        uni_currency_code,
    );
}

/**
 * Обновява скритото uni_price и показването на цената в попъпа.
 *
 * @param {number} uni_priceall
 * @returns {boolean}
 */
function unipaymentApplyTotalPriceToPopupDom(uni_priceall) {
    const uni_eur = parseInt(document.getElementById("uni_eur").value, 10);
    const uni_priceHidden = document.getElementById("uni_price");
    const uni_price_int = document.getElementById("uni_price_int");
    const uni_price_dec = document.getElementById("uni_price_dec");
    if (!uni_priceHidden || !uni_price_int || !uni_price_dec) {
        return false;
    }
    uni_priceHidden.value = String(uni_priceall);

    uni_price_int.innerHTML = String(Math.floor(uni_priceall));
    const decimalPartTwoDigitsStr = String(
        Math.ceil((uni_priceall - Math.trunc(uni_priceall)) * 100),
    ).padStart(2, "0");
    uni_price_dec.innerHTML = decimalPartTwoDigitsStr;

    const uni_price_second_int = document.getElementById(
        "uni_price_second_int",
    );
    const uni_price_second_dec = document.getElementById(
        "uni_price_second_dec",
    );
    if (uni_price_second_int !== null && uni_price_second_dec !== null) {
        if (uni_eur == 1) {
            const uni_price_second_arr = (
                uni_priceall / UNIPAYMENT_EUR_BGN_RATE
            )
                .toFixed(2)
                .split(".");
            uni_price_second_int.textContent = uni_price_second_arr[0];
            uni_price_second_dec.textContent = uni_price_second_arr[1];
        }
        if (uni_eur == 2) {
            const uni_price_second_arr = (
                uni_priceall * UNIPAYMENT_EUR_BGN_RATE
            )
                .toFixed(2)
                .split(".");
            uni_price_second_int.textContent = uni_price_second_arr[0];
            uni_price_second_dec.textContent = uni_price_second_arr[1];
        }
    }
    return true;
}

/**
 * Текст на бутона „N ВНОСКИ“ и месечна вноска (синхрон с попъпа след AJAX).
 *
 * @param {number} uniVnoski
 * @param {number} uniMesecna
 */
function unipaymentUpdateProductButtonLabels(uniVnoski, uniMesecna) {
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

function uniChangeContainer() {
    var uni_label_container = document.getElementsByClassName(
        "uni-label-container",
    )[0];
    if (!uni_label_container) {
        return;
    }
    if (uni_label_container.style.visibility == "visible") {
        uni_label_container.style.visibility = "hidden";
        uni_label_container.style.opacity = 0;
        uni_label_container.style.transition =
            "visibility 0s, opacity 0.5s ease";
    } else {
        uni_label_container.style.visibility = "visible";
        uni_label_container.style.opacity = 1;
    }
}
$(document).ready(function (e) {
    const uni_price = document.getElementById("uni_price");
    if (uni_price == null) {
        return;
    }

    $("#uni-product-popup-container").prependTo("body");
    unipaymentRelinkProductPopup();

    function uni_pogasitelni_vnoski_input_change() {
        const uni_get_product_link = document.getElementById(
            "uni_get_product_link",
        ).value;
        const uni_vnoski = parseFloat(
            document.getElementById("uni_pogasitelni_vnoski_input").value,
        );
        const uni_price = parseFloat(
            document.getElementById("uni_price").value,
        );
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
                                document.getElementById("uni_param_glp_3")
                                    .value,
                            );
                            break;
                        case 4:
                            uni_mesecna = parseFloat(json.uni_mesecna_4);
                            uni_gpr = parseFloat(json.uni_gpr_4);
                            uni_glp = parseFloat(
                                document.getElementById("uni_param_glp_4")
                                    .value,
                            );
                            break;
                        case 5:
                            uni_mesecna = parseFloat(json.uni_mesecna_5);
                            uni_gpr = parseFloat(json.uni_gpr_5);
                            uni_glp = parseFloat(
                                document.getElementById("uni_param_glp_5")
                                    .value,
                            );
                            break;
                        case 6:
                            uni_mesecna = parseFloat(json.uni_mesecna_6);
                            uni_gpr = parseFloat(json.uni_gpr_6);
                            uni_glp = parseFloat(
                                document.getElementById("uni_param_glp_6")
                                    .value,
                            );
                            break;
                        case 9:
                            uni_mesecna = parseFloat(json.uni_mesecna_9);
                            uni_gpr = parseFloat(json.uni_gpr_9);
                            uni_glp = parseFloat(
                                document.getElementById("uni_param_glp_9")
                                    .value,
                            );
                            break;
                        case 10:
                            uni_mesecna = parseFloat(json.uni_mesecna_10);
                            uni_gpr = parseFloat(json.uni_gpr_10);
                            uni_glp = parseFloat(
                                document.getElementById("uni_param_glp_10")
                                    .value,
                            );
                            break;
                        case 12:
                            uni_mesecna = parseFloat(json.uni_mesecna_12);
                            uni_gpr = parseFloat(json.uni_gpr_12);
                            uni_glp = parseFloat(
                                document.getElementById("uni_param_glp_12")
                                    .value,
                            );
                            break;
                        case 18:
                            uni_mesecna = parseFloat(json.uni_mesecna_18);
                            uni_gpr = parseFloat(json.uni_gpr_18);
                            uni_glp = parseFloat(
                                document.getElementById("uni_param_glp_18")
                                    .value,
                            );
                            break;
                        case 24:
                            uni_mesecna = parseFloat(json.uni_mesecna_24);
                            uni_gpr = parseFloat(json.uni_gpr_24);
                            uni_glp = parseFloat(
                                document.getElementById("uni_param_glp_24")
                                    .value,
                            );
                            break;
                        case 30:
                            uni_mesecna = parseFloat(json.uni_mesecna_30);
                            uni_gpr = parseFloat(json.uni_gpr_30);
                            uni_glp = parseFloat(
                                document.getElementById("uni_param_glp_30")
                                    .value,
                            );
                            break;
                        case 36:
                            uni_mesecna = parseFloat(json.uni_mesecna_36);
                            uni_gpr = parseFloat(json.uni_gpr_36);
                            uni_glp = parseFloat(
                                document.getElementById("uni_param_glp_36")
                                    .value,
                            );
                            break;
                        default:
                            uni_mesecna = parseFloat(json.uni_mesecna_3);
                            uni_gpr = parseFloat(json.uni_gpr_3);
                            uni_glp = parseFloat(
                                document.getElementById("uni_param_glp_3")
                                    .value,
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
                    unipaymentUpdateProductButtonLabels(
                        uni_vnoski,
                        uni_mesecna,
                    );
                }
            },
        });
    }

    function unipaymentRefreshProductPriceAndInstallment(openPopup) {
        unipaymentRelinkProductPopup();
        const uni_cartEl = document.getElementById("uni_cart");
        if (!uni_cartEl) {
            return;
        }
        const uni_cart = parseInt(uni_cartEl.value, 10);
        if (uni_cart == 1) {
            const total = unipaymentComputeProductTotalPriceForCalculator();
            if (
                !Number.isNaN(total) &&
                unipaymentApplyTotalPriceToPopupDom(total)
            ) {
                uni_pogasitelni_vnoski_input_change();
            }
            return;
        }
        const uni_priceall = unipaymentComputeProductTotalPriceForCalculator();
        if (Number.isNaN(uni_priceall)) {
            return;
        }
        if (!unipaymentApplyTotalPriceToPopupDom(uni_priceall)) {
            return;
        }
        uni_pogasitelni_vnoski_input_change();

        if (openPopup) {
            $("#uni-product-popup-container").first().show("slow");
        }
    }

    function scheduleUnipaymentProductPriceRefresh() {
        if (unipaymentProductPriceRefreshTimer !== null) {
            clearTimeout(unipaymentProductPriceRefreshTimer);
        }
        unipaymentProductPriceRefreshTimer = setTimeout(function () {
            unipaymentProductPriceRefreshTimer = null;
            if (!document.getElementById("uni_price")) {
                return;
            }
            unipaymentRefreshProductPriceAndInstallment(false);
        }, 100);
    }

    $(document).on("change", "#uni_pogasitelni_vnoski_input", function () {
        uni_pogasitelni_vnoski_input_change();
    });

    $(document).on("focus", "#uni_pogasitelni_vnoski_input", function () {
        uni_old_vnoski = $(this).val();
    });

    $(document).on("click", "#btn_uni", function () {
        unipaymentRelinkProductPopup();

        const uni_cartEl = document.getElementById("uni_cart");
        if (!uni_cartEl) {
            return;
        }
        const uni_cart = parseInt(uni_cartEl.value, 10);
        if (uni_cart == 1) {
            unipaymentRunPrepareInstallmentCheckout($(this), {
                hidePopupOnSuccess: false,
            });
        } else {
            unipaymentRefreshProductPriceAndInstallment(true);
        }
    });

    $(document).on(
        "change input",
        "#quantity_wanted, input[name='qty'], input[name=qty]",
        function () {
            scheduleUnipaymentProductPriceRefresh();
        },
    );

    if (
        typeof prestashop !== "undefined" &&
        typeof prestashop.on === "function"
    ) {
        prestashop.on("updatedProduct", function () {
            unipaymentRelinkProductPopup();
            scheduleUnipaymentProductPriceRefresh();
        });
        prestashop.on("updatedProductCombination", function () {
            unipaymentRelinkProductPopup();
            scheduleUnipaymentProductPriceRefresh();
        });
        prestashop.on("updateCart", function () {
            unipaymentReleaseAddToCartLock();
        });
    }

    /**
     * Пълно зареждане / F5: темата възстановява избрана комбинация от URL или сесия,
     * но не вдига updatedProduct* — бутонът иначе остава със SSR вноска за дефолтната комбинация.
     */
    scheduleUnipaymentProductPriceRefresh();
    setTimeout(function () {
        if (document.getElementById("uni_price")) {
            scheduleUnipaymentProductPriceRefresh();
        }
    }, 350);

    $(document).on("click", "#uni_buy_on_installment", function (e) {
        e.preventDefault();
        e.stopPropagation();
        unipaymentRunPrepareInstallmentCheckout($(this), {
            hidePopupOnSuccess: true,
        });
    });

    $(document).on("click", "#uni_back_unicredit", function () {
        $("#uni-product-popup-container").hide("slow");
    });

    $(document)
        .off("click.unipaymentProductAddToCart", "#uni_buy_unicredit")
        .on(
            "click.unipaymentProductAddToCart",
            "#uni_buy_unicredit",
            function (e) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                if (unipaymentIsAddToCartLocked()) {
                    return;
                }
                $("#uni-product-popup-container").hide("slow");
                unipaymentAddToCartFromPopup($(this));
            },
        );
});
