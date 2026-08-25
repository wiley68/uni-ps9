'use strict';

var assert = require('assert');
var productCalculator = require('../../views/js/product-calculator.js');
var productAttributeId = productCalculator.productAttributeId;
var buttonInstallmentLabel = productCalculator.buttonInstallmentLabel;

function element(attributes, value) {
  return {
    value: value,
    getAttribute: function (name) { return attributes[name] || null; }
  };
}

function productDocument(dataProduct, hiddenValue) {
  return {
    querySelector: function (selector) {
      if (selector === '#product-details[data-product]') {
        return dataProduct === null ? null : element({ 'data-product': dataProduct });
      }
      if (selector === 'input[name="id_product_attribute"]') {
        return hiddenValue === null ? null : element({}, hiddenValue);
      }
      return null;
    }
  };
}

assert.strictEqual(productAttributeId(productDocument('{"id_product_attribute":42}', '7')), 42);
assert.strictEqual(productAttributeId(productDocument('{"id_product_attribute":0}', null)), 0);
assert.strictEqual(productAttributeId(productDocument('{malformed', null)), 0);
assert.strictEqual(productAttributeId(productDocument(null, '7')), 7);
assert.strictEqual(productAttributeId(productDocument('{malformed', '9')), 9);
assert.strictEqual(buttonInstallmentLabel({
  months: 12,
  monthly_installment: 97.49,
  installment_label: '12 x 97.49 евро'
}), '12 x 97.49 евро');
assert.strictEqual(buttonInstallmentLabel(null), '');

var popupCalculationIdentity = productCalculator.popupCalculationIdentity;
var popupCalculationSchemeFields = productCalculator.popupCalculationSchemeFields;
var secondaryActionUsesNativeAddToCart = productCalculator.secondaryActionUsesNativeAddToCart;
var resolveCheckoutRedirectUrl = productCalculator.resolveCheckoutRedirectUrl;
var previous = { scheme_key: 'standard|STD|12|0', scheme_type: 'standard', kop_code: 'STD', months: 12, filter_id: 0 };
var selected = { key: 'standard|STD|24|0', scheme_type: 'standard', kop_code: 'STD', months: 24, filter_id: 0 };
var calculateIdentity = popupCalculationIdentity('calculate', selected, previous);
var applyIdentity = popupCalculationIdentity('apply', selected, previous);
assert.strictEqual(popupCalculationSchemeFields(calculateIdentity, selected, 'standard').months, 24);
assert.strictEqual(popupCalculationSchemeFields(calculateIdentity, selected, 'standard').scheme_key, 'standard|STD|24|0');
assert.strictEqual(popupCalculationSchemeFields(applyIdentity, selected, 'standard').months, 12);
assert.strictEqual(popupCalculationSchemeFields(applyIdentity, selected, 'standard').scheme_key, 'standard|STD|12|0');

assert.strictEqual(secondaryActionUsesNativeAddToCart('add_to_cart'), true);
assert.strictEqual(secondaryActionUsesNativeAddToCart('buy'), false);
assert.strictEqual(
  resolveCheckoutRedirectUrl({ checkout_url: 'https://example.test/order' }, 'https://fallback.test/order'),
  'https://example.test/order'
);
assert.strictEqual(resolveCheckoutRedirectUrl({}, 'https://fallback.test/order'), 'https://fallback.test/order');

var createPreselectOperationToken = productCalculator.createPreselectOperationToken;
var attachPreselectOperationToken = productCalculator.attachPreselectOperationToken;
assert.match(createPreselectOperationToken(), /^[a-f0-9]{32}$/);
var preselectPayload = new URLSearchParams();
attachPreselectOperationToken(preselectPayload, 'preselect', 'abc123def4567890abc123def4567890');
assert.strictEqual(preselectPayload.get('preselect_operation_token'), 'abc123def4567890abc123def4567890');
attachPreselectOperationToken(preselectPayload, 'calculate', 'abc123def4567890abc123def4567890');
assert.strictEqual(preselectPayload.get('preselect_operation_token'), 'abc123def4567890abc123def4567890');
preselectPayload = new URLSearchParams();
attachPreselectOperationToken(preselectPayload, 'calculate', 'ignored');
assert.strictEqual(preselectPayload.get('preselect_operation_token'), null);

console.log('OK (Phase 6 product combination DOM source and Woo button label)');
