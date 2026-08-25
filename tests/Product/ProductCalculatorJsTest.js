"use strict";

var assert = require("assert");
var productCalculator = require("../../views/js/product-calculator.js");
var productAttributeId = productCalculator.productAttributeId;
var buttonInstallmentLabel = productCalculator.buttonInstallmentLabel;

function element(attributes, value) {
    return {
        value: value,
        getAttribute: function (name) {
            return attributes[name] || null;
        },
    };
}

/**
 * Models Classic (#product-details) and Hummingbird (.js-product-details) product state sources.
 */
function productDocument(options) {
    var classicJson = options.classicJson;
    var hummingbirdJson = options.hummingbirdJson;
    var hiddenValue = options.hiddenValue;
    return {
        querySelector: function (selector) {
            if (
                selector.indexOf("#product-details[data-product]") !== -1 &&
                classicJson != null
            ) {
                return element({ "data-product": classicJson });
            }
            if (
                selector.indexOf(".js-product-details[data-product]") !== -1 &&
                hummingbirdJson != null
            ) {
                return element({ "data-product": hummingbirdJson });
            }
            // Combined selector used in production: first match wins in real DOM; mock prefers Classic node when set.
            if (
                selector ===
                "#product-details[data-product], .js-product-details[data-product]"
            ) {
                if (classicJson != null) {
                    return element({ "data-product": classicJson });
                }
                if (hummingbirdJson != null) {
                    return element({ "data-product": hummingbirdJson });
                }
                return null;
            }
            if (
                selector.indexOf('input[name="id_product_attribute"]') !== -1 &&
                hiddenValue != null
            ) {
                return element({}, hiddenValue);
            }
            return null;
        },
    };
}

// Classic: #product-details preferred over hidden field.
assert.strictEqual(
    productAttributeId(
        productDocument({
            classicJson: '{"id_product_attribute":42}',
            hiddenValue: "7",
        }),
    ),
    42,
);
assert.strictEqual(
    productAttributeId(
        productDocument({
            classicJson: '{"id_product_attribute":0}',
            hiddenValue: null,
        }),
    ),
    0,
);
assert.strictEqual(
    productAttributeId(
        productDocument({ classicJson: "{malformed", hiddenValue: null }),
    ),
    0,
);
assert.strictEqual(
    productAttributeId(
        productDocument({ classicJson: null, hiddenValue: "7" }),
    ),
    7,
);
assert.strictEqual(
    productAttributeId(
        productDocument({ classicJson: "{malformed", hiddenValue: "9" }),
    ),
    9,
);

// Hummingbird: only .js-product-details (no #product-details, often no hidden input).
assert.strictEqual(
    productAttributeId(
        productDocument({
            classicJson: null,
            hummingbirdJson: '{"id_product_attribute":55}',
            hiddenValue: null,
        }),
    ),
    55,
);

// A→B then B→A: final read must use latest Hummingbird state (not stale A).
assert.strictEqual(
    productAttributeId(
        productDocument({
            classicJson: null,
            hummingbirdJson: '{"id_product_attribute":11}',
            hiddenValue: null,
        }),
    ),
    11,
);
assert.strictEqual(
    productAttributeId(
        productDocument({
            classicJson: null,
            hummingbirdJson: '{"id_product_attribute":22}',
            hiddenValue: null,
        }),
    ),
    22,
);
assert.strictEqual(
    productAttributeId(
        productDocument({
            classicJson: null,
            hummingbirdJson: '{"id_product_attribute":11}',
            hiddenValue: null,
        }),
    ),
    11,
);

// updatedProduct payload hint wins when DOM has not settled yet (stale A in DOM, hint B).
assert.strictEqual(
    productAttributeId(
        productDocument({
            classicJson: null,
            hummingbirdJson: '{"id_product_attribute":11}',
            hiddenValue: null,
        }),
        { id_product_attribute: 22 },
    ),
    22,
);

// Dedup-relevant: same combination id from multiple notifications stays stable.
assert.strictEqual(
    productAttributeId(
        productDocument({ hummingbirdJson: '{"id_product_attribute":22}' }),
        { id_product_attribute: 22 },
    ),
    22,
);

assert.strictEqual(
    buttonInstallmentLabel({
        months: 12,
        monthly_installment: 97.49,
        installment_label: "12 x 97.49 евро",
    }),
    "12 x 97.49 евро",
);
assert.strictEqual(buttonInstallmentLabel(null), "");

var popupCalculationIdentity = productCalculator.popupCalculationIdentity;
var popupCalculationSchemeFields =
    productCalculator.popupCalculationSchemeFields;
var secondaryActionUsesNativeAddToCart =
    productCalculator.secondaryActionUsesNativeAddToCart;
var resolveCheckoutRedirectUrl = productCalculator.resolveCheckoutRedirectUrl;
var previous = {
    scheme_key: "standard|STD|12|0",
    scheme_type: "standard",
    kop_code: "STD",
    months: 12,
    filter_id: 0,
};
var selected = {
    key: "standard|STD|24|0",
    scheme_type: "standard",
    kop_code: "STD",
    months: 24,
    filter_id: 0,
};
var calculateIdentity = popupCalculationIdentity(
    "calculate",
    selected,
    previous,
);
var applyIdentity = popupCalculationIdentity("apply", selected, previous);
assert.strictEqual(
    popupCalculationSchemeFields(calculateIdentity, selected, "standard")
        .months,
    24,
);
assert.strictEqual(
    popupCalculationSchemeFields(calculateIdentity, selected, "standard")
        .scheme_key,
    "standard|STD|24|0",
);
assert.strictEqual(
    popupCalculationSchemeFields(applyIdentity, selected, "standard").months,
    12,
);
assert.strictEqual(
    popupCalculationSchemeFields(applyIdentity, selected, "standard")
        .scheme_key,
    "standard|STD|12|0",
);

assert.strictEqual(secondaryActionUsesNativeAddToCart("add_to_cart"), true);
assert.strictEqual(secondaryActionUsesNativeAddToCart("buy"), false);
assert.strictEqual(
    resolveCheckoutRedirectUrl(
        { checkout_url: "https://example.test/order" },
        "https://fallback.test/order",
    ),
    "https://example.test/order",
);
assert.strictEqual(
    resolveCheckoutRedirectUrl({}, "https://fallback.test/order"),
    "https://fallback.test/order",
);

var createPreselectOperationToken =
    productCalculator.createPreselectOperationToken;
var attachPreselectOperationToken =
    productCalculator.attachPreselectOperationToken;
assert.match(createPreselectOperationToken(), /^[a-f0-9]{32}$/);
var preselectPayload = new URLSearchParams();
attachPreselectOperationToken(
    preselectPayload,
    "preselect",
    "abc123def4567890abc123def4567890",
);
assert.strictEqual(
    preselectPayload.get("preselect_operation_token"),
    "abc123def4567890abc123def4567890",
);
attachPreselectOperationToken(
    preselectPayload,
    "calculate",
    "abc123def4567890abc123def4567890",
);
assert.strictEqual(
    preselectPayload.get("preselect_operation_token"),
    "abc123def4567890abc123def4567890",
);
preselectPayload = new URLSearchParams();
attachPreselectOperationToken(preselectPayload, "calculate", "ignored");
assert.strictEqual(preselectPayload.get("preselect_operation_token"), null);

console.log(
    "OK (Phase 6 Hummingbird combination DOM source + Classic regression)",
);
