(function () {
    "use strict";

    var CALCULATE_DELAY = 900;
    var submitting = false;

    function parseConfig(root) {
        try {
            return JSON.parse(root.getAttribute("data-config") || "{}");
        } catch (error) {
            return null;
        }
    }

    function t(root, attr, fallback) {
        return root.getAttribute(attr) || fallback;
    }

    function validEgn(value) {
        var egn = String(value || "").replace(/\D/g, "");
        if (!/^\d{10}$/.test(egn)) return false;
        var year = parseInt(egn.slice(0, 4), 10);
        var month = parseInt(egn.slice(4, 6), 10);
        var day = parseInt(egn.slice(6, 8), 10);
        var date = new Date(year, month - 1, day);
        return (
            date.getFullYear() === year &&
            date.getMonth() === month - 1 &&
            date.getDate() === day
        );
    }

    function validPhone(value) {
        var phone = String(value || "").trim();
        return phone !== "" && /^[-0-9+() ]+$/.test(phone) && /\d/.test(phone);
    }

    function setDisplay(root, name, display) {
        var primary = root.querySelector(
            '[data-unipayment-display="' + name + '-primary"]',
        );
        var secondary = root.querySelector(
            '[data-unipayment-display="' + name + '-secondary"]',
        );
        if (primary) primary.textContent = (display && display.primary) || "";
        if (secondary)
            secondary.textContent = (display && display.secondary) || "";
    }

    function setup(root) {
        if (root.dataset.unipaymentReady === "1") return;
        var config = parseConfig(root);
        if (!config || !config.schemes) return;
        root.dataset.unipaymentReady = "1";

        var form = root.closest("form") || root.querySelector("form");
        var select = root.querySelector("[data-unipayment-scheme]");
        var schemeHidden = root.querySelector(
            "[data-unipayment-scheme-hidden]",
        );
        var kop = root.querySelector("[data-unipayment-kop]");
        var first = root.querySelector("[data-unipayment-first]");
        var firstRow = root.querySelector("[data-unipayment-first-row]");
        var errorBox = root.querySelector("[data-unipayment-checkout-error]");
        var egn = root.querySelector("[data-unipayment-egn]");
        var phone2 = root.querySelector("[data-unipayment-phone2]");
        var calculateTimer = null;
        var calculateRequest = null;
        var calculateSequence = 0;
        var lastCalculation = null;

        function showError(message) {
            if (!errorBox) return;
            if (!message) {
                errorBox.textContent = "";
                errorBox.hidden = true;
                return;
            }
            errorBox.textContent = message;
            errorBox.hidden = false;
        }

        function snapshotInput() {
            return root.querySelector('input[name="unipayment_cart_snapshot"]');
        }

        function selectedScheme() {
            var key = select ? select.value : "";
            return (
                (config.schemes || []).filter(function (scheme) {
                    return scheme.key === key;
                })[0] || null
            );
        }

        function syncHidden() {
            var scheme = selectedScheme();
            if (schemeHidden)
                schemeHidden.value = scheme ? scheme.key : select.value;
            if (kop) kop.value = scheme ? scheme.kop_code : "";
        }

        function applyLocalScheme() {
            var scheme = selectedScheme();
            if (!scheme) return;
            syncHidden();
            if (first) {
                first.readOnly = !!scheme.first_installment_locked;
                if (scheme.first_installment_locked) {
                    first.value = String(Math.trunc(scheme.first_installment));
                } else if (first.value === "") {
                    first.value = "0";
                }
            }
            if (firstRow) {
                firstRow.classList.toggle(
                    "unipayment-checkout__row--hidden",
                    !config.show_first_installment &&
                        !scheme.first_installment_locked &&
                        !scheme.show_first_installment,
                );
            }
            setDisplay(root, "price", config.cart_total_display);
            setDisplay(root, "financed_amount", scheme.financed_amount_display);
            setDisplay(
                root,
                "monthly_installment",
                scheme.monthly_installment_display,
            );
            setDisplay(root, "total_payable", scheme.total_payable_display);
            var glp = root.querySelector('[data-unipayment-display="glp"]');
            var gpr = root.querySelector('[data-unipayment-display="gpr"]');
            if (glp) glp.textContent = (scheme.glp_display || "0.00") + "%";
            if (gpr) gpr.textContent = (scheme.gpr_display || "0.00") + "%";
            lastCalculation = scheme;
            showError("");
        }

        function applyCalculation(calculation) {
            lastCalculation = calculation;
            setDisplay(root, "price", calculation.price_display);
            setDisplay(
                root,
                "financed_amount",
                calculation.financed_amount_display,
            );
            setDisplay(
                root,
                "monthly_installment",
                calculation.monthly_installment_display,
            );
            setDisplay(
                root,
                "total_payable",
                calculation.total_payable_display,
            );
            var glp = root.querySelector('[data-unipayment-display="glp"]');
            var gpr = root.querySelector('[data-unipayment-display="gpr"]');
            if (glp)
                glp.textContent = (calculation.glp_display || "0.00") + "%";
            if (gpr)
                gpr.textContent = (calculation.gpr_display || "0.00") + "%";
            if (first && calculation.first_installment_locked) {
                first.value = String(Math.trunc(calculation.first_installment));
                first.readOnly = true;
            }
            if (firstRow) {
                firstRow.classList.toggle(
                    "unipayment-checkout__row--hidden",
                    !calculation.show_first_installment &&
                        !calculation.first_installment_locked,
                );
            }
            showError("");
        }

        function requestCalculation() {
            var endpoint = root.getAttribute("data-calculate-endpoint");
            var scheme = selectedScheme();
            if (!endpoint || !scheme || !root.isConnected)
                return Promise.reject(new Error("selection"));
            syncHidden();
            if (
                calculateRequest &&
                typeof calculateRequest.abort === "function"
            ) {
                calculateRequest.abort();
            }
            calculateRequest =
                typeof AbortController === "function"
                    ? new AbortController()
                    : null;
            var sequence = ++calculateSequence;
            var payload = new URLSearchParams();
            payload.set("token", root.getAttribute("data-popup-token") || "");
            payload.set("scheme_key", scheme.key);
            payload.set("kop_code", scheme.kop_code);
            payload.set(
                "first_installment",
                String((first && first.value) || "0"),
            );
            var snap = snapshotInput();
            if (snap && snap.value) payload.set("cart_snapshot", snap.value);
            var options = {
                method: "POST",
                credentials: "same-origin",
                body: payload,
                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                    "Content-Type":
                        "application/x-www-form-urlencoded;charset=UTF-8",
                },
            };
            if (calculateRequest) options.signal = calculateRequest.signal;
            return fetch(endpoint, options)
                .then(function (response) {
                    return response.json().then(function (body) {
                        if (!response.ok || !body.success)
                            throw new Error(body.message || "calculation");
                        if (sequence !== calculateSequence || !root.isConnected)
                            throw new Error("stale");
                        if (body.cart_snapshot && snap)
                            snap.value = body.cart_snapshot;
                        applyCalculation(body.calculation);
                        return body;
                    });
                })
                .catch(function (error) {
                    if (
                        error.name === "AbortError" ||
                        error.message === "stale"
                    )
                        throw error;
                    showError(
                        t(
                            root,
                            "data-calculate-failed-message",
                            "Неуспешно изчисление. Моля, опитайте отново.",
                        ),
                    );
                    throw error;
                });
        }

        function scheduleCalculate() {
            window.clearTimeout(calculateTimer);
            calculateTimer = window.setTimeout(function () {
                requestCalculation().catch(function () {});
            }, CALCULATE_DELAY);
        }

        function consentsOk() {
            var boxes = root.querySelectorAll(
                "[data-unipayment-consent-checkbox]",
            );
            if (!boxes.length) return true;
            for (var i = 0; i < boxes.length; i += 1) {
                if (!boxes[i].checked) return false;
            }
            return true;
        }

        function process2Ok() {
            if (!config.process2) return true;
            if (!egn || !phone2) return false;
            if (!String(egn.value || "").trim()) {
                showError(
                    t(
                        root,
                        "data-egn-required-message",
                        "Полето „ЕГН“ е задължително.",
                    ),
                );
                return false;
            }
            if (!validEgn(egn.value)) {
                showError(
                    t(
                        root,
                        "data-egn-invalid-message",
                        "Въведете валидно ЕГН (10 цифри, първите 8 — дата YYYYMMDD).",
                    ),
                );
                return false;
            }
            if (!String(phone2.value || "").trim()) {
                showError(
                    t(
                        root,
                        "data-phone2-required-message",
                        "Полето „Втори телефон“ е задължително.",
                    ),
                );
                return false;
            }
            if (!validPhone(phone2.value)) {
                showError(
                    t(
                        root,
                        "data-phone2-invalid-message",
                        "Въведете валиден втори телефонен номер.",
                    ),
                );
                return false;
            }
            return true;
        }

        function validateBeforeSubmit() {
            if (submitting) {
                showError(
                    t(
                        root,
                        "data-submitting-message",
                        "Заявката вече се обработва. Моля, изчакайте.",
                    ),
                );
                return false;
            }
            if (!consentsOk()) {
                showError(
                    t(
                        root,
                        "data-consents-required-message",
                        "Моля, приемете всички задължителни съгласия.",
                    ),
                );
                return false;
            }
            if (!process2Ok()) return false;
            if (!lastCalculation && !selectedScheme()) {
                showError(
                    t(
                        root,
                        "data-calculate-failed-message",
                        "Неуспешно изчисление. Моля, опитайте отново.",
                    ),
                );
                return false;
            }
            showError("");
            return true;
        }

        function markSubmitting() {
            submitting = true;
            root.classList.add("unipayment-checkout--submitting");
            var confirmBtn = document.querySelector(
                "#payment-confirmation button",
            );
            if (confirmBtn) {
                confirmBtn.disabled = true;
                confirmBtn.classList.add("disabled");
            }
        }

        if (select) {
            select.addEventListener("change", function () {
                applyLocalScheme();
                scheduleCalculate();
            });
        }
        if (first) {
            first.addEventListener("input", function () {
                if (first.readOnly) return;
                first.value = first.value.replace(/\D/g, "");
                scheduleCalculate();
            });
            first.addEventListener("change", function () {
                if (first.readOnly) return;
                window.clearTimeout(calculateTimer);
                requestCalculation().catch(function () {});
            });
        }
        root.querySelectorAll("[data-unipayment-consent-checkbox]").forEach(
            function (box) {
                box.addEventListener("change", function () {
                    if (consentsOk()) showError("");
                });
            },
        );
        if (egn) {
            egn.addEventListener("input", function () {
                egn.value = egn.value.replace(/\D/g, "").slice(0, 10);
            });
        }
        if (phone2) {
            phone2.addEventListener("input", function () {
                phone2.value = phone2.value.replace(/[^0-9+() -]/g, "");
            });
        }

        if (form) {
            form.addEventListener("submit", function (event) {
                if (!validateBeforeSubmit()) {
                    event.preventDefault();
                    event.stopPropagation();
                    return false;
                }
                syncHidden();
                markSubmitting();
                return true;
            });
        }

        document.addEventListener(
            "click",
            function (event) {
                var button = event.target.closest(
                    "#payment-confirmation button",
                );
                if (!button) return;
                var payment = document.querySelector(
                    'input[name="payment-option"][data-module-name="unipayment"]',
                );
                if (!payment || !payment.checked) return;
                if (!root.offsetParent && root.hidden) return;
                if (!validateBeforeSubmit()) {
                    event.preventDefault();
                    event.stopPropagation();
                }
            },
            true,
        );

        tryPreselectPayment(config);
        applyLocalScheme();
        if (
            Number(config.default_first_installment) > 0 ||
            (first && first.value && first.value !== "0")
        ) {
            scheduleCalculate();
        }
    }

    /**
     * One-shot UniPayment radio selection for Product "Купи" handoff.
     * Does not re-force after the customer manually picks another method.
     * Works for Hummingbird 2.0 and Classic 3.1.1 payment option markup.
     */
    function tryPreselectPayment(config) {
        if (
            !config ||
            !config.preselect_payment ||
            document.body.dataset.unipaymentPaymentPreselected === "1" ||
            document.body.dataset.unipaymentPaymentPreselectAborted === "1"
        ) {
            return;
        }

        function paymentRadios() {
            return document.querySelectorAll(
                'input[name="payment-option"][data-module-name="unipayment"],' +
                    'input[type="radio"][name="payment-option"][data-module-name="unipayment"]',
            );
        }

        function attempt() {
            if (document.body.dataset.unipaymentPaymentPreselected === "1") {
                return true;
            }
            var paymentOption = paymentRadios()[0];
            if (!paymentOption) {
                return false;
            }
            if (!paymentOption.checked) {
                paymentOption.click();
                if (
                    typeof paymentOption.dispatchEvent === "function" &&
                    !paymentOption.checked
                ) {
                    paymentOption.checked = true;
                    paymentOption.dispatchEvent(
                        new Event("change", { bubbles: true }),
                    );
                }
            }
            document.body.dataset.unipaymentPaymentPreselected = "1";
            return true;
        }

        if (attempt()) {
            return;
        }

        var tries = 0;
        var timer = window.setInterval(function () {
            tries += 1;
            if (attempt() || tries >= 40) {
                window.clearInterval(timer);
                if (
                    document.body.dataset.unipaymentPaymentPreselected !== "1"
                ) {
                    document.body.dataset.unipaymentPaymentPreselectAborted =
                        "1";
                }
            }
        }, 250);

        if (typeof MutationObserver === "function") {
            var observer = new MutationObserver(function () {
                if (attempt()) {
                    observer.disconnect();
                }
            });
            observer.observe(document.body, {
                childList: true,
                subtree: true,
            });
            window.setTimeout(function () {
                observer.disconnect();
            }, 12000);
        }
    }

    function initialize() {
        document.querySelectorAll("[data-unipayment-checkout]").forEach(setup);
        // Prefer server-rendered preselect flag from any mounted UniPayment form.
        document
            .querySelectorAll("[data-unipayment-checkout][data-config]")
            .forEach(function (root) {
                tryPreselectPayment(parseConfig(root));
            });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initialize);
    } else {
        initialize();
    }
    if (window.prestashop && typeof window.prestashop.on === "function") {
        // Hummingbird 2.0 / Classic: delivery update re-renders payment options.
        window.prestashop.on("updatedDeliveryForm", initialize);
        // Core checkout step changes (Classic + shared) may remount payment HTML.
        window.prestashop.on("changedCheckoutStep", initialize);
        // Voucher/cart mutations on checkout trigger updatedCart after AJAX refresh.
        window.prestashop.on("updatedCart", initialize);
    }

    // If the customer manually chooses another payment method after handoff, never force UniCredit again.
    document.addEventListener(
        "change",
        function (event) {
            var target = event.target;
            if (
                !target ||
                target.name !== "payment-option" ||
                target.getAttribute("data-module-name") === "unipayment"
            ) {
                return;
            }
            if (document.body.dataset.unipaymentPaymentPreselected === "1") {
                document.body.dataset.unipaymentPaymentPreselectAborted = "1";
            }
        },
        true,
    );
})();
