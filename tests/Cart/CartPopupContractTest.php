<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertCartPopupContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$template = (string) file_get_contents($root . '/views/templates/hook/cart_calculator.tpl');
$css = (string) file_get_contents($root . '/views/css/cart-calculator.css');
$module = (string) file_get_contents($root . '/unipayment.php');
$jsProduct = (string) file_get_contents($root . '/views/js/product-calculator.js');
$jsCart = (string) file_get_contents($root . '/views/js/cart-calculator.js');
$controller = (string) file_get_contents($root . '/controllers/front/cartpopup.php');
$hash = (string) file_get_contents($root . '/src/Product/PopupSubmissionSelectionHash.php');
$binding = (string) file_get_contents($root . '/src/Product/PopupSubmissionBindingFactory.php');

assertCartPopupContract(strpos($template, 'data-unipayment-source="cart"') !== false, 'cart root must declare source=cart');
assertCartPopupContract(strpos($template, 'data-unipayment-calculator') !== false, 'cart must reuse product popup JS root');
assertCartPopupContract(strpos($template, "s='Цена на количката'") !== false, 'cart popup must label cart total');
assertCartPopupContract(strpos($template, 'data-unipayment-apply') !== false, 'cart Step 1 must expose Apply');
assertCartPopupContract(strpos($template, 'data-unipayment-submit') !== false, 'cart Step 2 must expose Submit');
assertCartPopupContract(strpos($template, 'data-unipayment-customer-form') !== false, 'cart popup must include customer Step 2');
assertCartPopupContract(substr_count($template, 'data-unipayment-secondary') === 1, 'cart must keep a hidden secondary stub only');
assertCartPopupContract(strpos($template, 'data-hide-secondary="1"') !== false, 'cart must hide add-to-cart secondary action');
assertCartPopupContract(strpos($css, 'data-unipayment-source="cart"') !== false, 'cart CSS must hide secondary for cart source');
assertCartPopupContract(strpos($module, 'cartpopup') !== false, 'module must wire cartpopup endpoint');
assertCartPopupContract(strpos($module, 'product-calculator.js') !== false && strpos($module, "php_self === 'cart'") !== false, 'cart page must enqueue product popup JS');
assertCartPopupContract(strpos($jsProduct, 'isCartSource') !== false, 'product popup JS must detect cart source');
assertCartPopupContract(strpos($jsProduct, 'issue_submission_token') !== false, 'cart/product popup JS must issue submission tokens');
assertCartPopupContract(strpos($controller, 'PopupSubmissionRepository') !== false, 'cartpopup must reuse Phase 7 submission repository');
assertCartPopupContract(strpos($controller, 'claimForProcessing') !== false, 'cartpopup must claim submission for processing');
assertCartPopupContract(
    strpos($controller, 'PopupSubmissionPostOrderBinder') !== false
        || strpos($controller, 'markOrderCreated') !== false,
    'cart apply must reach order_created'
);
assertCartPopupContract(strpos($controller, 'OrderOrchestrator') !== false, 'cartpopup must create durable orders via orchestrator');
assertCartPopupContract(strpos($controller, 'CartPopupApplyService') !== false, 'cartpopup must use CartPopupApplyService');
assertCartPopupContract(strpos($controller, 'PostControlPanelLifecycleService') !== false, 'cartpopup must run post-CP lifecycle');
assertCartPopupContract(strpos($controller, 'fromCartSelection') !== false, 'cartpopup must bind cart selection hash');
assertCartPopupContract(strpos($hash, 'FLOW_CART_POPUP') !== false, 'selection hash must support cart_popup flow');
assertCartPopupContract(strpos($binding, 'fromCartSelection') !== false, 'binding factory must expose cart selection');
assertCartPopupContract(strpos($jsCart, 'updatedCart') !== false, 'cart refresh must listen to updatedCart');
assertCartPopupContract(strpos($jsCart, 'AbortController') !== false, 'cart refresh must abort stale requests');
assertCartPopupContract(!preg_match('/\$\s*\(|\bjQuery\b/', $jsCart), 'cart JS must not depend on jQuery');

fwrite(STDOUT, "OK (cart popup full-order contract)\n");
