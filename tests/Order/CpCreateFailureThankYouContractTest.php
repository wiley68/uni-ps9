<?php

declare(strict_types=1);

/**
 * Post-order Control Panel create failure must persist Woo-parity status
 * and land on native order-confirmation (not checkout validation error).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Order\BankStatus;
use PrestaShop\Module\Unipayment\Order\BankStatusReaderPort;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotByOrderReaderPort;
use PrestaShop\Module\Unipayment\Order\OrderConfirmationFinancingOutcomePresenter;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrator;

function assertCpFailureThankYou(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$checkout = (string) file_get_contents($root . '/controllers/front/validatecheckout.php');
$module = (string) file_get_contents($root . '/unipayment.php');
$orchestrator = (string) file_get_contents($root . '/src/Order/OrderOrchestrator.php');
$exception = (string) file_get_contents($root . '/src/Order/OrderOrchestrationException.php');
$bankStatus = (string) file_get_contents($root . '/src/Order/BankStatus.php');
$cpTemplate = (string) file_get_contents($root . '/views/templates/hook/order_confirmation_cp_failure.tpl');
$unknownTemplate = (string) file_get_contents($root . '/views/templates/hook/order_confirmation_cp_outcome_unknown.tpl');
$smartucfTemplate = (string) file_get_contents($root . '/views/templates/hook/order_confirmation_smartucf_failure.tpl');
$errorTemplate = (string) file_get_contents($root . '/views/templates/front/checkout_validation_error.tpl');
$urlBuilder = (string) file_get_contents($root . '/src/Order/OrderConfirmationUrlBuilder.php');

$bankStub = new class () implements BankStatusReaderPort {
    /** @var array<int, array<string, mixed>|null> */
    public $rows = [];

    public function findByOrderId(int $idOrder): ?array
    {
        return $this->rows[$idOrder] ?? null;
    }
};
$snapshotStub = new class () implements FinancingSnapshotByOrderReaderPort {
    /** @var array<int, array<string, mixed>|null> */
    public $rows = [];

    public function findByOrderId(int $idOrder): ?array
    {
        return $this->rows[$idOrder] ?? null;
    }
};
$presenter = new OrderConfirmationFinancingOutcomePresenter($bankStub, $snapshotStub);

// Test A — HTTP 4xx / definitive CP failure
assertCpFailureThankYou(
    strpos($orchestrator, 'recordControlPanelFailure') !== false,
    'A: orchestrator must persist CP failure close to the transition'
);
assertCpFailureThankYou(
    (bool) preg_match(
        '/catch\s*\(\s*OrderOrchestrationException\s+\$exception\s*\)\s*\{[\s\S]*isPostOrder\(\)[\s\S]*OrderConfirmationUrlBuilder[\s\S]*return;/s',
        $checkout
    ),
    'A: post-order orchestration exception must redirect to native confirmation'
);
assertCpFailureThankYou(
    strpos($bankStatus, "SEND_FAILED_CP = 'bank_send_failed_cp'") !== false,
    'A: Woo bank_send_failed_cp constant missing'
);
assertCpFailureThankYou(
    BankStatus::controlPanelFailure(false)['status_id'] === BankStatus::SEND_FAILED_CP,
    'A: Process 1 CP failure id'
);
$bankStub->rows[40] = [
    'status_id' => BankStatus::SEND_FAILED_CP,
    'status_label' => BankStatus::LABEL_SEND_FAILED_CP,
];
$snapshotStub->rows[40] = ['lifecycle_status' => OrderOrchestrator::TERMINAL_FAILED];
assertCpFailureThankYou(
    $presenter->outcome(40) === OrderConfirmationFinancingOutcomePresenter::CP_FAILED,
    'A: presenter maps persisted CP failure'
);

// Test B — HTTP 5xx retryable is same-attempt, not customer Try again
assertCpFailureThankYou(
    strpos($orchestrator, 'CP_FAILED_RETRYABLE') !== false
        && strpos($orchestrator, 'getStatusCode() >= 500') !== false,
    'B: HTTP 5xx stays CP_FAILED_RETRYABLE on the same attempt'
);
assertCpFailureThankYou(
    strpos($cpTemplate, 'Опитайте отново') === false && strpos($cpTemplate, 'Try again') === false,
    'B: customer notice must not offer checkout retry'
);
$bankStub->rows[50] = [
    'status_id' => BankStatus::SEND_FAILED_CP,
    'status_label' => BankStatus::LABEL_SEND_FAILED_CP,
];
$snapshotStub->rows[50] = ['lifecycle_status' => OrderOrchestrator::CP_FAILED_RETRYABLE];
assertCpFailureThankYou(
    $presenter->outcome(50) === OrderConfirmationFinancingOutcomePresenter::CP_FAILED,
    'B: 5xx uses definitive CP-failure notice, not outcome-unknown'
);

// Test C — connection / outcome unknown
assertCpFailureThankYou(
    strpos($orchestrator, 'CP_OUTCOME_UNKNOWN') !== false
        && strpos($orchestrator, 'ConnectionException') !== false,
    'C: connection failure stays CP_OUTCOME_UNKNOWN'
);
$bankStub->rows[60] = [
    'status_id' => BankStatus::SEND_FAILED_CP,
    'status_label' => BankStatus::LABEL_SEND_FAILED_CP,
];
$snapshotStub->rows[60] = ['lifecycle_status' => OrderOrchestrator::CP_OUTCOME_UNKNOWN];
assertCpFailureThankYou(
    $presenter->outcome(60) === OrderConfirmationFinancingOutcomePresenter::CP_OUTCOME_UNKNOWN,
    'C: presenter distinguishes outcome unknown via snapshot lifecycle'
);
assertCpFailureThankYou(
    strpos($unknownTemplate, 'потвърждението за регистрацията на финансирането не беше получено') !== false,
    'C: unknown notice must not claim CP order is absent'
);

// Test D — CP success unchanged
assertCpFailureThankYou(
    strpos($orchestrator, "state' => self::CP_CREATED") !== false
        && strpos($checkout, '$lifecycle->isCreated()') !== false,
    'D: successful CP create path remains'
);
assertCpFailureThankYou(
    $presenter->outcome(1) === OrderConfirmationFinancingOutcomePresenter::NONE,
    'D: order without CP-failure status has no notice'
);

// Test E — SmartUCF failed notice unchanged
$bankStub->rows[76] = [
    'status_id' => BankStatus::SEND_FAILED_SMARTUCF,
    'status_label' => BankStatus::LABEL_SEND_FAILED_SMARTUCF,
];
assertCpFailureThankYou(
    $presenter->outcome(76) === OrderConfirmationFinancingOutcomePresenter::SMARTUCF_FAILED,
    'E: SmartUCF failed status still maps to SmartUCF notice'
);
assertCpFailureThankYou(
    strpos($module, 'order_confirmation_smartucf_failure.tpl') !== false,
    'E: SmartUCF failure template still wired'
);
assertCpFailureThankYou(
    strpos($smartucfTemplate, 'заявката за финансиране не беше приета/стартирана успешно') !== false,
    'E: SmartUCF customer wording unchanged'
);

// Test F — Process 2 success unchanged
assertCpFailureThankYou(
    strpos($checkout, '$lifecycle->isProcess2()') !== false
        && strpos($module, 'order_confirmation_leasing.tpl') !== false,
    'F: Process 2 native confirmation remains'
);
$bankStub->rows[20] = [
    'status_id' => BankStatus::SENT_PROCESS2,
    'status_label' => BankStatus::LABEL_SENT_PROCESS2,
];
assertCpFailureThankYou(
    $presenter->outcome(20) === OrderConfirmationFinancingOutcomePresenter::NONE,
    'F: Process 2 success must not show CP-failure notice'
);

// Test G — admin column reads persisted label
assertCpFailureThankYou(
    strpos($module, 'unipayment_bs.status_label') !== false,
    'G: admin grid still reads persisted UniCredit status'
);
assertCpFailureThankYou(
    BankStatus::LABEL_SEND_FAILED_CP === 'Неуспешно изпратен Банка - КП',
    'G: admin label must match Woo Control Panel failure'
);

// Test H / I — guest and logged share secure confirmation URL
assertCpFailureThankYou(
    strpos($urlBuilder, "'id_cart'") !== false
        && strpos($urlBuilder, "'id_module'") !== false
        && strpos($urlBuilder, "'id_order'") !== false
        && strpos($urlBuilder, '$order->secure_key') !== false,
    'H/I: confirmation URL binds guest/logged access to order secure_key'
);
assertCpFailureThankYou(
    strpos($exception, 'function idOrder') !== false && strpos($exception, 'function isPostOrder') !== false,
    'H/I: redirect uses exception order context, not a public ID lookup'
);

// Test J — refresh safety
assertCpFailureThankYou(
    strpos($module, 'orchestrate(') === false,
    'J: confirmation hook must not re-run orchestration'
);
assertCpFailureThankYou(
    strpos($module, 'function hookDisplayPaymentReturn') !== false
        && strpos(substr($module, (int) strpos($module, 'function hookDisplayPaymentReturn')), 'createOrder(') === false,
    'J: displayPaymentReturn must not resubmit CP create'
);

// Test K — customer-safe content
$unsafe = [
    'Control Panel',
    'Exception',
    '/api/v1',
    'HTTP 404',
    'Bearer',
    'payload',
    'token',
    'debug',
    'Try again',
    'Опитайте отново',
    'Обратно към checkout',
];
foreach ([$cpTemplate, $unknownTemplate] as $template) {
    foreach ($unsafe as $needle) {
        assertCpFailureThankYou(
            stripos($template, $needle) === false,
            'K: notice must not expose "' . $needle . '"'
        );
    }
}
assertCpFailureThankYou(
    strpos($errorTemplate, 'Обратно към checkout') !== false,
    'K: Back to checkout remains only on pre-order validation errors'
);

// Test L — no false bank/SmartUCF labels
assertCpFailureThankYou(
    strpos($cpTemplate, 'SmartUCF') === false
        && strpos($cpTemplate, 'Изпратен Банка') === false
        && strpos($unknownTemplate, 'SmartUCF') === false,
    'L: CP failure notice must not use bank-sent or SmartUCF wording'
);
assertCpFailureThankYou(
    strpos($orchestrator, 'DeferredOrderMailQueue::discard()') !== false,
    'L: Process 1 deferred order_conf must be discarded on CP create failure'
);
assertCpFailureThankYou(
    BankStatus::SEND_FAILED_CP !== BankStatus::SEND_FAILED_SMARTUCF,
    'L: CP failure status must not reuse SmartUCF status id'
);
assertCpFailureThankYou(
    !in_array($presenter->outcome(40), [
        OrderConfirmationFinancingOutcomePresenter::SMARTUCF_FAILED,
        OrderConfirmationFinancingOutcomePresenter::NONE,
    ], true),
    'L: CP failed order is not labelled SmartUCF failed'
);

fwrite(STDOUT, "OK (CP create-order failure native thank-you contract)\n");
