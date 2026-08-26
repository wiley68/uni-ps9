<?php

declare(strict_types=1);

/**
 * Phase 12 remediation — leasing_email_sent only after all required Mail::Send succeed.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('_PS_MODULE_DIR_', sys_get_temp_dir() . '/');
define('_PS_MAIL_DIR_', sys_get_temp_dir() . '/');
define('_NEW_COOKIE_KEY_', 'leasing-email-completion-key');

final class Configuration
{
    /** @var array<string, mixed> */
    public static $values = [
        'PS_SHOP_EMAIL' => 'admin@example.com',
        'PS_SHOP_NAME' => 'Test Shop',
        'PS_LANG_DEFAULT' => 1,
    ];

    /**
     * @param mixed $idLang
     * @param mixed $idShopGroup
     * @param mixed $idShop
     * @param mixed $default
     *
     * @return mixed
     */
    public static function get(string $key, $idLang = null, $idShopGroup = null, $idShop = null, $default = false)
    {
        return self::$values[$key] ?? $default;
    }
}

final class PrestaShopLogger
{
    /** @var list<string> */
    public static $logs = [];

    public static function addLog(string $message, int $severity = 1): void
    {
        self::$logs[] = $message;
    }
}

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

    public function decrypt(string $value)
    {
        $decoded = base64_decode($value, true);

        return is_string($decoded) ? $decoded : false;
    }
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Order\BankStatus;
use PrestaShop\Module\Unipayment\Order\BankStatusPersistencePort;
use PrestaShop\Module\Unipayment\Order\DeferredOrderMailQueue;
use PrestaShop\Module\Unipayment\Order\FinancingOrderMailDispatcher;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotStoreInterface;
use PrestaShop\Module\Unipayment\Order\LeasingEmailDeliveryException;
use PrestaShop\Module\Unipayment\Order\LeasingEmailNotifier;
use PrestaShop\Module\Unipayment\Order\LeasingMailDispatchPort;
use PrestaShop\Module\Unipayment\Order\LeasingOrderEmailPresenter;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrationResult;
use PrestaShop\Module\Unipayment\Order\PostControlPanelLifecycleContext;
use PrestaShop\Module\Unipayment\Order\PostControlPanelLifecycleService;
use PrestaShop\Module\Unipayment\Order\PostControlPanelSmartUcfPort;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCoordinationResult;

function assertMailCompletion(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class CompletionSnapStore implements FinancingSnapshotStoreInterface
{
    /** @var array<int, array<string, mixed>> */
    public $rows = [];

    /** @var int */
    public $updateCalls = 0;

    public function save(int $attemptId, array $snapshot): void
    {
        $this->rows[$attemptId] = $snapshot;
    }

    public function findByAttempt(int $attemptId): ?array
    {
        return $this->rows[$attemptId] ?? null;
    }

    public function update(int $attemptId, array $changes): void
    {
        ++$this->updateCalls;
        $this->rows[$attemptId] = array_replace($this->rows[$attemptId] ?? [], $changes);
    }
}

/**
 * @return array<string, mixed>
 */
function completionSnapshot(string $customerEmail): array
{
    return [
        'order_reference' => 'REFMAIL01',
        'customer_json' => ['email' => $customerEmail],
        'status_label' => BankStatus::LABEL_SENT_PROCESS1,
        'kop_code' => 'KOP1',
        'months' => 12,
        'first_installment' => 10.0,
        'monthly_installment' => 50.0,
        'financed_amount' => 500.0,
        'total_payable' => 600.0,
        'glp' => 5.0,
        'gpr' => 6.0,
        'leasing_email_sent' => 0,
    ];
}

/**
 * @param list<bool|\Throwable> $script
 *
 * @return callable
 */
function completionMailScript(array &$script, array &$sent): callable
{
    return static function (
        int $languageId,
        string $template,
        string $subject,
        array $templateVars,
        string $to,
        string $fromEmail,
        string $fromName,
        string $moduleMailsDir
    ) use (&$script, &$sent): bool {
        unset($languageId, $template, $subject, $templateVars, $fromEmail, $fromName, $moduleMailsDir);
        $sent[] = $to;
        $next = array_shift($script);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next === true;
    };
}

$shop = ['uni_proces' => 0];
$presenter = new LeasingOrderEmailPresenter();

// 1) customer only, Mail::Send=true → marker=1
Configuration::$values['PS_SHOP_EMAIL'] = '';
$store1 = new CompletionSnapStore();
$store1->rows[1] = completionSnapshot('customer@example.com');
$sent1 = [];
$script1 = [true];
$n1 = new LeasingEmailNotifier($store1, $presenter, completionMailScript($script1, $sent1));
$n1->notify($store1->rows[1], 1, $shop);
assertMailCompletion(count($sent1) === 1, '1: one customer send');
assertMailCompletion((int) ($store1->rows[1]['leasing_email_sent'] ?? 0) === 1, '1: marker=1');

// 2) admin only, Mail::Send=true → marker=1
Configuration::$values['PS_SHOP_EMAIL'] = 'admin@example.com';
$store2 = new CompletionSnapStore();
$store2->rows[2] = completionSnapshot('');
$sent2 = [];
$script2 = [true];
$n2 = new LeasingEmailNotifier($store2, $presenter, completionMailScript($script2, $sent2));
$n2->notify($store2->rows[2], 2, $shop);
assertMailCompletion(count($sent2) === 1 && $sent2[0] === 'admin@example.com', '2: one admin send');
assertMailCompletion((int) ($store2->rows[2]['leasing_email_sent'] ?? 0) === 1, '2: marker=1');

// 3) customer+admin both succeed → marker=1
$store3 = new CompletionSnapStore();
$store3->rows[3] = completionSnapshot('customer@example.com');
$sent3 = [];
$script3 = [true, true];
$n3 = new LeasingEmailNotifier($store3, $presenter, completionMailScript($script3, $sent3));
$n3->notify($store3->rows[3], 3, $shop);
assertMailCompletion(count($sent3) === 2, '3: two sends');
assertMailCompletion((int) ($store3->rows[3]['leasing_email_sent'] ?? 0) === 1, '3: marker=1');

// 4) customer Mail::Send=false → marker NOT set
$store4 = new CompletionSnapStore();
$store4->rows[4] = completionSnapshot('customer@example.com');
Configuration::$values['PS_SHOP_EMAIL'] = '';
$sent4 = [];
$script4 = [false];
$n4 = new LeasingEmailNotifier($store4, $presenter, completionMailScript($script4, $sent4));
$threw4 = false;
try {
    $n4->notify($store4->rows[4], 4, $shop);
} catch (LeasingEmailDeliveryException $exception) {
    $threw4 = true;
}
assertMailCompletion($threw4, '4: throws on false');
assertMailCompletion((int) ($store4->rows[4]['leasing_email_sent'] ?? 0) === 0, '4: marker NOT set');

// 5) admin Mail::Send=false → marker NOT set
Configuration::$values['PS_SHOP_EMAIL'] = 'admin@example.com';
$store5 = new CompletionSnapStore();
$store5->rows[5] = completionSnapshot('');
$sent5 = [];
$script5 = [false];
$n5 = new LeasingEmailNotifier($store5, $presenter, completionMailScript($script5, $sent5));
$threw5 = false;
try {
    $n5->notify($store5->rows[5], 5, $shop);
} catch (LeasingEmailDeliveryException $exception) {
    $threw5 = true;
}
assertMailCompletion($threw5, '5: throws on admin false');
assertMailCompletion((int) ($store5->rows[5]['leasing_email_sent'] ?? 0) === 0, '5: marker NOT set');

// 6) customer succeeds, admin returns false → marker NOT set
$store6 = new CompletionSnapStore();
$store6->rows[6] = completionSnapshot('customer@example.com');
$sent6 = [];
$script6 = [true, false];
$n6 = new LeasingEmailNotifier($store6, $presenter, completionMailScript($script6, $sent6));
$threw6 = false;
try {
    $n6->notify($store6->rows[6], 6, $shop);
} catch (LeasingEmailDeliveryException $exception) {
    $threw6 = true;
}
assertMailCompletion($threw6 && count($sent6) === 2, '6: both audiences attempted');
assertMailCompletion((int) ($store6->rows[6]['leasing_email_sent'] ?? 0) === 0, '6: marker NOT set after partial');

// 7) Mail::Send throws → marker NOT set
$store7 = new CompletionSnapStore();
$store7->rows[7] = completionSnapshot('customer@example.com');
Configuration::$values['PS_SHOP_EMAIL'] = '';
$sent7 = [];
$script7 = [new RuntimeException('smtp down')];
$n7 = new LeasingEmailNotifier($store7, $presenter, completionMailScript($script7, $sent7));
$threw7 = false;
try {
    $n7->notify($store7->rows[7], 7, $shop);
} catch (LeasingEmailDeliveryException $exception) {
    $threw7 = true;
}
assertMailCompletion($threw7, '7: throws when Mail throws');
assertMailCompletion((int) ($store7->rows[7]['leasing_email_sent'] ?? 0) === 0, '7: marker NOT set');
assertMailCompletion(
    strpos(implode("\n", PrestaShopLogger::$logs), 'audience=customer') !== false
        && strpos(implode("\n", PrestaShopLogger::$logs), 'RuntimeException') !== false,
    '7: safe log includes audience + exception class'
);
assertMailCompletion(
    strpos(implode("\n", PrestaShopLogger::$logs), 'smtp down') === false,
    '7: log must not include exception message body'
);

// 8) same recipient success → one send, marker=1
Configuration::$values['PS_SHOP_EMAIL'] = 'same@example.com';
$store8 = new CompletionSnapStore();
$store8->rows[8] = completionSnapshot('same@example.com');
$sent8 = [];
$script8 = [true];
$n8 = new LeasingEmailNotifier($store8, $presenter, completionMailScript($script8, $sent8));
$n8->notify($store8->rows[8], 8, $shop);
assertMailCompletion(count($sent8) === 1, '8: one same-recipient send');
assertMailCompletion((int) ($store8->rows[8]['leasing_email_sent'] ?? 0) === 1, '8: marker=1');

// 9) same recipient failure → one send, marker NOT set
$store9 = new CompletionSnapStore();
$store9->rows[9] = completionSnapshot('same@example.com');
$sent9 = [];
$script9 = [false];
$n9 = new LeasingEmailNotifier($store9, $presenter, completionMailScript($script9, $sent9));
$threw9 = false;
try {
    $n9->notify($store9->rows[9], 9, $shop);
} catch (LeasingEmailDeliveryException $exception) {
    $threw9 = true;
}
assertMailCompletion($threw9 && count($sent9) === 1, '9: one failed same-recipient send');
assertMailCompletion((int) ($store9->rows[9]['leasing_email_sent'] ?? 0) === 0, '9: marker NOT set');

// 10) existing leasing_email_sent=1 → zero sends
$store10 = new CompletionSnapStore();
$store10->rows[10] = array_replace(completionSnapshot('customer@example.com'), ['leasing_email_sent' => 1]);
Configuration::$values['PS_SHOP_EMAIL'] = 'admin@example.com';
$sent10 = [];
$script10 = [true, true];
$n10 = new LeasingEmailNotifier($store10, $presenter, completionMailScript($script10, $sent10));
$n10->notify($store10->rows[10], 10, $shop);
assertMailCompletion($sent10 === [], '10: zero sends when already marked');
assertMailCompletion($store10->updateCalls === 0, '10: no marker rewrite');

// 12) retry after failed invocation → notifier attempts again
$store12 = new CompletionSnapStore();
$store12->rows[12] = completionSnapshot('customer@example.com');
Configuration::$values['PS_SHOP_EMAIL'] = '';
$sent12 = [];
$script12 = [false, true];
$n12 = new LeasingEmailNotifier($store12, $presenter, completionMailScript($script12, $sent12));
try {
    $n12->notify($store12->rows[12], 12, $shop);
} catch (LeasingEmailDeliveryException $exception) {
}
assertMailCompletion((int) ($store12->rows[12]['leasing_email_sent'] ?? 0) === 0, '12a: marker still 0 after fail');
$n12->notify($store12->rows[12], 12, $shop);
assertMailCompletion(count($sent12) === 2, '12b: retry sends again');
assertMailCompletion((int) ($store12->rows[12]['leasing_email_sent'] ?? 0) === 1, '12c: marker=1 after successful retry');

// 11) mail failure does NOT change bank status (Process 2 persist then mail fail)
final class CompletionBankSpy implements BankStatusPersistencePort
{
    /** @var list<array{statusId: string}> */
    public $updates = [];

    public function updateByOrderIdentifier(int $idShop, string $orderReference, string $statusId, string $statusLabel): ?array
    {
        $this->updates[] = ['statusId' => $statusId];

        return ['status_id' => $statusId];
    }
}

final class CompletionFailingMail implements LeasingMailDispatchPort
{
    public function send(array $snapshot, int $attemptId, array $shop, array $status): void
    {
        unset($snapshot, $attemptId, $shop, $status);
        throw new LeasingEmailDeliveryException('forced mail failure');
    }
}

final class CompletionIdleSmart implements PostControlPanelSmartUcfPort
{
    public function run(int $attemptId, array $shop, bool $process2, ?array $snapshot = null): SmartUcfCoordinationResult
    {
        unset($attemptId, $shop, $process2, $snapshot);

        return SmartUcfCoordinationResult::failed('unused');
    }

    public function resume(int $attemptId, array $shop, bool $process2): SmartUcfCoordinationResult
    {
        return $this->run($attemptId, $shop, $process2, null);
    }
}

$store11 = new CompletionSnapStore();
$store11->rows[11] = completionSnapshot('customer@example.com');
$bank11 = new CompletionBankSpy();
$result11 = (new PostControlPanelLifecycleService(
    $store11,
    new CompletionFailingMail(),
    $bank11
))->handle(
    new OrderOrchestrationResult(11, 'cp_created', 100, 'REFMAIL01', 555),
    ['uni_proces' => 1],
    new PostControlPanelLifecycleContext(1, 'BGN'),
    new CompletionIdleSmart()
);
assertMailCompletion($result11->isProcess2(), '11: Process 2 outcome preserved');
assertMailCompletion(
    $bank11->updates !== [] && $bank11->updates[0]['statusId'] === BankStatus::SENT_PROCESS2,
    '11: bank_sent_process2 persisted despite mail failure'
);
assertMailCompletion($result11->emailSent() === false, '11: withEmailSent(false)');
assertMailCompletion((int) ($store11->rows[11]['leasing_email_sent'] ?? 0) === 0, '11: marker remains 0');

// Native order_conf: flush empties queue before leasing notify; leasing fail must not re-queue
if (!class_exists('Mail', false)) {
    final class Mail
    {
        /** @var int */
        public static $sendCalls = 0;

        /**
         * @param mixed $idLang
         * @param mixed $template
         * @param mixed $subject
         * @param mixed $templateVars
         * @param mixed $to
         * @param mixed $toName
         * @param mixed $from
         * @param mixed $fromName
         * @param mixed $fileAttachment
         * @param mixed $mode_smtp
         * @param mixed $templatePath
         * @param mixed $die
         * @param mixed $idShop
         * @param mixed $bcc
         * @param mixed $replyTo
         */
        public static function Send(
            $idLang,
            $template,
            $subject,
            $templateVars,
            $to,
            $toName = null,
            $from = null,
            $fromName = null,
            $fileAttachment = null,
            $mode_smtp = null,
            $templatePath = null,
            $die = false,
            $idShop = null,
            $bcc = null,
            $replyTo = null
        ): bool {
            ++self::$sendCalls;
            unset(
                $idLang,
                $template,
                $subject,
                $templateVars,
                $to,
                $toName,
                $from,
                $fromName,
                $fileAttachment,
                $mode_smtp,
                $templatePath,
                $die,
                $idShop,
                $bcc,
                $replyTo
            );

            return true;
        }
    }
}

DeferredOrderMailQueue::discard();
DeferredOrderMailQueue::start();
DeferredOrderMailQueue::intercept([
    'template' => 'order_conf',
    'idLang' => 1,
    'subject' => 'Order',
    'templateVars' => [],
    'to' => 'customer@example.com',
]);
Mail::$sendCalls = 0;
$storeOc = new CompletionSnapStore();
$storeOc->rows[99] = completionSnapshot('customer@example.com');
Configuration::$values['PS_SHOP_EMAIL'] = '';
$failSender = static function () {
    return false;
};
$dispatcher = new FinancingOrderMailDispatcher(
    $presenter,
    new LeasingEmailNotifier($storeOc, $presenter, $failSender)
);
$threwOc = false;
try {
    $dispatcher->send(
        $storeOc->rows[99],
        99,
        $shop,
        BankStatus::successfulSend(false)
    );
} catch (LeasingEmailDeliveryException $exception) {
    $threwOc = true;
}
assertMailCompletion($threwOc, 'OC: leasing failure propagates after flush');
assertMailCompletion(Mail::$sendCalls === 1, 'OC: native order_conf flushed exactly once');
assertMailCompletion((int) ($storeOc->rows[99]['leasing_email_sent'] ?? 0) === 0, 'OC: leasing marker unset');
DeferredOrderMailQueue::start();
DeferredOrderMailQueue::intercept([
    'template' => 'order_conf',
    'idLang' => 1,
    'subject' => 'Order',
    'templateVars' => [],
    'to' => 'customer@example.com',
]);
DeferredOrderMailQueue::flush();
$callsAfterFlush = Mail::$sendCalls;
DeferredOrderMailQueue::flush();
assertMailCompletion(
    Mail::$sendCalls === $callsAfterFlush,
    'OC: second flush with empty queue does not resend order_conf'
);
DeferredOrderMailQueue::discard();

$notifierSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Order/LeasingEmailNotifier.php');
assertMailCompletion(
    strpos($notifierSrc, 'leasing_email_sent') !== false
        && strpos($notifierSrc, 'LeasingEmailDeliveryException') !== false
        && strpos($notifierSrc, '$result !== true') !== false,
    'source enforces false/throw failure before marker'
);

fwrite(STDOUT, "OK (leasing email completion marker invariant)\n");
