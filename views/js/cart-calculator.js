(function () {
    "use strict";

    /** Cart page refresh helper — popup UI/flow is handled by product-calculator.js. */
    var selector = "[data-unipayment-cart-calculator]";
    var refreshRequest = null;
    var refreshSequence = 0;

    function refresh() {
        var root = document.querySelector(selector);
        if (!root || !root.isConnected) {
            return;
        }
        if (typeof root.unipaymentInvalidatePopup === "function") {
            root.unipaymentInvalidatePopup();
        }
        var endpoint = root.getAttribute("data-endpoint");
        if (!endpoint) {
            return;
        }
        if (refreshRequest && typeof refreshRequest.abort === "function") {
            refreshRequest.abort();
        }
        refreshRequest =
            typeof AbortController === "function" ? new AbortController() : null;
        var sequence = ++refreshSequence;
        var options = {
            credentials: "same-origin",
            headers: { "X-Requested-With": "XMLHttpRequest" },
        };
        if (refreshRequest) {
            options.signal = refreshRequest.signal;
        }
        fetch(endpoint, options)
            .then(function (response) {
                return response.json();
            })
            .then(function (payload) {
                if (sequence !== refreshSequence || !root.isConnected) {
                    return;
                }
                var next = payload.success ? payload.calculator : null;
                if (typeof root.unipaymentUpdate === "function") {
                    root.unipaymentUpdate(next);
                    return;
                }
                root.hidden = !next;
            })
            .catch(function (error) {
                if (
                    sequence !== refreshSequence ||
                    !root.isConnected ||
                    (error && error.name === "AbortError")
                ) {
                    return;
                }
                if (typeof root.unipaymentUpdate === "function") {
                    root.unipaymentUpdate(null);
                } else {
                    root.hidden = true;
                }
            });
    }

    // Core: emit updateCart → AJAX cart refresh → emit updatedCart.
    // Hummingbird 2.0 + Classic 3.1.1 both use themes/core.js + theme cart handlers.
    if (window.prestashop && typeof window.prestashop.on === "function") {
        window.prestashop.on("updatedCart", refresh);
    }
})();
