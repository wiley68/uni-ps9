<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertProductCalculatorController(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$controller = (string) file_get_contents($root . '/controllers/front/productcalculator.php');
$popup = (string) file_get_contents($root . '/controllers/front/productpopup.php');
$factory = (string) file_get_contents($root . '/src/Product/ProductContextFactory.php');

assertProductCalculatorController(
    strpos($controller, 'extends ModuleFrontController') !== false,
    'productcalculator must use ModuleFrontController'
);
assertProductCalculatorController(
    strpos($controller, 'function displayAjax') !== false,
    'productcalculator must expose displayAjax'
);
assertProductCalculatorController(
    strpos($controller, 'ProductContextFactory') !== false
        && strpos($controller, 'ProductCalculatorPresenter') !== false,
    'productcalculator must rebuild context server-side and present via domain'
);
assertProductCalculatorController(
    strpos($controller, 'getShopConfigurationService()->get()') !== false,
    'productcalculator must use ShopConfigurationService, not direct CP calls'
);
assertProductCalculatorController(
    strpos($controller, 'FILTER_VALIDATE_INT') !== false
        && strpos($controller, 'id_product') !== false
        && strpos($controller, 'id_product_attribute') !== false
        && strpos($controller, 'quantity') !== false,
    'productcalculator must validate product/attribute/quantity'
);
assertProductCalculatorController(
    strpos($controller, 'errorResponse(400') !== false
        && strpos($controller, 'errorResponse(422') !== false,
    'productcalculator must return safe error envelopes'
);
assertProductCalculatorController(
    strpos($controller, 'get_class($exception)') !== false
        && strpos($controller, '$exception->getMessage()') === false,
    'productcalculator logs must not expose exception messages to customers'
);

assertProductCalculatorController(
    strpos($popup, "REQUEST_METHOD'] !== 'POST'") !== false,
    'productpopup must reject non-POST'
);
assertProductCalculatorController(
    strpos($popup, 'hash_equals(Tools::getToken(false)') !== false,
    'productpopup must require CSRF token'
);
assertProductCalculatorController(
    strpos($popup, "\$action !== 'calculate'") !== false
        && strpos($popup, 'error(501') !== false,
    'non-calculate popup actions must degrade safely in Phase 6'
);
assertProductCalculatorController(
    strpos($popup, 'ProductPopupCalculator') !== false
        && strpos($popup, "'calculation' => \$calculation") !== false,
    'calculate action must return server-side calculation payload'
);

assertProductCalculatorController(
    strpos($factory, 'getPrice(true') !== false,
    'ProductContextFactory must use tax-included getPrice'
);
assertProductCalculatorController(
    strpos($factory, '$unitPrice * $quantity') !== false,
    'ProductContextFactory must multiply unit price by quantity (PS8 parity)'
);
assertProductCalculatorController(
    strpos($factory, 'attributeBelongsToProduct') !== false
        && strpos($factory, 'getCategories()') !== false,
    'factory must validate combinations and preserve categories'
);
assertProductCalculatorController(
    strpos($factory, 'id_manufacturer') === false
        && strpos($factory, 'manufacturer') === false,
    'Phase 5/6 ProductContext has no manufacturer field; do not invent one'
);

fwrite(STDOUT, "OK (Phase 6 productcalculator controller contract)\n");
