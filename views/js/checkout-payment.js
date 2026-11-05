(function () {
    "use strict";

    // Own-form boundary: core's synthetic submit eventually calls this instance's submit().
    // Native submit events and direct calls share the same final, synchronous check.
    var checkoutForms = new WeakMap();
    function protectCheckoutForm(form, valid, generation, submitted, blocked, retired) {
        var state = checkoutForms.get(form);
        if (!state) {
            state = { nativeSubmit: form.submit, owner: null, sent: false };
            checkoutForms.set(form, state);
        }
        if (state.owner) state.owner.destroy();
        var disposed = false;
        var intent = null;
        var pending = false;
        function currentReady() {
            return !disposed && state.owner === guard && !state.sent && form.isConnected !== false && valid();
        }
        function allowed() {
            return (intent === null || intent === generation()) && currentReady();
        }
        function commit() {
            if (!allowed()) {
                if (!disposed && state.owner === guard && !state.sent && blocked) blocked();
                return false;
            }
            state.sent = true;
            submitted();
            return true;
        }
        function onSubmit(event) {
            if (disposed || state.owner !== guard) return;
            if (!commit()) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        }
        function guardedSubmit() {
            if (!commit()) return false;
            state.nativeSubmit.call(form);
            return true;
        }
        var guard = {
            claim: function () {
                if (pending || !currentReady()) return false;
                intent = generation();
                pending = true;
                return true;
            },
            // Unlock a new click, but never erase the generation of an older async check.
            release: function () { if (!disposed && state.owner === guard && !state.sent) pending = false; },
            destroy: function () {
                if (disposed) return;
                disposed = true;
                form.removeEventListener("submit", onSubmit);
                if (state.owner === guard) {
                    state.owner = null;
                    // Retained form references stay blocked until a new owner mounts.
                    if (form.submit === guardedSubmit) form.submit = function () { return false; };
                }
                if (retired) retired();
            }
        };
        state.owner = guard;
        form.submit = guardedSubmit;
        form.addEventListener("submit", onSubmit);
        return guard;
    }
    if (typeof module === "object" && module.exports) {
        module.exports.protectCheckoutForm = protectCheckoutForm;
        return;
    }

    var CALCULATE_DELAY = 900;
    var mounted = [];

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
        if (primary) primary.textContent = (display && display.primary) || "";
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

        var previousFirstLocked = null;
        var submitState = "idle";
        var consents = window.createUnipaymentShopConsents(root);
        var consentGeneration = 0;
        var disposed = false;
        var clickTimer = null;
        mounted.push(root);

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
                var locked = !!scheme.first_installment_locked;
                first.readOnly = locked;
                if (locked) {
                    first.value = String(Math.trunc(scheme.first_installment));
                } else if (previousFirstLocked === true) {
                    // locked → editable: do not carry the previous locked amount
                    first.value = "0";
                } else if (first.value === "") {
                    first.value = "0";
                }
                previousFirstLocked = locked;
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
            consentGeneration = consents.begin(selectionIdentity());
            lastCalculation = consents.apply(consentGeneration, scheme.consent_context) ? scheme : null;
            showError(lastCalculation ? "" : t(root, "data-consent-context-invalid-message", "Обновете страницата и изберете схема отново."));
        }

        function applyCalculation(calculation) {
            if (!consents.apply(consentGeneration, calculation.consent_context)) throw new Error("consent_context");
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

        function selectionIdentity() {
            var scheme = selectedScheme() || {};
            return JSON.stringify([scheme.kop_code, scheme.months, scheme.filter_id, scheme.scheme_type]);
        }

        function invalidateCalculation() {
            calculateSequence += 1;
            lastCalculation = null;
            consentGeneration = consents.begin(selectionIdentity());
        }

        function requestCalculation() {
            if (disposed) return Promise.reject(new Error("stale"));
            window.clearTimeout(calculateTimer);
            calculateTimer = null;
            invalidateCalculation();
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
                        if (disposed || sequence !== calculateSequence || !root.isConnected) throw new Error("stale");
                        if (!response.ok || !body.success)
                            throw new Error(body.message || "calculation");
                        if (body.cart_snapshot && snap)
                            snap.value = body.cart_snapshot;
                        applyCalculation(body.calculation);
                        return body;
                    });
                })
                .catch(function (error) {
                    if (
                        error.name === "AbortError" ||
                        error.message === "stale" || disposed || sequence !== calculateSequence || !root.isConnected
                    )
                        throw error;
                    lastCalculation = null;
                    showError(
                        error.message === "consent_context" ? t(root, "data-consent-context-invalid-message", "Обновете страницата и изберете схема отново.") : t(
                            root,
                            "data-calculate-failed-message",
                            "Неуспешно изчисление. Моля, опитайте отново.",
                        ),
                    );
                    throw error;
                });
        }

        function scheduleCalculate() {
            invalidateCalculation();
            window.clearTimeout(calculateTimer);
            calculateTimer = window.setTimeout(function () {
                requestCalculation().catch(function () {});
            }, CALCULATE_DELAY);
        }

        function consentsOk() {
            return consents.ready();
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

        function validateFormFields() {
            if (disposed || !root.isConnected || calculateTimer !== null || !lastCalculation || !consents.resolved()) {
                showError(t(root, "data-consent-context-invalid-message", "Изчакайте изчислението или обновете страницата."));
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

        function confirmationButton() {
            return document.querySelector("#payment-confirmation button");
        }

        function validateBeforeSubmit() {
            if (submitState === "submitting") {
                return false;
            }
            return validateFormFields();
        }

        function markSubmitting() {
            submitState = "submitting";
            root.classList.remove("unipayment-checkout--click-accepted");
            root.classList.add("unipayment-checkout--submitting");
            var confirmBtn = confirmationButton();
            if (confirmBtn) {
                confirmBtn.disabled = true;
                confirmBtn.classList.add("disabled");
                confirmBtn.setAttribute("aria-busy", "true");
            }
        }

        function resetClick() {
            if (disposed || submitState === "submitting") return;
            submitState = "idle";
            root.classList.remove("unipayment-checkout--click-accepted");
            var payment = document.querySelector('input[name="payment-option"][data-module-name="unipayment"]');
            var button = payment && payment.checked ? confirmationButton() : null;
            if (button) button.removeAttribute("aria-busy");
            if (submitGuard) submitGuard.release();
        }

        var submitGuard = form ? protectCheckoutForm(
            form,
            function () {
                var payment = document.querySelector('input[name="payment-option"][data-module-name="unipayment"]');
                if (!payment || !payment.checked) return false;
                syncHidden();
                return validateFormFields();
            },
            function () { return consentGeneration; },
            markSubmitting,
            function () {
                resetClick();
                showError(t(root, "data-consent-context-invalid-message", "Обновете страницата и потвърдете избраната схема отново."));
            },
            destroyCheckout
        ) : null;

        function acceptFirstClick() {
            if (!submitGuard || !submitGuard.claim()) return false;
            submitState = "click_accepted";
            root.classList.add("unipayment-checkout--click-accepted");
            var button = confirmationButton();
            if (button) button.setAttribute("aria-busy", "true");
            window.clearTimeout(clickTimer);
            clickTimer = window.setTimeout(resetClick, 30000);
            return true;
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

        function confirmClick(event) {
            var button = event.target.closest("#payment-confirmation button");
            if (!button || disposed || !root.isConnected) return;
            var payment = document.querySelector('input[name="payment-option"][data-module-name="unipayment"]');
            if (!payment || !payment.checked) return;
            if (submitState !== "idle" || !validateBeforeSubmit() || !acceptFirstClick()) {
                event.preventDefault();
                event.stopPropagation();
                if (event.stopImmediatePropagation) event.stopImmediatePropagation();
            }
        }
        document.addEventListener("click", confirmClick, true);
        root.unipaymentCheckoutInvalidate = function () {
            window.clearTimeout(calculateTimer);
            calculateTimer = null;
            invalidateCalculation();
        };
        root.unipaymentCheckoutRecalculate = function () {
            if (!disposed && submitState !== "submitting") requestCalculation().catch(function () {});
        };
        root.unipaymentCheckoutRelease = resetClick;
        function destroyCheckout() {
            if (disposed) return;
            disposed = true;
            root.unipaymentCheckoutInvalidate();
            window.clearTimeout(clickTimer);
            consents.destroy();
            if (calculateRequest) calculateRequest.abort();
            if (submitGuard) submitGuard.destroy();
            document.removeEventListener("click", confirmClick, true);
        }
        root.unipaymentCheckoutDestroy = destroyCheckout;

        tryPreselectPayment(handoffFrom(config));
        applyLocalScheme();
        if (
            Number(config.default_first_installment) > 0 ||
            (first && first.value && first.value !== "0")
        ) {
            scheduleCalculate();
        }
    }

    function handoffFrom(config) {
        var globalHandoff =
            (window.unipaymentCheckoutHandoff &&
                typeof window.unipaymentCheckoutHandoff === "object" &&
                window.unipaymentCheckoutHandoff) ||
            null;
        if (globalHandoff && globalHandoff.preselect_payment) {
            return globalHandoff;
        }
        if (config && config.preselect_payment) {
            return {
                preselect_payment: true,
                module_name: "unipayment",
                default_scheme_key: config.default_scheme_key || "",
                default_first_installment:
                    config.default_first_installment || 0,
            };
        }
        return null;
    }

    /**
     * One-shot UniPayment radio selection for Product "Купи" handoff.
     * Uses Classic + Hummingbird payment.tpl radios:
     *   input[name="payment-option"][data-module-name="unipayment"]
     * No MutationObserver loops — retries only on checkout lifecycle events.
     */
    function tryPreselectPayment(handoff) {
        if (
            !handoff ||
            !handoff.preselect_payment ||
            document.body.dataset.unipaymentPaymentPreselected === "1" ||
            document.body.dataset.unipaymentPaymentPreselectAborted === "1"
        ) {
            return false;
        }

        var moduleName = handoff.module_name || "unipayment";
        var paymentOption = document.querySelector(
            'input[name="payment-option"][data-module-name="' +
                moduleName +
                '"]',
        );
        if (!paymentOption) {
            return false;
        }

        if (!paymentOption.checked) {
            paymentOption.click();
            if (!paymentOption.checked) {
                paymentOption.checked = true;
                paymentOption.dispatchEvent(
                    new Event("change", { bubbles: true }),
                );
                paymentOption.dispatchEvent(
                    new Event("click", { bubbles: true }),
                );
            }
        }

        document.body.dataset.unipaymentPaymentPreselected = "1";
        applyHandoffScheme(handoff);
        return true;
    }

    function applyHandoffScheme(handoff) {
        var key = handoff && handoff.default_scheme_key;
        if (!key) return;
        document
            .querySelectorAll("[data-unipayment-checkout]")
            .forEach(function (root) {
                var select = root.querySelector("[data-unipayment-scheme]");
                var first = root.querySelector("[data-unipayment-first]");
                if (select) {
                    var options = select.options || [];
                    var found = false;
                    for (var i = 0; i < options.length; i++) {
                        if (options[i].value === key) {
                            select.value = key;
                            found = true;
                            break;
                        }
                    }
                    if (found) {
                        select.dispatchEvent(
                            new Event("change", { bubbles: true }),
                        );
                    }
                }
                if (
                    first &&
                    Number(handoff.default_first_installment) > 0 &&
                    !first.readOnly
                ) {
                    first.value = String(
                        Math.trunc(Number(handoff.default_first_installment)),
                    );
                }
            });
    }

    var handoffRetryTimer = null;
    var handoffRetryCount = 0;

    function scheduleHandoffRetry() {
        if (
            document.body.dataset.unipaymentPaymentPreselected === "1" ||
            document.body.dataset.unipaymentPaymentPreselectAborted === "1"
        ) {
            return;
        }
        var handoff = handoffFrom(null);
        if (!handoff) {
            document
                .querySelectorAll("[data-unipayment-checkout][data-config]")
                .forEach(function (root) {
                    if (!handoff) handoff = handoffFrom(parseConfig(root));
                });
        }
        if (!handoff) return;
        if (tryPreselectPayment(handoff)) return;
        if (handoffRetryCount >= 20) return;
        if (handoffRetryTimer) window.clearTimeout(handoffRetryTimer);
        handoffRetryCount += 1;
        handoffRetryTimer = window.setTimeout(function () {
            tryPreselectPayment(handoff);
        }, 200);
    }

    function initialize() {
        mounted = mounted.filter(function (root) {
            if (root.isConnected) return true;
            root.unipaymentCheckoutDestroy();
            return false;
        });
        document.querySelectorAll("[data-unipayment-checkout]").forEach(setup);
        scheduleHandoffRetry();
    }

    function invalidateForms() {
        mounted.forEach(function (root) { root.unipaymentCheckoutInvalidate(); });
    }
    function refreshForms() {
        invalidateForms();
        initialize();
        mounted.forEach(function (root) { root.unipaymentCheckoutRecalculate(); });
    }
    document.addEventListener("change", function (event) {
        if (event.target.closest && event.target.closest("#js-delivery, .js-delivery-form, #checkout-addresses-step")) invalidateForms();
    }, true);
    // Observe form mounts only; consent visibility/text changes do not trigger reinitialization loops.
    if (typeof MutationObserver === "function") {
        new MutationObserver(function (changes) {
            if (changes.some(function (change) {
                return Array.from(change.addedNodes).concat(Array.from(change.removedNodes)).some(function (node) {
                    return node.nodeType === 1 && (node.matches("[data-unipayment-checkout], [data-unipayment-checkout-form]") || node.querySelector("[data-unipayment-checkout]"));
                });
            })) initialize();
        }).observe(document.body, {childList: true, subtree: true});
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initialize);
    } else {
        initialize();
    }
    if (window.prestashop && typeof window.prestashop.on === "function") {
        // Hummingbird 2.0 / Classic: delivery update re-renders payment options.
        window.prestashop.on("updateCart", invalidateForms);
        window.prestashop.on("updatedDeliveryForm", refreshForms);
        // Core checkout step changes (Classic + shared) may remount payment HTML.
        window.prestashop.on("changedCheckoutStep", initialize);
        // Voucher/cart mutations on checkout trigger updatedCart after AJAX refresh.
        window.prestashop.on("updatedCart", refreshForms);
        window.prestashop.on("orderConfirmationErrors", function () { mounted.forEach(function (root) { root.unipaymentCheckoutRelease(); }); });
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
            document.body.dataset.unipaymentPaymentPreselectAborted = "1";
        },
        true,
    );
})();
