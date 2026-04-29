/** UNI блок — страница количка (сума = общо продукти). */
let uni_old_vnoski_cart;
const UNIPAYMENT_EUR_BGN_RATE = 1.95583;
/** @type {MutationObserver|null} */
let unipaymentCartRefreshObserver = null;
/** @type {ReturnType<typeof setTimeout>|null} */
let unipaymentCartRefreshTimer = null;
/** @type {Element|null} */
let unipaymentObservedCartRoot = null;
let unipaymentCartRecalcBusy = false;
let unipaymentCartNetworkHooksReady = false;
/** Само при първо зареждане с цена под мин. (latent) — false, за да не презаписваме #uni_price с грешен тотал от DOM преди реална промяна в количката. */
let unipaymentDomCartSyncAllowed = true;

function unipaymentEnableDomCartSyncFromUserOrNetwork() {
    unipaymentDomCartSyncAllowed = true;
}

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
 * Сума на продукти без доставка — съвпада с Cart::ONLY_PRODUCTS (като PS8 скрипта).
 * @returns {number|null}
 */
function unipaymentResolveProductsTotalFromPrestashopCart() {
    if (typeof window.prestashop !== "object" || window.prestashop === null) {
        return null;
    }
    const cart = window.prestashop.cart;
    if (typeof cart !== "object" || cart === null) {
        return null;
    }
    const productsSub = cart.subtotals && cart.subtotals.products;
    if (typeof productsSub === "object" && productsSub !== null) {
        if (typeof productsSub.amount !== "undefined") {
            const a = parseFloat(String(productsSub.amount).replace(/,/g, "."));
            if (!Number.isNaN(a)) {
                return a;
            }
        }
        if (productsSub.value != null) {
            const fromText = unipaymentExtractNumericPriceStringFromDisplayText(
                String(productsSub.value),
            );
            if (fromText !== "") {
                const norm =
                    fromText.indexOf(".") !== -1
                        ? fromText
                        : fromText.replace(/,/g, ".");
                const p = parseFloat(norm);
                if (!Number.isNaN(p)) {
                    return p;
                }
            }
        }
    }
    if (Array.isArray(cart.products) && cart.products.length > 0) {
        let sum = 0;
        let n = 0;
        for (let i = 0; i < cart.products.length; i += 1) {
            const p = cart.products[i];
            if (typeof p !== "object" || p === null) {
                continue;
            }
            let line = null;
            if (typeof p.total_wt !== "undefined") {
                line = parseFloat(String(p.total_wt).replace(/,/g, "."));
            } else if (typeof p.total !== "undefined") {
                line = parseFloat(String(p.total).replace(/,/g, "."));
            } else if (typeof p.price_wt !== "undefined") {
                const pr = parseFloat(String(p.price_wt).replace(/,/g, "."));
                const q = parseInt(String(p.quantity), 10);
                if (!Number.isNaN(pr) && !Number.isNaN(q) && q > 0) {
                    line = pr * q;
                }
            }
            if (line != null && !Number.isNaN(line)) {
                sum += line;
                n += 1;
            }
        }
        if (n > 0) {
            return sum;
        }
    }
    return null;
}

function unipaymentExtractNumericPriceStringFromDisplayText(text) {
    if (text == null || typeof text !== "string") {
        return "";
    }
    let s = text
        .replace(/\u00a0/gi, " ")
        .replace(/\s+/g, "")
        .replace(/[^\d.,-]/g, "");
    if (!s) {
        return "";
    }
    const hasComma = s.indexOf(",") !== -1;
    const hasDot = s.indexOf(".") !== -1;
    if (hasComma && hasDot) {
        const lastC = s.lastIndexOf(",");
        const lastD = s.lastIndexOf(".");
        if (lastC > lastD) {
            return s.replace(/\./g, "").replace(",", ".");
        }
        return s.replace(/,/g, "");
    }
    if (hasComma && !hasDot) {
        const parts = s.split(",");
        if (parts.length === 2 && parts[1].length <= 2) {
            return parts[0].replace(/\./g, "") + "." + parts[1];
        }
        return s.replace(/,/g, "");
    }
    if (hasDot && !hasComma) {
        const parts = s.split(".");
        if (parts.length === 2 && parts[1].length <= 2) {
            return parts[0].replace(/,/g, "") + "." + parts[1];
        }
        if (parts.length > 2) {
            return s.replace(/\./g, "");
        }
    }
    return s;
}

function unipaymentReadCartLineProductsSumRawString() {
    const lineTotalNodes = document.querySelectorAll(
        ".product-line__content-right .product-line__price, .cart__item .product-line__price",
    );
    if (lineTotalNodes && lineTotalNodes.length > 0) {
        let sum = 0;
        let used = 0;
        for (let i = 0; i < lineTotalNodes.length; i += 1) {
            const rawLine = unipaymentExtractNumericPriceStringFromDisplayText(
                lineTotalNodes[i].textContent || "",
            );
            if (rawLine === "") {
                continue;
            }
            const normalizedLine =
                rawLine.indexOf(".") !== -1
                    ? rawLine
                    : rawLine.replace(/,/g, ".");
            const parsedLine = parseFloat(normalizedLine);
            if (Number.isNaN(parsedLine)) {
                continue;
            }
            sum += parsedLine;
            used += 1;
        }
        if (used > 0) {
            return sum.toFixed(2);
        }
    }
    return "";
}

function unipaymentReadCartTotalRawStringFromPage() {
    const fromLines = unipaymentReadCartLineProductsSumRawString();
    if (fromLines !== "") {
        return fromLines;
    }
    const selectors = [
        ".cart-summary__line.cart-total .cart-summary__value",
        ".cart-summary__totals .cart-summary__line.cart-total .cart-summary__value",
        ".cart-summary__line.cart-total .cart-summary__line__value .cart-summary__value",
        ".cart-summary__line.cart-total .cart-summary__line__value",
        ".cart-summary__line.cart-total .value",
        ".cart-summary-line.cart-total .value",
        ".cart-summary-totals .value",
        ".cart__totals .cart-summary-line .value",
        ".cart-total .value",
        ".product-line__discount .product-line__price strong",
        ".product-line__discount .product-line__price",
        ".cart__total .price",
        ".cart__total .value",
    ];
    for (let i = 0; i < selectors.length; i += 1) {
        const el = document.querySelector(selectors[i]);
        if (!el) {
            continue;
        }
        const fromContent = el.getAttribute("content");
        if (fromContent != null && fromContent !== "") {
            return fromContent;
        }
        const extracted = unipaymentExtractNumericPriceStringFromDisplayText(
            el.textContent || "",
        );
        if (extracted !== "") {
            return extracted;
        }
    }
    return "";
}

function unipaymentApplyCartButtonVisibilityFromTotals() {
    const priceEl = document.getElementById("uni_price");
    const minEl = document.getElementById("uni_param_minstojnost");
    const maxEl = document.getElementById("uni_param_maxstojnost");
    const btn = document.getElementById("btn_uni");
    if (!priceEl || !minEl || !maxEl || !btn) {
        return;
    }
    if (!btn.classList.contains("uni_button")) {
        return;
    }
    const p = parseFloat(String(priceEl.value).replace(/,/g, "."));
    const minV = parseFloat(String(minEl.value).replace(/,/g, "."));
    const maxV = parseFloat(String(maxEl.value).replace(/,/g, "."));
    if (Number.isNaN(p) || Number.isNaN(minV) || Number.isNaN(maxV)) {
        return;
    }
    /* .uni_button в unipayment_cart.css е display:flex !important — inline display:none без !important не важи. */
    if (p >= minV && p <= maxV) {
        btn.style.removeProperty("display");
    } else {
        btn.style.setProperty("display", "none", "important");
    }
}

/**
 * @param {number} parsed
 */
function unipaymentApplyNumericTotalToCartUi(parsed) {
    if (Number.isNaN(parsed)) {
        return;
    }
    const uniPriceEl = document.getElementById("uni_price");
    const uniEurEl = document.getElementById("uni_eur");
    if (!uniPriceEl || !uniEurEl) {
        return;
    }
    uniPriceEl.value = String(parsed);

    const uniPriceInt = document.getElementById("uni_price_int");
    const uniPriceDec = document.getElementById("uni_price_dec");
    if (uniPriceInt && uniPriceDec) {
        const parts = parsed.toFixed(2).split(".");
        uniPriceInt.textContent = parts[0];
        uniPriceDec.textContent = parts[1];
    }

    const uniPriceSecondInt = document.getElementById("uni_price_second_int");
    const uniPriceSecondDec = document.getElementById("uni_price_second_dec");
    if (uniPriceSecondInt && uniPriceSecondDec) {
        const uni_eur = parseInt(uniEurEl.value, 10);
        let second = 0;
        if (uni_eur === 1) {
            second = parsed / UNIPAYMENT_EUR_BGN_RATE;
        } else if (uni_eur === 2) {
            second = parsed * UNIPAYMENT_EUR_BGN_RATE;
        }
        if (second > 0) {
            const secondParts = second.toFixed(2).split(".");
            uniPriceSecondInt.textContent = secondParts[0];
            uniPriceSecondDec.textContent = secondParts[1];
        }
    }
}

function unipaymentSyncCartTotalFromDom() {
    const uniPriceEl = document.getElementById("uni_price");
    const uniEurEl = document.getElementById("uni_eur");
    if (!uniPriceEl || !uniEurEl) {
        return;
    }

    const fromPs = unipaymentResolveProductsTotalFromPrestashopCart();
    if (fromPs !== null) {
        unipaymentApplyNumericTotalToCartUi(fromPs);
        return;
    }

    const latentEl = document.getElementById("uni_cart_latent");
    if (
        latentEl &&
        String(latentEl.value) === "1" &&
        !unipaymentDomCartSyncAllowed
    ) {
        return;
    }
    const raw = unipaymentReadCartTotalRawStringFromPage();
    if (raw === "") {
        return;
    }
    const normalized = raw.indexOf(".") !== -1 ? raw : raw.replace(/,/g, ".");
    const parsed = parseFloat(normalized);
    if (Number.isNaN(parsed)) {
        return;
    }
    unipaymentApplyNumericTotalToCartUi(parsed);
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
                unipaymentApplyCartButtonVisibilityFromTotals();
            }
        },
    });
}

function unipaymentScheduleCartRecalculation() {
    if (unipaymentCartRecalcBusy) {
        return;
    }
    if (unipaymentCartRefreshTimer !== null) {
        clearTimeout(unipaymentCartRefreshTimer);
    }
    unipaymentCartRefreshTimer = setTimeout(function () {
        unipaymentCartRefreshTimer = null;
        unipaymentCartRecalcBusy = true;
        // При AJAX някои теми подменят целия .js-cart; пре-закачаме observer-а.
        unipaymentSetupAjaxCartObserver();
        unipaymentSyncCartTotalFromDom();
        unipaymentApplyCartButtonVisibilityFromTotals();
        unipaymentRelinkCartPopup();
        uniCartPogasitelniInputChange();
        setTimeout(function () {
            unipaymentCartRecalcBusy = false;
        }, 250);
    }, 120);
}

function unipaymentScheduleCartRecalculationBurst() {
    unipaymentScheduleCartRecalculation();
    setTimeout(unipaymentScheduleCartRecalculation, 300);
    setTimeout(unipaymentScheduleCartRecalculation, 700);
}

function unipaymentSetupAjaxCartObserver() {
    if (typeof MutationObserver === "undefined") {
        return;
    }
    const cartRoot = document.querySelector(".cart-overview.js-cart, .js-cart");
    if (!cartRoot) {
        return;
    }
    if (
        unipaymentCartRefreshObserver !== null &&
        unipaymentObservedCartRoot === cartRoot
    ) {
        return;
    }
    if (unipaymentCartRefreshObserver !== null) {
        unipaymentCartRefreshObserver.disconnect();
    }
    unipaymentCartRefreshObserver = new MutationObserver(function (mutations) {
        for (let i = 0; i < mutations.length; i += 1) {
            const mutation = mutations[i];
            if (
                mutation.type === "childList" &&
                (mutation.addedNodes.length > 0 ||
                    mutation.removedNodes.length > 0)
            ) {
                unipaymentScheduleCartRecalculation();
                return;
            }
            if (
                mutation.type === "characterData" ||
                mutation.type === "attributes"
            ) {
                unipaymentScheduleCartRecalculation();
                return;
            }
        }
    });
    unipaymentObservedCartRoot = cartRoot;
    unipaymentCartRefreshObserver.observe(cartRoot, {
        childList: true,
        subtree: true,
        characterData: true,
        attributes: true,
        attributeFilter: ["value", "data-refresh-url"],
    });
}

function unipaymentIsCartAjaxUrl(url) {
    if (typeof url !== "string" || url === "") {
        return false;
    }
    return (
        url.indexOf("action=refresh") !== -1 ||
        url.indexOf("update=1") !== -1 ||
        url.indexOf("delete=1") !== -1
    );
}

/** Само промяна на съдържание на количката, не първоначален/фонов refresh (в комб. с карт-latent избягваме погрешен #uni_price при зареждане). */
function unipaymentIsCartMutatingRequest(url) {
    if (typeof url !== "string" || url === "") {
        return false;
    }
    return url.indexOf("update=1") !== -1 || url.indexOf("delete=1") !== -1;
}

function unipaymentSetupCartNetworkHooks() {
    if (unipaymentCartNetworkHooksReady) {
        return;
    }
    unipaymentCartNetworkHooksReady = true;

    if (typeof window.fetch === "function") {
        const originalFetch = window.fetch;
        window.fetch = function (input, init) {
            let reqUrl = "";
            if (typeof input === "string") {
                reqUrl = input;
            } else if (input && typeof input.url === "string") {
                reqUrl = input.url;
            }
            return originalFetch.call(this, input, init).then(function (resp) {
                if (unipaymentIsCartAjaxUrl(reqUrl)) {
                    const latentIn = document.getElementById("uni_cart_latent");
                    const isLatent = latentIn && String(latentIn.value) === "1";
                    if (!isLatent || unipaymentIsCartMutatingRequest(reqUrl)) {
                        unipaymentEnableDomCartSyncFromUserOrNetwork();
                    }
                    unipaymentScheduleCartRecalculationBurst();
                }
                return resp;
            });
        };
    }

    if (
        typeof window.XMLHttpRequest === "function" &&
        window.XMLHttpRequest.prototype
    ) {
        const xhrProto = window.XMLHttpRequest.prototype;
        const originalOpen = xhrProto.open;
        const originalSend = xhrProto.send;
        xhrProto.open = function (method, url) {
            this.__uniCartUrl = typeof url === "string" ? url : "";
            return originalOpen.apply(this, arguments);
        };
        xhrProto.send = function () {
            if (
                this.__uniCartHooked !== true &&
                unipaymentIsCartAjaxUrl(this.__uniCartUrl || "")
            ) {
                this.__uniCartHooked = true;
                this.addEventListener("loadend", function () {
                    const latentIn = document.getElementById("uni_cart_latent");
                    const isLatent = latentIn && String(latentIn.value) === "1";
                    const u = this.__uniCartUrl || "";
                    if (!isLatent || unipaymentIsCartMutatingRequest(u)) {
                        unipaymentEnableDomCartSyncFromUserOrNetwork();
                    }
                    unipaymentScheduleCartRecalculationBurst();
                });
            }
            return originalSend.apply(this, arguments);
        };
    }
}

$(document).ready(function () {
    if (!document.getElementById("uni_prepare_cart_checkout_url")) {
        return;
    }
    const uniPriceEl = document.getElementById("uni_price");
    if (!uniPriceEl) {
        return;
    }

    const uniLatentInput = document.getElementById("uni_cart_latent");
    if (uniLatentInput && String(uniLatentInput.value) === "1") {
        unipaymentDomCartSyncAllowed = false;
    }
    unipaymentSyncCartTotalFromDom();
    unipaymentApplyCartButtonVisibilityFromTotals();

    $("#uni-cart-popup-container").prependTo("body");
    unipaymentRelinkCartPopup();
    uniCartPogasitelniInputChange();
    unipaymentSetupAjaxCartObserver();
    unipaymentSetupCartNetworkHooks();
    if (
        typeof window.prestashop !== "undefined" &&
        typeof window.prestashop.on === "function"
    ) {
        window.prestashop.on("updatedCart", function () {
            unipaymentEnableDomCartSyncFromUserOrNetwork();
            unipaymentScheduleCartRecalculation();
        });
        window.prestashop.on("updateCart", function () {
            unipaymentEnableDomCartSyncFromUserOrNetwork();
            unipaymentScheduleCartRecalculationBurst();
        });
    }
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
    $(document).on(
        "click",
        ".remove-from-cart, .js-remove-from-cart, [data-link-action='delete-from-cart']",
        function () {
            unipaymentEnableDomCartSyncFromUserOrNetwork();
            unipaymentScheduleCartRecalculationBurst();
        },
    );
    $(document).on(
        "click",
        ".js-increment-button, .js-decrement-button",
        function () {
            unipaymentEnableDomCartSyncFromUserOrNetwork();
            unipaymentScheduleCartRecalculationBurst();
        },
    );
    $(document).on(
        "change input",
        ".js-cart-line-product-quantity",
        function () {
            unipaymentEnableDomCartSyncFromUserOrNetwork();
            unipaymentScheduleCartRecalculationBurst();
        },
    );

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
