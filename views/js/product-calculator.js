(function () {
    "use strict";

    var selector = "[data-unipayment-calculator]";
    var domRefreshTimer = null;
    // Authoritative combination hint from prestashop.emit('updatedProduct', eventPayload).
    var pendingProductUpdateHint = null;

    function parseConfig(root) {
        try {
            return JSON.parse(root.getAttribute("data-calculator") || "{}");
        } catch (error) {
            return null;
        }
    }

    /**
     * Resolve current combination id after native PrestaShop product refresh.
     *
     * Hummingbird 2.0 uses `.js-product-details[data-product]` (no #product-details)
     * and typically has no hidden id_product_attribute input. Classic keeps
     * `#product-details[data-product]`. Prefer theme product state, then form field,
     * then optional updatedProduct payload hint.
     *
     * @param {Document|Object} doc
     * @param {Object|null|undefined} updateHint
     */
    function productAttributeId(doc, updateHint) {
        var hint = updateHint || null;
        if (hint && typeof hint === "object") {
            var hinted = parseInt(hint.id_product_attribute, 10);
            if (!isNaN(hinted) && hinted > 0) {
                return hinted;
            }
        }

        // Classic: #product-details; Hummingbird: .js-product-details (no id).
        var productDetails = doc.querySelector(
            "#product-details[data-product], .js-product-details[data-product]",
        );
        if (productDetails) {
            try {
                var productState = JSON.parse(
                    productDetails.getAttribute("data-product") || "{}",
                );
                var stateAttributeId =
                    parseInt(productState.id_product_attribute, 10) || 0;
                if (stateAttributeId > 0) return stateAttributeId;
            } catch (error) {
                // Fall back to the theme's hidden field when the PrestaShop state is unavailable.
            }
        }
        var field = doc.querySelector(
            '#add-to-cart-or-refresh input[name="id_product_attribute"], input[name="id_product_attribute"]',
        );
        return field ? Math.max(0, parseInt(field.value, 10) || 0) : 0;
    }

    function buttonInstallmentLabel(offer) {
        return offer && typeof offer.installment_label === "string"
            ? offer.installment_label
            : "";
    }

    function popupCalculationIdentity(action, selected, lastCalculation) {
        if (action !== "calculate" && lastCalculation) {
            return lastCalculation;
        }
        return selected || null;
    }

    function popupCalculationSchemeFields(identity, selected, activeType) {
        var source = identity || selected || {};
        var selectedScheme = selected || {};
        return {
            scheme_key:
                source.scheme_key || source.key || selectedScheme.key || "",
            scheme_type:
                source.scheme_type ||
                selectedScheme.scheme_type ||
                activeType ||
                "",
            kop_code: source.kop_code || selectedScheme.kop_code || "",
            months:
                source.months != null
                    ? source.months
                    : selectedScheme.months || 0,
            filter_id:
                source.filter_id != null
                    ? source.filter_id
                    : selectedScheme.filter_id || 0,
        };
    }

    function secondaryActionUsesNativeAddToCart(buttonAction) {
        return buttonAction !== "buy";
    }

    function resolveCheckoutRedirectUrl(body, fallbackUrl) {
        if (body && body.checkout_url) {
            return body.checkout_url;
        }
        return fallbackUrl || "";
    }

    function createPreselectOperationToken() {
        var cryptoRef =
            (typeof globalThis !== "undefined" && globalThis.crypto) ||
            (typeof window !== "undefined" && window.crypto);
        if (cryptoRef && typeof cryptoRef.getRandomValues === "function") {
            var bytes = new Uint8Array(16);
            cryptoRef.getRandomValues(bytes);
            var hex = "";
            var index;
            for (index = 0; index < bytes.length; index += 1) {
                hex += ("0" + bytes[index].toString(16)).slice(-2);
            }
            return hex;
        }

        return (
            Date.now().toString(16) +
            Math.floor(Math.random() * 1e9).toString(16)
        );
    }

    function attachPreselectOperationToken(payload, action, operationToken) {
        if (action === "preselect" && operationToken) {
            payload.set("preselect_operation_token", operationToken);
        }
        return payload;
    }

    if (typeof module === "object" && module.exports) {
        module.exports.productAttributeId = productAttributeId;
        module.exports.buttonInstallmentLabel = buttonInstallmentLabel;
        module.exports.popupCalculationIdentity = popupCalculationIdentity;
        module.exports.popupCalculationSchemeFields =
            popupCalculationSchemeFields;
        module.exports.secondaryActionUsesNativeAddToCart =
            secondaryActionUsesNativeAddToCart;
        module.exports.resolveCheckoutRedirectUrl = resolveCheckoutRedirectUrl;
        module.exports.createPreselectOperationToken =
            createPreselectOperationToken;
        module.exports.attachPreselectOperationToken =
            attachPreselectOperationToken;
        return;
    }

    function readProductAttributeId() {
        return productAttributeId(document, pendingProductUpdateHint);
    }

    function quantity() {
        var field = document.querySelector(
            '#quantity_wanted, input[name="qty"], input[name="quantity"]',
        );
        return field ? Math.max(1, parseInt(field.value, 10) || 1) : 1;
    }

    function applyVisualConfig(root, config) {
        var available = config || {};
        root.classList.toggle(
            "unipayment-product-calculator--dark",
            !!available.dark_button,
        );
        root.classList.toggle(
            "unipayment-product-calculator--no-installment",
            !available.show_installment,
        );
        root.classList.toggle(
            "unipayment-product-calculator--stacked",
            available.buttons_in_row === false,
        );
        root.style.setProperty(
            "--unipayment-button-width",
            (parseInt(available.button_width, 10) || 290) + "px",
        );
        root.style.setProperty(
            "--unipayment-button-height",
            (parseInt(available.button_height, 10) || 56) + "px",
        );
        var heading = root.querySelector(
            ".unipayment-product-calculator__heading",
        );
        if (heading && typeof available.heading === "string") {
            heading.textContent = available.heading;
            heading.hidden = available.heading === "";
        }
        var logo = root.querySelector("[data-unipayment-logo]");
        if (logo)
            logo.src =
                root.getAttribute(
                    available.dark_button
                        ? "data-logo-alternative"
                        : "data-logo-standard",
                ) || logo.src;
    }

    function setup(root) {
        if (root.dataset.unipaymentReady === "1") return;
        root.dataset.unipaymentReady = "1";
        var config = parseConfig(root);
        var modal = root.querySelector("[data-unipayment-modal]");
        var dialog = modal ? modal.querySelector('[role="dialog"]') : null;
        var backgroundInertRecords = [];
        var modalFocusInGuard = null;
        var FOCUSABLE_SELECTOR =
            'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
        var select = root.querySelector("[data-unipayment-schemes]");
        var first = root.querySelector("[data-unipayment-first]");
        var firstRow = root.querySelector("[data-unipayment-first-row]");
        var step1 = root.querySelector('[data-unipayment-step="1"]');
        var step2 = root.querySelector('[data-unipayment-step="2"]');
        var step3 = root.querySelector('[data-unipayment-step="3"]');
        var processing = root.querySelector("[data-unipayment-processing]");
        var processingDefaultMarkup = processing ? processing.innerHTML : "";
        var customerForm =
            root.querySelector("[data-unipayment-customer-form]") || step2;
        var submitError = root.querySelector("[data-unipayment-submit-error]");
        var applyButton = root.querySelector("[data-unipayment-apply]");
        var secondaryButton = root.querySelector("[data-unipayment-secondary]");
        var submitButton = root.querySelector("[data-unipayment-submit]");
        var errorBox = root.querySelector("[data-unipayment-popup-error]");
        var isCartSource =
            root.getAttribute("data-unipayment-source") === "cart" ||
            root.getAttribute("data-hide-secondary") === "1";
        var activeType = "";

        function setSecondaryDisabled(disabled) {
            if (secondaryButton) secondaryButton.disabled = !!disabled;
        }
        var lastCalculation = null;
        var lastOpenTrigger = null;
        var calculateTimer = null;
        var calculateRequest = null;
        var calculateSequence = 0;
        var refreshTimer = null;
        var refreshRequest = null;
        var refreshSequence = 0;
        var lastRequestKey = "";
        var redirectPending = false;
        var postOrderFinal = false;
        var popupSubmissionToken = "";
        var preselectOperationToken = "";

        function markPostOrderFinal() {
            postOrderFinal = true;
            root.unipaymentPostOrderFinal = true;
        }

        function clearPostOrderFinal() {
            postOrderFinal = false;
            root.unipaymentPostOrderFinal = false;
        }

        function t(attribute, fallback) {
            return root.getAttribute(attribute) || fallback;
        }

        function isModalOpen() {
            return !!modal && modal.hidden === false;
        }

        function supportsInert() {
            return "inert" in HTMLElement.prototype;
        }

        function getDialogContainer() {
            return dialog || modal;
        }

        function isElementHidden(element, stopAt) {
            var node = element;
            while (node && node !== stopAt) {
                if (
                    node.hidden ||
                    node.getAttribute("aria-hidden") === "true"
                ) {
                    return true;
                }
                node = node.parentElement;
            }
            return false;
        }

        function getFocusableElements(container) {
            if (!container) return [];
            var nodes = container.querySelectorAll(FOCUSABLE_SELECTOR);
            var focusables = [];
            var index;
            for (index = 0; index < nodes.length; index += 1) {
                var element = nodes[index];
                if (element.disabled) continue;
                if (isElementHidden(element, container)) continue;
                var style = window.getComputedStyle(element);
                if (style.visibility === "hidden" || style.display === "none") {
                    continue;
                }
                focusables.push(element);
            }
            return focusables;
        }

        function focusDialogContainer() {
            var container = getDialogContainer();
            if (!container || typeof container.focus !== "function") return;
            if (!container.hasAttribute("tabindex")) {
                container.setAttribute("tabindex", "-1");
            }
            container.focus();
        }

        function focusInitialInModal() {
            if (
                select &&
                !select.disabled &&
                !isElementHidden(select, getDialogContainer())
            ) {
                select.focus();
                return;
            }
            var focusables = getFocusableElements(getDialogContainer());
            if (focusables.length) {
                focusables[0].focus();
                return;
            }
            focusDialogContainer();
        }

        function applyBackgroundInert() {
            if (!supportsInert() || !modal) return;
            backgroundInertRecords = [];
            var seen = [];
            function remember(element) {
                if (
                    !element ||
                    element === modal ||
                    seen.indexOf(element) !== -1
                ) {
                    return;
                }
                seen.push(element);
                backgroundInertRecords.push({
                    el: element,
                    inert: element.inert,
                });
                element.inert = true;
            }
            var index;
            var child;
            if (document.body) {
                for (
                    index = 0;
                    index < document.body.children.length;
                    index += 1
                ) {
                    child = document.body.children[index];
                    if (!child.contains(modal)) remember(child);
                }
            }
            var anchor = modal;
            while (anchor && anchor !== document.body) {
                var parent = anchor.parentElement;
                if (parent) {
                    for (
                        index = 0;
                        index < parent.children.length;
                        index += 1
                    ) {
                        child = parent.children[index];
                        if (child !== anchor && !child.contains(modal)) {
                            remember(child);
                        }
                    }
                }
                anchor = parent;
            }
        }

        function restoreBackgroundInert() {
            backgroundInertRecords.forEach(function (record) {
                if (record.el && supportsInert()) {
                    record.el.inert = record.inert;
                }
            });
            backgroundInertRecords = [];
        }

        function enableModalFocusGuard() {
            if (modalFocusInGuard) return;
            modalFocusInGuard = function (event) {
                if (!isModalOpen()) return;
                var container = getDialogContainer();
                if (!container || container.contains(event.target)) return;
                event.stopPropagation();
                var focusables = getFocusableElements(container);
                if (focusables.length) focusables[0].focus();
                else focusDialogContainer();
            };
            document.addEventListener("focusin", modalFocusInGuard, true);
        }

        function disableModalFocusGuard() {
            if (!modalFocusInGuard) return;
            document.removeEventListener("focusin", modalFocusInGuard, true);
            modalFocusInGuard = null;
        }

        function handleModalTabKey(event) {
            if (!isModalOpen()) return;
            var container = getDialogContainer();
            var focusables = getFocusableElements(container);
            if (!focusables.length) {
                event.preventDefault();
                focusDialogContainer();
                return;
            }
            var first = focusables[0];
            var last = focusables[focusables.length - 1];
            var active = document.activeElement;
            if (!container.contains(active)) {
                event.preventDefault();
                (event.shiftKey ? last : first).focus();
                return;
            }
            if (event.shiftKey && active === first) {
                event.preventDefault();
                last.focus();
                return;
            }
            if (!event.shiftKey && active === last) {
                event.preventDefault();
                first.focus();
            }
        }

        var STEP_TRANSITION_MS = 600;
        var currentStep = 1;
        var stepTimer = null;

        function prefersReducedMotion() {
            return (
                window.matchMedia &&
                window.matchMedia("(prefers-reduced-motion: reduce)").matches
            );
        }

        function clearStepStyles(el) {
            if (!el) return;
            el.style.height = "";
            el.style.opacity = "";
            el.style.overflow = "";
            el.style.transition = "";
            el.classList.remove(
                "unipayment-product-calculator__step--animating",
            );
        }

        function applyStepVisibility(number) {
            step1.hidden = number !== 1;
            step1.classList.toggle(
                "unipayment-product-calculator__step--active",
                number === 1,
            );
            step2.hidden = number !== 2;
            step2.classList.toggle(
                "unipayment-product-calculator__step--active",
                number === 2,
            );
            if (step3) {
                step3.hidden = number !== 3;
                step3.classList.toggle(
                    "unipayment-product-calculator__step--active",
                    number === 3,
                );
            }
            clearStepStyles(step1);
            clearStepStyles(step2);
            clearStepStyles(step3);
        }

        function animateSteps(leaving, entering, number) {
            entering.hidden = false;
            entering.classList.add(
                "unipayment-product-calculator__step--active",
            );
            leaving.classList.add(
                "unipayment-product-calculator__step--animating",
            );
            entering.classList.add(
                "unipayment-product-calculator__step--animating",
            );
            var fromHeight = leaving.scrollHeight;
            var toHeight = entering.scrollHeight;
            leaving.style.overflow = "hidden";
            entering.style.overflow = "hidden";
            leaving.style.height = fromHeight + "px";
            leaving.style.opacity = "1";
            entering.style.height = "0px";
            entering.style.opacity = "0";
            void entering.offsetHeight;
            var transition =
                "height " +
                STEP_TRANSITION_MS +
                "ms ease-in-out, opacity " +
                STEP_TRANSITION_MS +
                "ms ease-in-out";
            leaving.style.transition = transition;
            entering.style.transition = transition;
            leaving.style.height = "0px";
            leaving.style.opacity = "0";
            entering.style.height = toHeight + "px";
            entering.style.opacity = "1";
            stepTimer = window.setTimeout(function () {
                stepTimer = null;
                applyStepVisibility(number);
            }, STEP_TRANSITION_MS);
        }

        function setStep(number, options) {
            var animate =
                !!(options && options.animate) && !prefersReducedMotion();
            var from = currentStep;
            var betweenFirstTwo =
                (from === 1 && number === 2) || (from === 2 && number === 1);
            if (stepTimer) {
                window.clearTimeout(stepTimer);
                stepTimer = null;
            }
            if (!animate || !betweenFirstTwo || from === number) {
                currentStep = number;
                applyStepVisibility(number);
                return;
            }
            applyStepVisibility(from);
            currentStep = number;
            animateSteps(
                from === 1 ? step1 : step2,
                number === 1 ? step1 : step2,
                number,
            );
        }

        function customerField(name) {
            return customerForm.querySelector('[name="' + name + '"]');
        }
        function fieldError(name) {
            return customerForm.querySelector(
                '[data-unipayment-field-error="' + name + '"]',
            );
        }
        function nonEmpty(value) {
            return String(value || "").trim() !== "";
        }
        function validPhone(value) {
            var phone = String(value || "").trim();
            return (
                phone !== "" && /^[-0-9+() ]+$/.test(phone) && /\d/.test(phone)
            );
        }
        function validEmail(value) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(
                String(value || "").trim(),
            );
        }
        /** Mirrors PrestaShop Validate::isName for Step 2 UX (server remains authoritative). */
        function validName(value) {
            return /^[^0-9!<>,;?=+()@#"°{}_$%:¤|]*$/u.test(
                String(value || "").trim(),
            );
        }
        /** Mirrors PrestaShop Validate::isAddress for Step 2 UX (server remains authoritative). */
        function validAddressLine(value) {
            var address = String(value || "").trim();
            return address === "" || /^[^!<>?=+@{}_$%]*$/u.test(address);
        }

        function validEgn(value) {
            var egn = String(value || "").replace(/\D/g, "");
            if (!/^\d{10}$/.test(egn)) {
                return false;
            }
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

        function consentCheckboxes() {
            return root.querySelectorAll("[data-unipayment-consent-checkbox]");
        }

        function areMandatoryConsentsChecked() {
            var boxes = consentCheckboxes();
            if (!boxes.length) return true;
            for (var i = 0; i < boxes.length; i += 1) {
                if (!boxes[i].checked) return false;
            }
            return true;
        }

        function resetConsents() {
            consentCheckboxes().forEach(function (input) {
                input.checked = false;
            });
        }

        function appendAcceptedConsents(payload) {
            consentCheckboxes().forEach(function (input) {
                if (input.checked && input.value)
                    payload.append("unipayment_consent[]", input.value);
            });
        }

        function customerErrors() {
            var errors = {};
            var firstName = customerField("first_name").value;
            if (!nonEmpty(firstName))
                errors.first_name = t(
                    "data-required-field-message",
                    "Полето е задължително.",
                );
            else if (!validName(firstName))
                errors.first_name = t(
                    "data-invalid-first-name-message",
                    "Името може да съдържа само букви, интервал, тире и апостроф.",
                );
            var lastName = customerField("last_name").value;
            if (!nonEmpty(lastName))
                errors.last_name = t(
                    "data-required-field-message",
                    "Полето е задължително.",
                );
            else if (!validName(lastName))
                errors.last_name = t(
                    "data-invalid-last-name-message",
                    "Фамилията може да съдържа само букви, интервал, тире и апостроф.",
                );
            var address = customerField("address").value;
            if (!nonEmpty(address))
                errors.address = t(
                    "data-required-field-message",
                    "Полето е задължително.",
                );
            else if (!validAddressLine(address))
                errors.address = t(
                    "data-invalid-address-message",
                    "Адресът може да съдържа букви, цифри, интервали и стандартни знаци. Не използвайте символи като <, >, =, +, @, {, }, _, $, %, !, ?.",
                );
            var phone = customerField("phone").value;
            if (!nonEmpty(phone))
                errors.phone = t(
                    "data-required-field-message",
                    "Полето е задължително.",
                );
            else if (!validPhone(phone))
                errors.phone = t(
                    "data-invalid-phone-message",
                    "Телефонът може да съдържа цифри, интервали, +, -, ( и ).",
                );
            var email = customerField("email").value;
            if (!nonEmpty(email))
                errors.email = t(
                    "data-required-field-message",
                    "Полето е задължително.",
                );
            else if (!validEmail(email))
                errors.email = t(
                    "data-invalid-email-message",
                    "Въведете валиден e-mail адрес, например name@example.com.",
                );
            var egnField = customerField("egn");
            if (egnField) {
                var egn = egnField.value.trim();
                if (!nonEmpty(egn))
                    errors.egn = t(
                        "data-required-field-message",
                        "Полето е задължително.",
                    );
                else if (!validEgn(egn))
                    errors.egn = t(
                        "data-invalid-egn-message",
                        "ЕГН трябва да съдържа 10 цифри. Първите 8 трябва да са валидна дата във формат ГГГГММДД.",
                    );
            }
            var phone2Field = customerField("phone2");
            if (phone2Field) {
                var phone2 = phone2Field.value.trim();
                if (!nonEmpty(phone2))
                    errors.phone2 = t(
                        "data-required-field-message",
                        "Полето е задължително.",
                    );
                else if (!validPhone(phone2))
                    errors.phone2 = t(
                        "data-invalid-phone2-message",
                        "Вторият телефон може да съдържа цифри, интервали, +, -, ( и ).",
                    );
            }
            return errors;
        }

        function showCustomerErrors(errors) {
            var fields = [
                "first_name",
                "last_name",
                "address",
                "phone",
                "email",
            ];
            if (customerField("egn")) fields.push("egn");
            if (customerField("phone2")) fields.push("phone2");
            var firstInvalid = null;
            fields.forEach(function (name) {
                var el = fieldError(name);
                var field = customerField(name);
                if (!el || !field) return;
                var message = errors[name] || "";
                el.textContent = message;
                field.setAttribute("aria-invalid", message ? "true" : "false");
                if (message && !firstInvalid) firstInvalid = field;
            });
            if (firstInvalid && typeof firstInvalid.focus === "function") {
                firstInvalid.focus();
            }
        }

        function showCustomerFieldError(name, message) {
            fieldError(name).textContent = message || "";
            customerField(name).setAttribute(
                "aria-invalid",
                message ? "true" : "false",
            );
        }

        function updateSubmitState(showErrors) {
            var errors = customerErrors();
            if (showErrors) showCustomerErrors(errors);
            var valid =
                Object.keys(errors).length === 0 &&
                !!lastCalculation &&
                areMandatoryConsentsChecked();
            submitButton.disabled = !valid;
            submitButton.setAttribute(
                "aria-disabled",
                valid ? "false" : "true",
            );
            return valid;
        }

        function resetCustomerForm() {
            if (!customerForm || !submitButton) return;
            customerForm.querySelectorAll("input").forEach(function (input) {
                if (input.type === "checkbox") input.checked = false;
                else input.value = input.defaultValue;
            });
            resetConsents();
            showCustomerErrors({});
            submitError.textContent = "";
            updateSubmitState(false);
        }

        function resetPopup() {
            window.clearTimeout(calculateTimer);
            calculateTimer = null;
            if (
                calculateRequest &&
                typeof calculateRequest.abort === "function"
            )
                calculateRequest.abort();
            calculateRequest = null;
            calculateSequence += 1;
            lastCalculation = null;
            redirectPending = false;
            clearPostOrderFinal();
            popupSubmissionToken = "";
            preselectOperationToken = "";
            root.unipaymentPopupSubmissionToken = "";
            activeType = "";
            root.classList.remove("unipayment-product-calculator--error");
            first.value = "0";
            first.readOnly = false;
            firstRow.hidden = true;
            select.textContent = "";
            errorBox.textContent = "";
            applyButton.disabled = true;
            setSecondaryDisabled(true);
            resetCustomerForm();
            setStep(1);
        }

        function close() {
            if (redirectPending) return;
            modal.hidden = true;
            modal.setAttribute("aria-hidden", "true");
            document.body.classList.remove("unipayment-modal-open");
            disableModalFocusGuard();
            restoreBackgroundInert();
            setProcessingState(false);
            resetPopup();
            if (lastOpenTrigger && document.body.contains(lastOpenTrigger))
                lastOpenTrigger.focus();
            lastOpenTrigger = null;
        }

        function setProcessingState(active) {
            if (!processing) return;
            if (active) {
                root.classList.remove("unipayment-product-calculator--error");
                if (processingDefaultMarkup !== "") {
                    processing.innerHTML = processingDefaultMarkup;
                }
                root.classList.add("unipayment-product-calculator--processing");
                processing.removeAttribute("hidden");
                modal.setAttribute("aria-busy", "true");
                return;
            }

            root.classList.remove("unipayment-product-calculator--processing");
            processing.setAttribute("hidden", "hidden");
            modal.setAttribute("aria-busy", "false");
        }

        function optionText(scheme) {
            var text = (
                root.getAttribute("data-months-label") || "%d месеца"
            ).replace("%d", scheme.months);
            return (
                text + (scheme.description ? " - " + scheme.description : "")
            );
        }

        function rebuildSchemes(type) {
            var offer = config && config.offers ? config.offers[type] : null;
            select.textContent = "";
            if (!offer || !offer.schemes || !offer.schemes.length) return false;
            offer.schemes.forEach(function (scheme) {
                var option = document.createElement("option");
                option.value =
                    scheme.key ||
                    (scheme.scheme_type === "promo" ? "p:" : "") +
                        scheme.months +
                        ":" +
                        scheme.filter_id;
                option.textContent = optionText(scheme) + "\u00a0\u00a0\u00a0";
                option.selected = option.value === offer.preferred_scheme_key;
                select.appendChild(option);
            });
            select.disabled = false;
            return true;
        }

        function selectedScheme() {
            var offer =
                config && config.offers ? config.offers[activeType] : null;
            if (!offer || !offer.schemes) return null;
            var key = select.value;
            return (
                offer.schemes.filter(function (scheme) {
                    var schemeKey =
                        scheme.key ||
                        (scheme.scheme_type === "promo" ? "p:" : "") +
                            scheme.months +
                            ":" +
                            scheme.filter_id;
                    return schemeKey === key;
                })[0] || null
            );
        }

        function displayAmount(target, display) {
            var element = root.querySelector(
                '[data-unipayment-display="' + target + '"]',
            );
            if (element)
                element.textContent = display
                    ? display.primary +
                      (display.dual && display.secondary
                          ? " (" + display.secondary + ")"
                          : "")
                    : "";
        }

        function applyCalculation(calculation) {
            lastCalculation = calculation;
            displayAmount("price", calculation.price_display);
            displayAmount(
                "financed_amount",
                calculation.financed_amount_display,
            );
            displayAmount(
                "monthly_installment",
                calculation.monthly_installment_display,
            );
            displayAmount("total_payable", calculation.total_payable_display);
            root.querySelector('[data-unipayment-display="glp"]').textContent =
                calculation.glp_display + "%";
            root.querySelector('[data-unipayment-display="gpr"]').textContent =
                calculation.gpr_display + "%";
            var firstInstallmentLocked = !!calculation.first_installment_locked;
            var firstInstallment = Number(calculation.first_installment || 0);
            first.value = firstInstallmentLocked
                ? firstInstallment.toFixed(2)
                : String(Math.trunc(firstInstallment));
            first.readOnly = firstInstallmentLocked;
            firstRow.hidden =
                !calculation.show_first_installment &&
                !calculation.first_installment_locked;
            errorBox.textContent = "";
            applyButton.disabled = false;
            setSecondaryDisabled(false);
        }

        function calculationPayload(action) {
            var scheme = selectedScheme();
            if (!scheme) return null;
            var identity = popupCalculationIdentity(
                action,
                scheme,
                lastCalculation,
            );
            var fields = popupCalculationSchemeFields(
                identity,
                scheme,
                activeType,
            );
            var payload = new URLSearchParams();
            payload.set("token", root.getAttribute("data-popup-token") || "");
            payload.set("popup_action", action || "calculate");
            payload.set(
                "id_product",
                root.getAttribute("data-product-id") || "0",
            );
            payload.set(
                "id_product_attribute",
                String(readProductAttributeId()),
            );
            payload.set("quantity", String(quantity()));
            payload.set("popup_offer_type", activeType);
            payload.set("scheme_key", fields.scheme_key);
            payload.set("scheme_type", fields.scheme_type);
            payload.set("kop_code", fields.kop_code);
            payload.set("months", String(fields.months || 0));
            payload.set("filter_id", String(fields.filter_id || 0));
            payload.set(
                "first_installment",
                String(
                    action !== "calculate" &&
                        lastCalculation &&
                        lastCalculation.first_installment != null
                        ? lastCalculation.first_installment
                        : first.value || "0",
                ),
            );
            attachPreselectOperationToken(
                payload,
                action || "calculate",
                preselectOperationToken,
            );
            return payload;
        }

        function requestCalculation(action) {
            var payload = calculationPayload(action);
            var endpoint = root.getAttribute("data-popup-endpoint");
            if (!payload || !endpoint)
                return Promise.reject(new Error("selection"));
            if (
                calculateRequest &&
                typeof calculateRequest.abort === "function"
            )
                calculateRequest.abort();
            calculateRequest =
                typeof AbortController === "function"
                    ? new AbortController()
                    : null;
            var sequence = ++calculateSequence;
            applyButton.disabled = true;
            setSecondaryDisabled(true);
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
                        if (sequence !== calculateSequence)
                            throw new Error("stale");
                        applyCalculation(body.calculation);
                        return body;
                    });
                })
                .catch(function (error) {
                    if (
                        sequence === calculateSequence &&
                        error.name !== "AbortError" &&
                        error.message !== "stale"
                    )
                        errorBox.textContent = t(
                            "data-calculate-failed-message",
                            "Неуспешно изчисление. Моля, опитайте отново.",
                        );
                    throw error;
                });
        }

        function calculateNow() {
            requestCalculation("calculate").catch(function () {});
        }

        function open(type, trigger) {
            resetPopup();
            activeType = type;
            lastOpenTrigger = trigger || null;
            if (!rebuildSchemes(type)) return;
            modal.hidden = false;
            modal.setAttribute("aria-hidden", "false");
            document.body.classList.add("unipayment-modal-open");
            applyBackgroundInert();
            enableModalFocusGuard();
            calculateNow();
            focusInitialInModal();
        }

        function nativeAddButton() {
            return document.querySelector(
                '.product-add-to-cart button[data-button-action="add-to-cart"], .product-add-to-cart button.add-to-cart, button[data-button-action="add-to-cart"]',
            );
        }

        function redirectToCheckout(redirectUrl) {
            if (!redirectUrl) {
                errorBox.textContent = t(
                    "data-add-to-cart-failed-message",
                    "Не може да се добави в количката.",
                );
                redirectPending = false;
                return;
            }
            window.location.assign(redirectUrl);
        }

        function addToCart(redirectUrl) {
            var button = nativeAddButton();
            if (
                !button ||
                button.disabled ||
                button.classList.contains("disabled")
            ) {
                errorBox.textContent = t(
                    "data-add-to-cart-failed-message",
                    "Не може да се добави в количката.",
                );
                redirectPending = false;
                return;
            }
            if (
                redirectUrl &&
                window.prestashop &&
                typeof window.prestashop.on === "function"
            ) {
                redirectPending = true;
                window.prestashop.on("updatedCart", function () {
                    if (redirectPending) window.location.assign(redirectUrl);
                });
            } else {
                close();
            }
            button.click();
        }

        function handleSecondary() {
            if (isCartSource || !lastCalculation || redirectPending) return;
            if (
                secondaryActionUsesNativeAddToCart(
                    root.getAttribute("data-button-action"),
                )
            ) {
                addToCart("");
                return;
            }
            redirectPending = true;
            setSecondaryDisabled(true);
            if (applyButton) applyButton.disabled = true;
            preselectOperationToken = createPreselectOperationToken();
            requestCalculation("preselect")
                .then(function (body) {
                    applyButton.disabled = true;
                    setSecondaryDisabled(true);
                    redirectToCheckout(
                        resolveCheckoutRedirectUrl(
                            body,
                            root.getAttribute("data-checkout-url"),
                        ),
                    );
                })
                .catch(function () {
                    redirectPending = false;
                    if (lastCalculation) {
                        if (applyButton) applyButton.disabled = false;
                        setSecondaryDisabled(false);
                    }
                });
        }

        function transitionToStep2() {
            if (!lastCalculation) return;
            if (!customerForm || !submitButton || !step3) {
                errorBox.textContent = t(
                    "data-customer-form-missing-message",
                    "Формата за лични данни не се зареди. Моля, презаредете страницата.",
                );
                return;
            }
            var state = {
                productId:
                    parseInt(root.getAttribute("data-product-id"), 10) || 0,
                productAttributeId: readProductAttributeId(),
                quantity: quantity(),
                type: activeType,
                schemeType: lastCalculation.scheme_type,
                kopCode: lastCalculation.kop_code,
                months: lastCalculation.months,
                filterId: lastCalculation.filter_id,
                firstInstallment: lastCalculation.first_installment,
                calculation: lastCalculation,
            };
            root.unipaymentSelectedFinancing = state;
            root.dispatchEvent(
                new CustomEvent("unipayment:schemeSelected", {
                    bubbles: true,
                    detail: state,
                }),
            );

            function enterStep2() {
                setStep(2, { animate: true });
                showCustomerErrors({});
                submitError.textContent = "";
                updateSubmitState(false);
            }

            ensurePopupSubmissionToken(true)
                .then(function () {
                    enterStep2();
                })
                .catch(function () {
                    errorBox.textContent = t(
                        "data-validation-failed-message",
                        "Данните не могат да бъдат валидирани.",
                    );
                });
        }

        function ensurePopupSubmissionToken(forceIssue) {
            if (!forceIssue && popupSubmissionToken) {
                return Promise.resolve(popupSubmissionToken);
            }
            var payload = calculationPayload("issue_submission_token");
            var endpoint = root.getAttribute("data-popup-endpoint");
            if (!payload || !endpoint) {
                return Promise.reject(new Error("token"));
            }
            if (popupSubmissionToken) {
                payload.set("popup_submission_token", popupSubmissionToken);
            }
            if (applyButton) applyButton.disabled = true;
            return fetch(endpoint, {
                method: "POST",
                credentials: "same-origin",
                body: payload,
                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                    "Content-Type":
                        "application/x-www-form-urlencoded;charset=UTF-8",
                },
            })
                .then(function (response) {
                    return response.json().then(function (body) {
                        if (
                            !response.ok ||
                            !body.success ||
                            !body.popup_submission_token
                        ) {
                            throw new Error((body && body.message) || "token");
                        }
                        popupSubmissionToken = body.popup_submission_token;
                        root.unipaymentPopupSubmissionToken =
                            popupSubmissionToken;
                        return popupSubmissionToken;
                    });
                })
                .then(function (token) {
                    if (applyButton && lastCalculation)
                        applyButton.disabled = false;
                    return token;
                })
                .catch(function (error) {
                    if (applyButton && lastCalculation)
                        applyButton.disabled = false;
                    throw error;
                });
        }

        function requestStep2Validation() {
            if (!customerForm || !submitButton || !step3) return;
            if (!updateSubmitState(true)) return;
            var endpoint = root.getAttribute("data-popup-endpoint");
            if (!endpoint) return;
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.setAttribute("aria-disabled", "true");
            }
            submitError.textContent = "";
            redirectPending = true;
            setProcessingState(true);
            showSmartUcfLoading();
            setStep(3);

            ensurePopupSubmissionToken(false)
                .then(function (token) {
                    var payload = calculationPayload("apply");
                    if (!payload) throw new Error("selection");
                    if (!token && !popupSubmissionToken) {
                        throw new Error("token");
                    }
                    payload.set(
                        "popup_submission_token",
                        token || popupSubmissionToken,
                    );
                    [
                        "first_name",
                        "last_name",
                        "address",
                        "phone",
                        "email",
                    ].forEach(function (name) {
                        payload.set(name, customerField(name).value.trim());
                    });
                    var egnField = customerField("egn");
                    if (egnField) payload.set("egn", egnField.value.trim());
                    var phone2Field = customerField("phone2");
                    if (phone2Field)
                        payload.set("phone2", phone2Field.value.trim());
                    appendAcceptedConsents(payload);
                    return fetch(endpoint, {
                        method: "POST",
                        credentials: "same-origin",
                        body: payload,
                        headers: {
                            "X-Requested-With": "XMLHttpRequest",
                            "Content-Type":
                                "application/x-www-form-urlencoded;charset=UTF-8",
                        },
                    });
                })
                .then(function (response) {
                    return response.json().then(function (body) {
                        if (body && body.popup_submission_token)
                            popupSubmissionToken = body.popup_submission_token;
                        if (!response.ok || !body.success) {
                            if (body && body.selection_changed) {
                                popupSubmissionToken = "";
                                root.unipaymentPopupSubmissionToken = "";
                            }
                            if (body && body.errors)
                                showCustomerErrors(body.errors);
                            // Prefer field-level guidance; keep top message for consents / non-field errors.
                            if (
                                body &&
                                body.errors &&
                                (body.errors.first_name ||
                                    body.errors.last_name ||
                                    body.errors.address ||
                                    body.errors.phone ||
                                    body.errors.email ||
                                    body.errors.egn ||
                                    body.errors.phone2)
                            ) {
                                submitError.textContent = body.errors.consents
                                    ? body.errors.consents
                                    : "";
                            } else {
                                submitError.textContent =
                                    body.message ||
                                    t(
                                        "data-validation-failed-message",
                                        "Данните не могат да бъдат валидирани.",
                                    );
                            }
                            redirectPending = false;
                            setProcessingState(false);
                            throw new Error(body.message || "validation");
                        }
                        if (body.step === "processing") {
                            showSmartUcfLoading();
                            setStep(3);
                            return;
                        }
                        if (body.step === "identity_accepted") {
                            setProcessingState(false);
                            showIdentityAccepted();
                            setStep(3);
                            return;
                        }
                        if (body.step === "outcome_unknown") {
                            setProcessingState(false);
                            showSmartUcfError(
                                body.cp_error ||
                                    body.smartucf_error ||
                                    body.message ||
                                    t(
                                        "data-smartucf-error-default",
                                        "Възникна грешка при обработката на заявката.",
                                    ),
                                { omitRetry: !!(body.final || body.cp_error) },
                            );
                            setStep(3);
                            if (body.final || body.cp_error || body.smartucf_error) {
                                markPostOrderFinal();
                            }
                            return;
                        }
                        root.unipaymentOrderResult = body.order;
                        if (body.redirect_url) {
                            window.location.assign(body.redirect_url);
                            return;
                        }
                        setProcessingState(false);
                        if (body.cp_error || body.smartucf_error) {
                            showSmartUcfError(
                                body.cp_error || body.smartucf_error,
                                {
                                    omitRetry: !!(body.final || body.cp_error),
                                },
                            );
                            setStep(3);
                            markPostOrderFinal();
                            return;
                        }
                        showOrderConfirmation(
                            body.order,
                            body.post_order_error || body.email_error || "",
                        );
                        setStep(3);
                        markPostOrderFinal();
                    });
                })
                .catch(function () {
                    if (postOrderFinal) {
                        setProcessingState(false);
                        return;
                    }
                    redirectPending = false;
                    setProcessingState(false);
                    setStep(2);
                    if (!step2.hidden) updateSubmitState(false);
                });
        }

        function showSmartUcfLoading() {
            if (!step3) return;
            var processingTitle = t(
                "data-processing-title",
                "Обработване на заявката",
            );
            var processingMessage = t(
                "data-processing-message",
                "Моля, изчакайте...",
            );
            step3.innerHTML =
                '<div class="unipayment-product-calculator__confirmation">' +
                '<h2 class="unipayment-product-calculator__popup-title">' +
                processingTitle +
                "</h2>" +
                "<p>" +
                processingMessage +
                "</p>" +
                "</div>";
        }

        function showIdentityAccepted() {
            if (!step3) return;
            var closeLabel = t("data-close-label", "Затвори");
            var title = t("data-identity-accepted-title", "Данните са приети");
            var message = t(
                "data-identity-accepted-message",
                "Личните данни и избраният план са приети. Поръчката ще бъде завършена на следваща стъпка.",
            );
            step3.innerHTML =
                '<div class="unipayment-product-calculator__confirmation">' +
                '<h2 class="unipayment-product-calculator__popup-title">' +
                title +
                "</h2>" +
                "<p>" +
                message +
                "</p>" +
                '<div class="unipayment-product-calculator__popup-actions">' +
                '<button type="button" class="unipayment-product-calculator__popup-button unipayment-product-calculator__popup-button--primary" data-unipayment-close>' +
                "<span><b>" +
                closeLabel +
                "</b></span></button></div></div>";
            redirectPending = false;
        }

        function showSmartUcfError(errorMessage, options) {
            if (!processing) return;
            options = options || {};
            var omitRetry = options.omitRetry === true;
            var errorDefaultMessage = t(
                "data-smartucf-error-default",
                "Възникна грешка при обработката на заявката.",
            );
            var errorRetryMessage = t(
                "data-smartucf-error-retry",
                "Моля, опитайте по-късно.",
            );
            var closeLabel = t("data-close-label", "Затвори");
            root.classList.remove("unipayment-product-calculator--processing");
            root.classList.add("unipayment-product-calculator--error");
            modal.setAttribute("aria-busy", "false");
            processing.removeAttribute("hidden");
            processing.innerHTML =
                '<div class="unipayment-product-calculator__processing-panel" role="alertdialog" aria-live="assertive" aria-busy="false">' +
                '<p class="unipayment-product-calculator__processing-error">' +
                (errorMessage || errorDefaultMessage) +
                "</p>" +
                (omitRetry
                    ? ""
                    : '<p class="unipayment-product-calculator__processing-text">' +
                      errorRetryMessage +
                      "</p>") +
                '<div class="unipayment-product-calculator__popup-actions unipayment-product-calculator__popup-actions--centered">' +
                '<button type="button" class="unipayment-product-calculator__popup-button unipayment-product-calculator__popup-button--primary" data-unipayment-close>' +
                "<span><b>" +
                closeLabel +
                "</b></span></button></div></div>";
            redirectPending = false;
        }

        function showOrderConfirmation(order, smartucfError) {
            if (!step3) return;
            var closeLabel = t("data-close-label", "Затвори");
            var orderSuccessTitle = t(
                "data-order-success-title",
                "Заявката е изпратена успешно",
            );
            var orderNumberLabel = t(
                "data-order-number-label",
                "Номер на поръчка:",
            );
            var orderConfirmationMessage = t(
                "data-order-confirmation-message",
                "Очаквайте потвърждение от УниКредит.",
            );
            var warning = smartucfError
                ? '<p class="unipayment-product-calculator__popup-row--note">' +
                  smartucfError +
                  "</p>"
                : "";
            step3.innerHTML =
                '<div class="unipayment-product-calculator__confirmation">' +
                '<h2 class="unipayment-product-calculator__popup-title">' +
                orderSuccessTitle +
                "</h2>" +
                "<p>" +
                orderNumberLabel +
                " <strong>" +
                (order.order_reference || "") +
                "</strong></p>" +
                warning +
                "<p>" +
                orderConfirmationMessage +
                "</p>" +
                '<div class="unipayment-product-calculator__popup-actions">' +
                '<button type="button" class="unipayment-product-calculator__popup-button unipayment-product-calculator__popup-button--primary" data-unipayment-close>' +
                "<span><b>" +
                closeLabel +
                "</b></span></button></div></div>";
            redirectPending = false;
        }

        root.addEventListener("click", function (event) {
            var offerButton = event.target.closest("[data-unipayment-offer]");
            if (offerButton)
                open(
                    offerButton.getAttribute("data-unipayment-offer"),
                    offerButton,
                );
            if (event.target.closest("[data-unipayment-close]")) close();
            if (event.target.closest("[data-unipayment-secondary]"))
                handleSecondary();
            if (event.target.closest("[data-unipayment-apply]"))
                transitionToStep2();
            if (event.target.closest("[data-unipayment-back]"))
                setStep(1, { animate: true });
            if (event.target.closest("[data-unipayment-submit]"))
                requestStep2Validation();
        });
        if (customerForm) {
            customerForm.addEventListener("input", function (event) {
                if (
                    event.target &&
                    (event.target.name === "phone" ||
                        event.target.name === "phone2")
                )
                    event.target.value = event.target.value.replace(
                        /[^0-9+() -]/g,
                        "",
                    );
                if (event.target && event.target.name)
                    showCustomerFieldError(
                        event.target.name,
                        customerErrors()[event.target.name] || "",
                    );
                submitError.textContent = "";
                updateSubmitState(false);
            });
            customerForm.addEventListener("change", function (event) {
                if (event.target && event.target.name)
                    showCustomerFieldError(
                        event.target.name,
                        customerErrors()[event.target.name] || "",
                    );
                updateSubmitState(false);
            });
        }
        if (step2) {
            step2.addEventListener("change", function (event) {
                if (
                    event.target &&
                    event.target.matches("[data-unipayment-consent-checkbox]")
                )
                    updateSubmitState(false);
            });
            step2.addEventListener("mousedown", function (event) {
                var consentLink = event.target.closest(
                    ".unipayment-product-calculator__consent-label a",
                );
                if (consentLink) event.stopPropagation();
            });
        }
        select.addEventListener("change", function () {
            first.value = "0";
            first.readOnly = false;
            lastCalculation = null;
            popupSubmissionToken = "";
            preselectOperationToken = "";
            root.unipaymentPopupSubmissionToken = "";
            applyButton.disabled = true;
            setSecondaryDisabled(true);
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.setAttribute("aria-disabled", "true");
            }
            calculateNow();
        });
        first.addEventListener("input", function () {
            if (first.readOnly) return;
            first.value = first.value.replace(/\D/g, "");
            lastCalculation = null;
            popupSubmissionToken = "";
            preselectOperationToken = "";
            root.unipaymentPopupSubmissionToken = "";
            applyButton.disabled = true;
            setSecondaryDisabled(true);
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.setAttribute("aria-disabled", "true");
            }
            window.clearTimeout(calculateTimer);
            calculateTimer = window.setTimeout(calculateNow, 800);
        });
        first.addEventListener("change", function () {
            if (first.readOnly) return;
            window.clearTimeout(calculateTimer);
            calculateNow();
        });
        root.addEventListener("keydown", function (event) {
            if (modal.hidden) return;
            if (event.key === "Escape") {
                close();
                return;
            }
            if (event.key === "Tab") handleModalTabKey(event);
        });

        root.unipaymentUpdate = function (next) {
            // AUD-024: post-order final result must survive cart refresh / empty cart.
            if (postOrderFinal || redirectPending) {
                return;
            }
            config = next;
            root.setAttribute("data-calculator", JSON.stringify(next || {}));
            root.hidden = !next;
            if (isCartSource) {
                popupSubmissionToken = "";
                root.unipaymentPopupSubmissionToken = "";
                lastCalculation = null;
            }
            if (next) applyVisualConfig(root, next);
            root.querySelectorAll("[data-unipayment-offer]").forEach(
                function (button) {
                    var type = button.getAttribute("data-unipayment-offer");
                    var offer = next && next.offers ? next.offers[type] : null;
                    button.hidden = !offer;
                    var price = button.querySelector(
                        "[data-unipayment-preferred-price]",
                    );
                    if (price && offer)
                        price.textContent = buttonInstallmentLabel(offer);
                },
            );
            if (
                !next ||
                (activeType && (!next.offers || !next.offers[activeType]))
            )
                close();
            else if (!modal.hidden && activeType) {
                first.value = "0";
                rebuildSchemes(activeType);
                calculateNow();
            }
        };

        root.unipaymentInvalidatePopup = function () {
            if (postOrderFinal || redirectPending) return;
            if (modal.hidden) return;
            lastCalculation = null;
            popupSubmissionToken = "";
            preselectOperationToken = "";
            root.unipaymentPopupSubmissionToken = "";
            applyButton.disabled = true;
            setSecondaryDisabled(true);
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.setAttribute("aria-disabled", "true");
            }
        };

        root.unipaymentRefresh = function () {
            window.clearTimeout(refreshTimer);
            refreshTimer = window.setTimeout(function () {
                if (!root.isConnected) return;
                var endpoint = root.getAttribute("data-endpoint");
                var productId =
                    parseInt(root.getAttribute("data-product-id"), 10) || 0;
                if (!endpoint || !productId) return;
                var currentAttributeId = readProductAttributeId();
                var currentQuantity = quantity();
                var requestKey =
                    productId +
                    ":" +
                    currentAttributeId +
                    ":" +
                    currentQuantity;
                if (requestKey === lastRequestKey) return;
                lastRequestKey = requestKey;
                if (
                    refreshRequest &&
                    typeof refreshRequest.abort === "function"
                )
                    refreshRequest.abort();
                refreshRequest =
                    typeof AbortController === "function"
                        ? new AbortController()
                        : null;
                var sequence = ++refreshSequence;
                var url =
                    endpoint +
                    (endpoint.indexOf("?") === -1 ? "?" : "&") +
                    "id_product=" +
                    encodeURIComponent(productId) +
                    "&id_product_attribute=" +
                    encodeURIComponent(currentAttributeId) +
                    "&quantity=" +
                    encodeURIComponent(currentQuantity);
                var options = {
                    credentials: "same-origin",
                    headers: { "X-Requested-With": "XMLHttpRequest" },
                };
                if (refreshRequest) options.signal = refreshRequest.signal;
                fetch(url, options)
                    .then(function (response) {
                        if (!response.ok) throw new Error("calculator");
                        return response.json();
                    })
                    .then(function (payload) {
                        if (sequence === refreshSequence && root.isConnected)
                            root.unipaymentUpdate(
                                payload.success ? payload.calculator : null,
                            );
                    })
                    .catch(function (error) {
                        if (
                            sequence === refreshSequence &&
                            root.isConnected &&
                            (!error || error.name !== "AbortError")
                        ) {
                            lastRequestKey = "";
                            root.unipaymentUpdate(null);
                        }
                    });
            }, 80);
        };
    }

    function initialize() {
        document.querySelectorAll(selector).forEach(setup);
    }
    function refresh() {
        document.querySelectorAll(selector).forEach(function (root) {
            if (!root.isConnected) return;
            setup(root);
            root.unipaymentInvalidatePopup();
            root.unipaymentRefresh();
        });
    }
    function captureProductUpdateHint(payload) {
        if (payload && typeof payload === "object") {
            var hinted = parseInt(payload.id_product_attribute, 10);
            if (!isNaN(hinted) && hinted > 0) {
                pendingProductUpdateHint = {
                    id_product_attribute: hinted,
                };
                return;
            }
        }
    }
    function scheduleDomRefresh(payload) {
        captureProductUpdateHint(payload);
        window.clearTimeout(domRefreshTimer);
        // Defer one task so core replaceWith(.js-product-details / additional-info) can settle.
        domRefreshTimer = window.setTimeout(function () {
            refresh();
            pendingProductUpdateHint = null;
        }, 0);
    }
    function mutationNeedsRefresh(mutation) {
        var target =
            mutation.target && mutation.target.nodeType === 1
                ? mutation.target
                : mutation.target.parentElement;
        return !target || !target.closest(selector);
    }
    function initializeProductObservers() {
        var productActions = document.querySelector(
            ".product-actions, .js-product-actions",
        );
        if (!productActions || typeof MutationObserver !== "function") return;
        var observer = new MutationObserver(function (mutations) {
            if (mutations.some(mutationNeedsRefresh)) scheduleDomRefresh();
        });
        observer.observe(productActions, { childList: true, subtree: true });
    }
    function start() {
        initialize();
        initializeProductObservers();
    }
    if (document.readyState === "loading")
        document.addEventListener("DOMContentLoaded", start);
    else start();

    // Core emits updatedProduct AFTER product_details / additional_info replaceWith.
    // updatedProductCombination is only an updateProduct eventType, not a separate emit.
    if (window.prestashop && typeof window.prestashop.on === "function") {
        window.prestashop.on("updatedProduct", scheduleDomRefresh);
    }
    document.addEventListener("updatedProduct", function (event) {
        scheduleDomRefresh(event && event.detail ? event.detail : null);
    });
    document.addEventListener("input", function (event) {
        if (
            event.target &&
            event.target.matches(
                '#quantity_wanted, input[name="qty"], input[name="quantity"]',
            )
        )
            refresh();
    });
    document.addEventListener("change", function (event) {
        if (
            event.target &&
            event.target.matches(
                '#quantity_wanted, input[name="qty"], input[name="quantity"]',
            )
        )
            refresh();
    });
})();
