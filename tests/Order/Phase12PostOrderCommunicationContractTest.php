<?php

declare(strict_types=1);

/**
 * Phase 12 mail + confirmation + BO wiring contracts.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertPhase12(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$lifecycle = (string) file_get_contents($root . '/src/Order/PostControlPanelLifecycleService.php');
$dispatcher = (string) file_get_contents($root . '/src/Order/FinancingOrderMailDispatcher.php');
$notifier = (string) file_get_contents($root . '/src/Order/LeasingEmailNotifier.php');
$presenter = (string) file_get_contents($root . '/src/Order/LeasingOrderEmailPresenter.php');
$details = (string) file_get_contents($root . '/src/Order/OrderLeasingDetailsPresenter.php');
$module = (string) file_get_contents($root . '/unipayment.php');
$callback = (string) file_get_contents($root . '/controllers/front/orderbankstatus.php');

assertPhase12(
    strpos($lifecycle, 'new FinancingOrderMailDispatcher()') !== false,
    'default mail dispatcher must be FinancingOrderMailDispatcher'
);
assertPhase12(
    (bool) preg_match(
        '/if\s*\(\s*\$process2\s*\)\s*\{[\s\S]*persistBankStatus[\s\S]*if\s*\(\s*\$context->sendLeasingEmail\s*\)/s',
        $lifecycle
    ),
    'Process 2 bank status must persist independently of sendLeasingEmail'
);
assertPhase12(
    strpos($dispatcher, 'DeferredOrderMailQueue::flush') !== false
        && strpos($dispatcher, 'notifier->notify') !== false,
    'FinancingOrderMailDispatcher must flush order_conf then notify leasing audiences'
);
assertPhase12(
    strpos($notifier, 'leasing_email_sent') !== false,
    'LeasingEmailNotifier must gate on leasing_email_sent'
);
assertPhase12(
    strpos($presenter, 'EmailAudience::CUSTOMER') !== false
        && strpos($presenter, 'EmailAudience::ADMIN') !== false,
    'presenter must distinguish customer/admin audiences'
);
assertPhase12(
    (bool) preg_match('/process2 && \$audience === EmailAudience::ADMIN[\s\S]*ЕГН/s', $presenter),
    'Process 2 admin may include EGN'
);
assertPhase12(
    strpos($details, 'customerRowsFromSnapshot') !== false
        && strpos($details, 'function thankYouRows') !== false,
    'thank-you rows must use customer audience (no EGN)'
);
assertPhase12(
    strpos($details, 'Control Panel order ID') !== false
        && strpos($details, 'SmartUCF state') !== false,
    'BO presenter must expose CP id and SmartUCF diagnostics'
);
assertPhase12(
    (bool) preg_match('/registerHook\s*\(\s*[\'"]displayPaymentReturn[\'"]\s*\)/', $module)
        && (bool) preg_match('/function\s+hookDisplayPaymentReturn\b/', $module),
    'displayPaymentReturn must be registered and implemented'
);
assertPhase12(
    (bool) preg_match('/registerHook\s*\(\s*[\'"]displayAdminOrderMainBottom[\'"]\s*\)/', $module)
        && (bool) preg_match('/function\s+hookDisplayAdminOrderMainBottom\b/', $module),
    'displayAdminOrderMainBottom must be registered and implemented'
);
assertPhase12(
    (bool) preg_match('/registerHook\s*\(\s*[\'"]sendMailAlterTemplateVars[\'"]\s*\)/', $module)
        && (bool) preg_match('/function\s+hookSendMailAlterTemplateVars\b/', $module),
    'sendMailAlterTemplateVars must be registered and implemented'
);
assertPhase12(
    (bool) preg_match('/registerHook\s*\(\s*[\'"]actionOrderGridDefinitionModifier[\'"]\s*\)/', $module)
        && strpos($module, 'unipayment_bs.status_label') !== false,
    'admin order grid bank-status column must be registered'
);
assertPhase12(
    strpos($module, "php_self === 'order-confirmation'") !== false
        && is_file($root . '/views/css/order-confirmation.css'),
    'order-confirmation CSS must load'
);
assertPhase12(
    is_file($root . '/mails/bg/ordersend.html')
        && is_file($root . '/mails/bg/ordersend.txt')
        && is_file($root . '/mails/en/ordersend.html')
        && is_file($root . '/mails/en/ordersend.txt'),
    'bg/en ordersend mail templates required'
);
assertPhase12(
    strpos($callback, 'BankStatusOrderStateMapper') === false
        && strpos($callback, "['ps_order_state_changed'] = false") !== false,
    'AUD-009: callback must not sync native PS order state'
);
assertPhase12(
    is_file($root . '/src/Order/BankStatusOrderStateMapper.php')
        && is_file($root . '/src/Order/BankStatusRejectionPolicy.php'),
    'rejection mapper/policy classes remain available but unwired (AUD-009)'
);

fwrite(STDOUT, "OK (Phase 12 mail/confirmation/BO wiring)\n");
