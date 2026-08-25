<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

/**
 * Hummingbird 2.0 combination lifecycle contract (empirical theme/core behavior).
 */
function assertHbLifecycle(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$js = (string) file_get_contents($root . '/views/js/product-calculator.js');
$hbDetails = (string) file_get_contents(
    '/var/www/presta9.avalonbg.com/themes/hummingbird/templates/catalog/_partials/product-details.tpl'
);
$classicDetails = (string) file_get_contents(
    '/var/www/presta9.avalonbg.com/themes/classic/templates/catalog/_partials/product-details.tpl'
);
$eventsMap = (string) file_get_contents(
    '/var/www/presta9.avalonbg.com/themes/hummingbird/src/js/constants/events-map.ts'
);

assertHbLifecycle(
    strpos($hbDetails, 'js-product-details') !== false
        && strpos($hbDetails, 'id="product-details"') === false,
    'Hummingbird product details use .js-product-details without #product-details'
);
assertHbLifecycle(
    strpos($classicDetails, 'id="product-details"') !== false,
    'Classic still exposes #product-details for regression'
);
assertHbLifecycle(
    strpos($eventsMap, "updatedProduct: 'updatedProduct'") !== false
        && strpos($eventsMap, 'updatedProductCombination') === false,
    'Hummingbird events map exposes updatedProduct, not a separate updatedProductCombination emit'
);
assertHbLifecycle(
    strpos($js, '.js-product-details[data-product]') !== false
        && strpos($js, '#product-details[data-product]') !== false,
    'productAttributeId must read both Classic and Hummingbird product-details nodes'
);
assertHbLifecycle(
    (bool) preg_match('/prestashop\.on\(\s*["\']updatedProduct["\']/', $js),
    'must subscribe to prestashop updatedProduct (post replaceWith)'
);
assertHbLifecycle(
    strpos($js, 'pendingProductUpdateHint') !== false
        && strpos($js, 'id_product_attribute') !== false,
    'must accept updatedProduct payload hint for combination id'
);
assertHbLifecycle(
    strpos($js, 'root.isConnected') !== false,
    'detached roots after additional_info replaceWith must not apply stale updates'
);
assertHbLifecycle(
    strpos($js, 'js-product-actions') !== false,
    'MutationObserver must cover Hummingbird .js-product-actions'
);
assertHbLifecycle(
    !preg_match('/prestashop\.on\(\s*["\']updatedProductCombination["\']/', $js),
    'must not register fictional prestashop.on(updatedProductCombination) emit'
);

fwrite(STDOUT, "OK (Phase 6 Hummingbird combination lifecycle contract)\n");
