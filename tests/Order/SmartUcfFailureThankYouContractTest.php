<?php

declare(strict_types=1);

/**
 * Post-order SmartUCF failure must land on native order-confirmation
 * with a customer-safe financing notice (Woo thank-you parity).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Order\BankStatus;
use PrestaShop\Module\Unipayment\Order\BankStatusReaderPort;
use PrestaShop\Module\Unipayment\Order\OrderConfirmationSmartUcfFailurePresenter;
use PrestaShop\Module\Unipayment\Order\PostControlPanelLifecycleResult;

function assertSmartUcfFailureThankYou(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$checkout = (string) file_get_contents($root . '/controllers/front/validatecheckout.php');
$module = (string) file_get_contents($root . '/unipayment.php');
$urlBuilder = (string) file_get_contents($root . '/src/Order/OrderConfirmationUrlBuilder.php');
$presenterSource = (string) file_get_contents($root . '/src/Order/OrderLeasingDetailsPresenter.php');
$failurePresenterSource = (string) file_get_contents($root . '/src/Order/OrderConfirmationSmartUcfFailurePresenter.php');
$failureTemplate = (string) file_get_contents($root . '/views/templates/hook/order_confirmation_smartucf_failure.tpl');
$validatedTemplate = (string) file_get_contents($root . '/views/templates/front/checkout_validated.tpl');
$css = (string) file_get_contents($root . '/views/css/order-confirmation.css');

// Test A — checkout SmartUCF failed chooses native confirmation redirect
assertSmartUcfFailureThankYou(
    (bool) preg_match(
        '/if\s*\(\s*\$lifecycle->isFailed\(\)\s*\)\s*\{[^}]*OrderConfirmationUrlBuilder[^}]*return;/s',
        $checkout
    ),
    'A: isFailed() must redirect via OrderConfirmationUrlBuilder and return'
);
assertSmartUcfFailureThankYou(
    strpos($checkout, "checkout_validated.tpl") !== false,
    'A: checkout_validated.tpl must remain for non-failed intermediate states'
);

$failedPos = strpos($checkout, '$lifecycle->isFailed()');
$fallbackAssign = strpos($checkout, "\$this->context->smarty->assign(['unipayment_order_result' => \$orderResult]);");
assertSmartUcfFailureThankYou($failedPos !== false, 'A: isFailed() branch missing');
assertSmartUcfFailureThankYou(
    $fallbackAssign !== false && $failedPos < $fallbackAssign,
    'A: isFailed() must not fall through to checkout_validated.tpl'
);

// Test B — native URL uses OrderConfirmationUrlBuilder with order context
assertSmartUcfFailureThankYou(strpos($urlBuilder, "'order-confirmation'") !== false, 'B: URL must use native order-confirmation');
assertSmartUcfFailureThankYou(strpos($urlBuilder, "'id_cart'") !== false, 'B: confirmation URL must include id_cart');
assertSmartUcfFailureThankYou(strpos($urlBuilder, "'id_module'") !== false, 'B: confirmation URL must include id_module');
assertSmartUcfFailureThankYou(strpos($urlBuilder, "'id_order'") !== false, 'B: confirmation URL must include id_order');
assertSmartUcfFailureThankYou(strpos($urlBuilder, "'key'") !== false, 'B: confirmation URL must include secure key');
assertSmartUcfFailureThankYou(
    (bool) preg_match(
        '/\$lifecycle->isFailed\(\)[\s\S]*?OrderConfirmationUrlBuilder\(\)\)->build\(\s*\$this->context,\s*\$module,\s*\$result->idOrder\s*\)/',
        $checkout
    ),
    'B: failed redirect must build confirmation URL for the created order'
);

// Test C — hook/presenter detect persisted failed SmartUCF state
assertSmartUcfFailureThankYou(
    strpos($presenterSource, 'SENT_PROCESS2') !== false,
    'C: Process 2 thank-you rows stay gated to SENT_PROCESS2'
);
assertSmartUcfFailureThankYou(
    strpos($failurePresenterSource, 'SEND_FAILED_SMARTUCF') !== false,
    'C: dedicated presenter must use persisted SEND_FAILED_SMARTUCF'
);
assertSmartUcfFailureThankYou(
    strpos($module, 'OrderConfirmationFinancingOutcomePresenter') !== false
        || strpos($module, 'OrderConfirmationSmartUcfFailurePresenter') !== false,
    'C: displayPaymentReturn must consult persisted SmartUCF failure state'
);
assertSmartUcfFailureThankYou(
    strpos($module, 'order_confirmation_smartucf_failure.tpl') !== false,
    'C: native confirmation must render the SmartUCF failure template'
);
assertSmartUcfFailureThankYou(
    strpos($failureTemplate, "s='Поръчката е създадена'") !== false,
    'C: failure notice title missing'
);
assertSmartUcfFailureThankYou(
    strpos($failureTemplate, 'заявката за финансиране не беше приета/стартирана успешно') !== false,
    'C: failure notice must distinguish shop order from bank request'
);
assertSmartUcfFailureThankYou(
    strpos($failureTemplate, "s='Не изпращайте поръчката повторно.'") !== false,
    'C: failure notice must forbid checkout resubmit'
);
assertSmartUcfFailureThankYou(
    strpos($css, 'unipayment-thankyou-smartucf-failure') !== false,
    'C: failure notice CSS missing'
);

$failed = PostControlPanelLifecycleResult::smartUcfFailed(
    'Възникна грешка при обработката на заявката.',
    BankStatus::smartUcfFailure()
);
assertSmartUcfFailureThankYou($failed->isFailed(), 'C: lifecycle failed outcome');
assertSmartUcfFailureThankYou($failed->outcome() === PostControlPanelLifecycleResult::OUTCOME_SMARTUCF_FAILED, 'C: outcome constant');
assertSmartUcfFailureThankYou(
    ($failed->finalBankStatus()['status_id'] ?? '') === BankStatus::SEND_FAILED_SMARTUCF,
    'C: failed lifecycle carries persisted SmartUCF bank status'
);

$bankStub = new class() implements BankStatusReaderPort {
    /** @var array<int, array<string, mixed>|null> */
    public $rows = [];

    public function findByOrderId(int $idOrder): ?array
    {
        return $this->rows[$idOrder] ?? null;
    }
};
$bankStub->rows[76] = [
    'status_id' => BankStatus::SEND_FAILED_SMARTUCF,
    'status_label' => BankStatus::LABEL_SEND_FAILED_SMARTUCF,
];
$bankStub->rows[10] = [
    'status_id' => BankStatus::SENT_PROCESS1,
    'status_label' => BankStatus::LABEL_SENT_PROCESS1,
];
$bankStub->rows[20] = [
    'status_id' => BankStatus::SENT_PROCESS2,
    'status_label' => BankStatus::LABEL_SENT_PROCESS2,
];
$noticePresenter = new OrderConfirmationSmartUcfFailurePresenter($bankStub);
assertSmartUcfFailureThankYou($noticePresenter->shouldDisplay(76), 'C: failed bank status shows notice');
assertSmartUcfFailureThankYou(
    strpos($presenterSource, 'statusId !== BankStatus::SENT_PROCESS2') !== false
        || strpos($presenterSource, '!== BankStatus::SENT_PROCESS2') !== false,
    'C: failed order cannot render Process 2 leasing table'
);

// Test D — successful Process 1 keeps SmartUCF redirect and has no failure notice
$createdPos = strpos($checkout, '$lifecycle->isCreated()');
$redirectPos = strpos($checkout, '$lifecycle->redirectUrl()');
assertSmartUcfFailureThankYou(
    $createdPos !== false && $redirectPos !== false && $createdPos < $failedPos,
    'D: Process 1 created+redirect must remain before failed confirmation redirect'
);
assertSmartUcfFailureThankYou(
    (bool) preg_match(
        '/if\s*\(\s*\$lifecycle->isCreated\(\)\s*&&\s*\$lifecycle->redirectUrl\(\)\s*!==\s*\'\'\s*\)\s*\{[^}]*Tools::redirect\(\$lifecycle->redirectUrl\(\)\);/s',
        $checkout
    ),
    'D: successful Process 1 must still redirect to SmartUCF'
);
assertSmartUcfFailureThankYou(!$noticePresenter->shouldDisplay(10), 'D: Process 1 success status must not show failure notice');

// Test E — Process 2 native confirmation remains unchanged
$process2Pos = strpos($checkout, '$lifecycle->isProcess2()');
assertSmartUcfFailureThankYou(
    $process2Pos !== false && $process2Pos < $createdPos,
    'E: Process 2 confirmation redirect must remain first'
);
assertSmartUcfFailureThankYou(!$noticePresenter->shouldDisplay(20), 'E: Process 2 success status must not show SmartUCF failure notice');

// Test F — processing keeps intermediate page
assertSmartUcfFailureThankYou(
    (bool) preg_match(
        '/if\s*\(\s*\$lifecycle->isProcessing\(\)\s*\)\s*\{[\s\S]*?unipayment_smartucf_processing[\s\S]*?checkout_validated\.tpl[\s\S]*?return;/s',
        $checkout
    ),
    'F: processing must keep checkout_validated.tpl'
);
assertSmartUcfFailureThankYou(
    strpos($validatedTemplate, 'unipayment_smartucf_processing') !== false,
    'F: processing template branch missing'
);

// Test G — outcome unknown stays intermediate, not failed confirmation
assertSmartUcfFailureThankYou(
    (bool) preg_match(
        '/if\s*\(\s*\$lifecycle->isOutcomeUnknown\(\)\s*\)\s*\{[\s\S]*?unipayment_smartucf_outcome_unknown[\s\S]*?checkout_validated\.tpl[\s\S]*?return;/s',
        $checkout
    ),
    'G: outcome unknown must keep checkout_validated.tpl'
);
$unknown = PostControlPanelLifecycleResult::smartUcfOutcomeUnknown(
    'unknown',
    BankStatus::successfulSend(false)
);
assertSmartUcfFailureThankYou($unknown->isOutcomeUnknown() && !$unknown->isFailed(), 'G: outcome unknown is not failed');

// Test H / I — guest and logged confirmation share the same secure URL + order-bound notice
assertSmartUcfFailureThankYou(
    strpos($urlBuilder, 'secure_key') !== false || strpos($urlBuilder, '$order->secure_key') !== false,
    'H/I: confirmation URL must bind guest/logged access to order secure_key'
);
assertSmartUcfFailureThankYou(
    (bool) preg_match(
        '/if\s*\(\s*\$lifecycle->isFailed\(\)\s*\)\s*\{[^}]*OrderConfirmationUrlBuilder[^}]*return;/s',
        $checkout
    ) && !preg_match(
        '/if\s*\(\s*\$lifecycle->isFailed\(\)\s*\)\s*\{[^}]*cookie/s',
        $checkout
    ),
    'H/I: failed confirmation must not use an unbound cookie flag'
);
assertSmartUcfFailureThankYou(
    !preg_match('/isFailed\(\)[\s\S]*?Tools::redirect\([^)]*\?[^)]*error=/', $checkout),
    'H/I: failed redirect must not put error text in the query string'
);

// Test J — refresh safety: confirmation page does not re-enter orchestration
assertSmartUcfFailureThankYou(
    strpos($module, 'orchestrate(') === false,
    'J: order-confirmation hook must not re-run order orchestration'
);
assertSmartUcfFailureThankYou(
    strpos($module, 'SmartUcfSessionCoordinator') === false
        || (strpos($module, 'hookDisplayPaymentReturn') !== false
            && strpos(substr($module, (int) strpos($module, 'function hookDisplayPaymentReturn')), 'SmartUcfSessionCoordinator') === false),
    'J: displayPaymentReturn must not start SmartUCF'
);

// Test K — unrelated / non-failed orders show no failure notice
assertSmartUcfFailureThankYou(!$noticePresenter->shouldDisplay(0), 'K: invalid order id must not show notice');
assertSmartUcfFailureThankYou(!$noticePresenter->shouldDisplay(99), 'K: order without bank status must not show notice');
assertSmartUcfFailureThankYou(
    strpos($module, 'function hookDisplayPaymentReturn') !== false
        && strpos($module, "'displayPaymentReturn'") !== false,
    'K: notice is scoped to native payment-return on this module confirmation page'
);

// Test L — customer-safe content
$unsafeNeedles = [
    'Exception',
    'Throwable',
    '/api/v1',
    'Bearer',
    'sucfOnlineSessionStart',
    'stack trace',
    'payload',
    'token',
    'debug',
    'Try again',
    'Опитайте отново',
    'Поръчката за финансиране е създадена успешно',
];
foreach ($unsafeNeedles as $needle) {
    assertSmartUcfFailureThankYou(
        stripos($failureTemplate, $needle) === false,
        'L: failure notice must not expose "' . $needle . '"'
    );
}
assertSmartUcfFailureThankYou(
    strpos($failureTemplate, 'button') === false && strpos($failureTemplate, 'href') === false,
    'L: confirmation failure notice must not offer a retry control'
);

fwrite(STDOUT, "OK (SmartUCF failure native thank-you contract)\n");
