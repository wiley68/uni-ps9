<?php

declare(strict_types=1);

/**
 * Product Process 1 terminal SmartUCF failure must redirect to Thank You,
 * not show a generic popup AJAX error — checkout parity for presentation only.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Order\BankStatus;
use PrestaShop\Module\Unipayment\Order\PostControlPanelLifecyclePopupMapper;
use PrestaShop\Module\Unipayment\Order\PostControlPanelLifecycleResult;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfEndpointPolicy;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfSessionCoordinator;

function assertProductTerminalTy(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$product = (string) file_get_contents($root . '/controllers/front/productpopup.php');
$checkout = (string) file_get_contents($root . '/controllers/front/validatecheckout.php');
$cart = (string) file_get_contents($root . '/controllers/front/cartpopup.php');
$js = (string) file_get_contents($root . '/views/js/product-calculator.js');
$mapper = (string) file_get_contents($root . '/src/Order/PostControlPanelLifecyclePopupMapper.php');
$urlBuilder = (string) file_get_contents($root . '/src/Order/OrderConfirmationUrlBuilder.php');
$failureTpl = (string) file_get_contents($root . '/views/templates/hook/order_confirmation_smartucf_failure.tpl');

// --- A. Product wires terminal failure → OrderConfirmationUrlBuilder ---
assertProductTerminalTy(
    strpos($product, 'applyTerminalSmartUcfFailureThankYouRedirect') !== false,
    'A: Product must apply terminal SmartUCF failure Thank You redirect'
);
assertProductTerminalTy(
    substr_count($product, 'applyTerminalSmartUcfFailureThankYouRedirect') >= 3,
    'A: helper must be defined and used on apply + replay paths'
);
assertProductTerminalTy(
    (bool) preg_match(
        '/SEND_FAILED_SMARTUCF[\s\S]*?OrderConfirmationUrlBuilder\(\)\)->build\(/s',
        $product
    ),
    'A: terminal bank_send_failed_smartucf must build Thank You via OrderConfirmationUrlBuilder'
);
assertProductTerminalTy(
    strpos($product, "unset(\$response['smartucf_error']") !== false
        || strpos($product, 'unset($response[\'smartucf_error\']') !== false,
    'A: terminal failure must clear smartucf_error so JS does not show generic popup'
);

// --- B. JS prefers redirect_url over smartucf_error ---
$redirectPos = strpos($js, 'if (body.redirect_url)');
$errorPos = strpos($js, 'if (body.cp_error || body.smartucf_error)');
assertProductTerminalTy($redirectPos !== false && $errorPos !== false, 'B: JS branches present');
assertProductTerminalTy($redirectPos < $errorPos, 'B: redirect_url must win over smartucf_error');
assertProductTerminalTy(
    strpos($js, 'window.location.assign(body.redirect_url)') !== false,
    'B: JS assigns server Thank You / SmartUCF URL'
);

// --- C. Simulated Product response: terminal failure → Thank You fields ---
$failedLifecycle = PostControlPanelLifecycleResult::smartUcfFailed(
    SmartUcfSessionCoordinator::CUSTOMER_FAILED,
    BankStatus::smartUcfFailure()
);
$responseTerminal = [
    'success' => true,
    'step' => 'order_created',
    'order' => [
        'id_order' => 121,
        'order_reference' => 'TERMFAIL01',
        'control_panel_order_id' => 400,
    ],
];
PostControlPanelLifecyclePopupMapper::apply($responseTerminal, $failedLifecycle);
assertProductTerminalTy(isset($responseTerminal['smartucf_error']), 'C: mapper still emits smartucf_error (raw)');
assertProductTerminalTy(
    ($failedLifecycle->finalBankStatus()['status_id'] ?? '') === BankStatus::SEND_FAILED_SMARTUCF,
    'C: backend terminal status remains bank_send_failed_smartucf'
);

// Presentation layer (product helper contract): after terminal remap
$responseTerminal['redirect_url'] = 'https://shop.example/order-confirmation?id_order=121&key=sec';
unset($responseTerminal['smartucf_error']);
$responseTerminal['step'] = 'order_created';
assertProductTerminalTy(
    ($responseTerminal['redirect_url'] ?? '') !== ''
        && !isset($responseTerminal['smartucf_error'])
        && ($responseTerminal['success'] ?? false) === true,
    'C: corrected Product payload has Thank You redirect_url and no smartucf_error'
);
assertProductTerminalTy(
    strpos((string) $responseTerminal['redirect_url'], 'order-confirmation') !== false,
    'C: redirect targets native order-confirmation'
);

// --- D. Normal Process 1 success stays SmartUCF redirect ---
$trusted = (new SmartUcfEndpointPolicy())->buildApplicationRedirect(
    'https://online.ucfin.bg/sucf-online/Request/Start',
    'SESSION-OK'
);
$created = PostControlPanelLifecycleResult::smartUcfCreated($trusted, BankStatus::successfulSend(false));
$responseOk = ['success' => true, 'step' => 'order_created'];
PostControlPanelLifecyclePopupMapper::apply($responseOk, $created);
assertProductTerminalTy(
    ($responseOk['redirect_url'] ?? '') === $trusted,
    'D: success keeps SmartUCF redirect_url'
);
assertProductTerminalTy(!isset($responseOk['smartucf_error']), 'D: success has no smartucf_error');
assertProductTerminalTy(
    strpos((string) $responseOk['redirect_url'], 'sucf-online') !== false
        || strpos((string) $responseOk['redirect_url'], 'ucfin') !== false,
    'D: success URL is SmartUCF application, not Thank You'
);

// --- E. Pre-send / non-terminal failure keeps generic error (no Thank You remap) ---
$preSend = PostControlPanelLifecycleResult::smartUcfFailed(
    SmartUcfSessionCoordinator::CUSTOMER_FAILED,
    null
);
$responsePre = ['success' => true, 'step' => 'order_created'];
PostControlPanelLifecyclePopupMapper::apply($responsePre, $preSend);
assertProductTerminalTy(isset($responsePre['smartucf_error']), 'E: pre-send still maps smartucf_error');
assertProductTerminalTy(!isset($responsePre['redirect_url']), 'E: pre-send has no Thank You redirect from mapper');
assertProductTerminalTy(
    strpos($product, "\$status === null || (\$status['status_id'] ?? '') !== BankStatus::SEND_FAILED_SMARTUCF") !== false
        || strpos($product, 'BankStatus::SEND_FAILED_SMARTUCF') !== false,
    'E: Product Thank You remap requires persisted SEND_FAILED_SMARTUCF bank status'
);
assertProductTerminalTy(
    strpos($product, '$lifecycle->finalBankStatus()') !== false,
    'E: remap gates on finalBankStatus (null = pre-send keeps error UX)'
);

// --- F. Backend / checkout contracts unchanged ---
assertProductTerminalTy(
    (bool) preg_match(
        '/if\s*\(\s*\$lifecycle->isFailed\(\)\s*\)\s*\{[^}]*OrderConfirmationUrlBuilder/s',
        $checkout
    ),
    'F: checkout terminal failure Thank You remains'
);
assertProductTerminalTy(
    strpos($mapper, "['smartucf_error']") !== false,
    'F: mapper still can emit smartucf_error for non-remapped cases'
);
assertProductTerminalTy(strpos($urlBuilder, "'order-confirmation'") !== false, 'F: URL builder unchanged');
assertProductTerminalTy(
    strpos($failureTpl, 'заявката за финансиране не беше приета/стартирана успешно') !== false,
    'F: Thank You customer failure notice unchanged'
);
assertProductTerminalTy(
    stripos($failureTpl, 'Exception') === false
        && stripos($failureTpl, 'smartucf_state') === false
        && stripos($failureTpl, 'transport') === false,
    'F: Thank You notice exposes no internal diagnostics'
);

// --- G. Process 2 Product path still uses confirmation builder ---
assertProductTerminalTy(
    strpos($product, 'ShopConfigurationFlags::isProcess2') !== false
        && strpos($product, 'OrderConfirmationUrlBuilder') !== false,
    'G: Process 2 Product Thank You path remains'
);

// --- H. Cart not required for this Product-only UX fix ---
assertProductTerminalTy(
    strpos($cart, 'applyTerminalSmartUcfFailureThankYouRedirect') === false,
    'H: Cart popup left untouched by this Product presentation fix'
);

fwrite(STDOUT, "OK (Product terminal SmartUCF failure Thank You UX)\n");
