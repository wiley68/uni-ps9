<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertProductFrontend(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$module = (string) file_get_contents($root . '/unipayment.php');
$js = (string) file_get_contents($root . '/views/js/product-calculator.js');
$css = (string) file_get_contents($root . '/views/css/product-calculator.css');
$template = (string) file_get_contents($root . '/views/templates/hook/product_calculator.tpl');
$calculatorController = (string) file_get_contents($root . '/controllers/front/productcalculator.php');
$popupController = (string) file_get_contents($root . '/controllers/front/productpopup.php');

assertProductFrontend(
    (bool) preg_match('/function\s+hookDisplayProductAdditionalInfo\b/', $module),
    'product hook must be registered via handler'
);
assertProductFrontend(
    (bool) preg_match('/function\s+hookActionFrontControllerSetMedia\b/', $module),
    'product asset hook must exist'
);
assertProductFrontend(
    strpos($module, "php_self !== 'product'") !== false
        || strpos($module, "php_self === 'product'") !== false,
    'assets must be gated to product controller'
);
assertProductFrontend(
    strpos($module, 'homepage-advertising') === false,
        'Phase 9 must not enqueue homepage FO assets'
);
assertProductFrontend(
    strpos($module, "php_self === 'cart'") !== false
        && strpos($module, 'cart-calculator') !== false,
    'Phase 8 must enqueue cart calculator assets on cart page'
);
assertProductFrontend(
    strpos($module, "php_self === 'order'") !== false
        && strpos($module, 'checkout-payment') !== false,
    'Phase 9 must enqueue checkout payment assets on order page'
);

assertProductFrontend(
    !preg_match('/\$\s*\(|\bjQuery\b|\$\.ajax\s*\(|\$\(document\)\.ready/', $js),
    'product JS must not depend on jQuery'
);
assertProductFrontend(strpos($js, 'fetch(') !== false, 'product refresh must use fetch()');
assertProductFrontend(strpos($js, 'AbortController') !== false, 'stale request protection must use AbortController');
assertProductFrontend(strpos($js, 'refreshSequence') !== false, 'stale request protection must use request sequence');
assertProductFrontend(
    strpos($js, 'dataset.unipaymentReady') !== false,
    'initialization must be idempotent via data-unipayment-ready'
);
assertProductFrontend(
    (bool) preg_match('/prestashop\.on\(\s*[\'"]updatedProduct[\'"]/', $js),
    'JS must subscribe to prestashop updatedProduct'
);
assertProductFrontend(
    strpos($js, '.js-product-details[data-product]') !== false,
    'JS must resolve Hummingbird .js-product-details combination state'
);
assertProductFrontend(
    strpos($js, '#product-details[data-product]') !== false,
    'JS must keep Classic #product-details combination state'
);
assertProductFrontend(
    strpos($template, 'data-unipayment-calculator') !== false,
    'template must expose stable module root data attribute'
);
assertProductFrontend(
    strpos($css, '[data-unipayment-calculator]') !== false,
    'CSS must be namespaced under module root'
);
assertProductFrontend(
    !preg_match('/^[ \t]*(?:button|input|\.modal|\.product-price)\b/m', $css),
    'CSS must not style bare global button/input/modal/product-price selectors'
);

assertProductFrontend(
    strpos($calculatorController, "'success' => true") !== false
        && strpos($calculatorController, "'calculator' => \$calculator") !== false,
    'productcalculator AJAX envelope must return success + calculator'
);
assertProductFrontend(
    strpos($popupController, "REQUEST_METHOD'] !== 'POST'") !== false
        && strpos($popupController, 'hash_equals') !== false
        && strpos($popupController, 'issue_submission_token') !== false
        && strpos($popupController, 'ProductPopupOperationGuard') !== false,
    'productpopup must be POST+token with Phase 7 identity actions'
);
assertProductFrontend(
    is_file($root . '/src/Product/PopupSubmissionRepository.php'),
    'popup submission persistence must exist'
);
assertProductFrontend(
    strpos($js, 'unipaymentInvalidatePopup') !== false
        && strpos($js, 'preselectOperationToken = ""') !== false
        && strpos($js, 'identity_accepted') !== false,
    'JS must reset stale popup identity and handle identity_accepted'
);

fwrite(STDOUT, "OK (Phase 7 product frontend contract)\n");
