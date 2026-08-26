<?php

declare(strict_types=1);

/**
 * Process 2 must not inject "Изпратен Банка - Процес 2" into native order_conf
 * at PS order creation, because CP POST /orders has not succeeded yet.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (!defined('_NEW_COOKIE_KEY_')) {
    define('_NEW_COOKIE_KEY_', 'process2-order-conf-test-key');
}

if (!class_exists('PhpEncryption', false)) {
    final class PhpEncryption
    {
        public function __construct(string $key)
        {
            unset($key);
        }

        public function encrypt(string $value): string
        {
            return base64_encode($value);
        }

        public function decrypt(string $value): string
        {
            return (string) base64_decode($value, true);
        }
    }
}

use PrestaShop\Module\Unipayment\Calculator\AvailableScheme;
use PrestaShop\Module\Unipayment\Calculator\CalculationResult;
use PrestaShop\Module\Unipayment\Calculator\FirstInstallmentState;
use PrestaShop\Module\Unipayment\Checkout\ValidatedPaymentRequest;
use PrestaShop\Module\Unipayment\Order\BankStatus;
use PrestaShop\Module\Unipayment\Order\LeasingOrderEmailPresenter;
use PrestaShop\Module\Unipayment\Order\SensitiveDataCipher;

function assertProcess2OrderConf(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$gateway = (string) file_get_contents($root . '/src/Order/NativePrestaShopOrderGateway.php');
$lifecycle = (string) file_get_contents($root . '/src/Order/PostControlPanelLifecycleService.php');
$orchestrator = (string) file_get_contents($root . '/src/Order/OrderOrchestrator.php');
$hook = (string) file_get_contents($root . '/unipayment.php');
$thankYou = (string) file_get_contents($root . '/views/templates/hook/order_confirmation_cp_failure.tpl');

$request = new ValidatedPaymentRequest(
    new CalculationResult(
        new AvailableScheme('standard', 'KOP1', 12, 0, null, []),
        1000.0,
        new FirstInstallmentState(100.0, false, true),
        900.0,
        80.0,
        1060.0,
        5.0,
        6.0
    ),
    ['email' => 'customer@example.com', 'egn' => '9001011234', 'phone2' => '0888123456'],
    [],
    'fingerprint'
);

$presenter = new LeasingOrderEmailPresenter(new SensitiveDataCipher(new \PhpEncryption(_NEW_COOKIE_KEY_)));
$process2Shop = ['uni_proces' => 1];
$prematureVars = $presenter->mailExtraVarsFromRequest($request, $process2Shop);
$prematureHtml = (string) ($prematureVars['{unipayment_leasing_html}'] ?? '');
$prematureTxt = (string) ($prematureVars['{unipayment_leasing_txt}'] ?? '');

assertProcess2OrderConf($prematureHtml !== '' && $prematureTxt !== '', 'presenter still renders Process 2 customer leasing block when asked');
assertProcess2OrderConf(
    strpos($prematureHtml, 'Статус към банката') !== false
        && strpos($prematureHtml, BankStatus::LABEL_SENT_PROCESS2) !== false,
    'rendered customer HTML would claim Изпратен Банка - Процес 2 if attached at validateOrder'
);
assertProcess2OrderConf(
    strpos($prematureTxt, BankStatus::LABEL_SENT_PROCESS2) !== false,
    'rendered customer text would claim Изпратен Банка - Процес 2 if attached at validateOrder'
);
assertProcess2OrderConf(
    strpos($prematureHtml, '9001011234') === false && strpos($prematureTxt, 'ЕГН') === false,
    'customer extra vars must not include EGN'
);

$createMethod = substr($gateway, (int) strpos($gateway, 'function create('));
$createMethod = substr($createMethod, 0, (int) strpos($createMethod, 'function load('));
assertProcess2OrderConf(
    strpos($createMethod, 'mailExtraVarsFromRequest') !== false,
    'Process 1 still prepares deferred order_conf extra vars'
);
assertProcess2OrderConf(
    strpos($createMethod, 'isProcess2($shop)') !== false
        && strpos($createMethod, '!$process2') !== false
        && strpos($createMethod, '$extraVars = [];') !== false,
    'Process 2 must not attach leasing extra vars at PS order creation'
);

assertProcess2OrderConf(
    strpos($lifecycle, 'dispatchLeasingEmail') !== false
        && strpos($lifecycle, 'BankStatus::successfulSend') !== false,
    'Process 2 success leasing email remains after CP create'
);
assertProcess2OrderConf(
    strpos($orchestrator, 'LeasingEmailNotifier') === false
        && strpos($orchestrator, 'mailExtraVarsFromRequest') === false
        && strpos($orchestrator, 'dispatchLeasingEmail') === false,
    'CP create failure path must not send leasing emails'
);

assertProcess2OrderConf(
    strpos($hook, "(\$params['template'] ?? '') !== 'order_conf'") !== false
        && strpos($hook, '{unipayment_leasing_html}') !== false,
    'order_conf injection remains gated on extra vars'
);
assertProcess2OrderConf(
    strpos($thankYou, 'не беше регистрирана успешно') !== false
        && strpos($thankYou, BankStatus::LABEL_SENT_PROCESS2) === false,
    'Thank You CP-failure notice must not use bank-sent wording'
);

fwrite(STDOUT, "OK (Process 2 CP-failure order_conf must not claim bank-sent)\n");
