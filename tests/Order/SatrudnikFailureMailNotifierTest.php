<?php

declare(strict_types=1);

/**
 * Satrudnik failure notification — status gate, recipient, once-only, isolation.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('_PS_MODULE_DIR_', sys_get_temp_dir() . '/');
define('_NEW_COOKIE_KEY_', 'satrudnik-mail-test-key');

final class Configuration
{
    /** @var array<string, mixed> */
    public static $values = [
        'PS_SHOP_EMAIL' => 'shop@example.com',
        'PS_SHOP_NAME' => 'Test Shop',
        'PS_LANG_DEFAULT' => 1,
    ];

    /**
     * @param int|null $idLang
     * @param int|null $idShopGroup
     * @param int|null $idShop
     * @param mixed $default
     *
     * @return mixed
     */
    public static function get(
        string $key,
        $idLang = null,
        $idShopGroup = null,
        $idShop = null,
        $default = false
    ) {
        return self::$values[$key] ?? $default;
    }
}

final class Mail
{
    /** @var list<array<string, mixed>> */
    public static $sent = [];

    /** @var bool */
    public static $throwOnSatrudnik = false;

    /**
     * @param int $idLang
     * @param array<string, mixed> $templateVars
     * @param string|array<int, string> $to
     * @param string|null $toName
     * @param string|null $from
     * @param string|null $fromName
     * @param mixed $fileAttachment
     * @param bool|null $mode_smtp
     * @param string|null $templatePath
     * @param bool $die
     * @param int|null $idShop
     * @param string|null $bcc
     * @param string|null $replyTo
     */
    public static function Send(
        $idLang,
        string $template,
        string $subject,
        array $templateVars,
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
        $subjectText = (string) $subject;
        if (
            self::$throwOnSatrudnik
            && strpos($subjectText, 'Проблем при изпращане на заявка за финансиране') === 0
        ) {
            throw new RuntimeException('forced satrudnik mail failure');
        }
        self::$sent[] = [
            'to' => $to,
            'subject' => $subjectText,
            'template' => (string) $template,
            'message' => (string) ($templateVars['{message}'] ?? ''),
            'message_html' => (string) ($templateVars['{message_html}'] ?? ''),
        ];

        return true;
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

    /**
     * @return string|false
     */
    public function decrypt(string $value)
    {
        $decoded = base64_decode($value, true);

        return is_string($decoded) ? $decoded : false;
    }
}

final class Order
{
    /** @var int */
    public $id = 0;

    /** @var string */
    public $date_add = '';

    public function __construct(int $idOrder = 0)
    {
        if ($idOrder === 88) {
            $this->id = 88;
            $this->date_add = '2026-09-18 10:00:00';
        }
    }
}

final class Validate
{
    /**
     * @param mixed $object
     */
    public static function isLoadedObject($object): bool
    {
        return is_object($object) && !empty($object->id);
    }
}

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Order\BankStatus;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotStoreInterface;
use PrestaShop\Module\Unipayment\Order\LeasingEmailNotifier;
use PrestaShop\Module\Unipayment\Order\LeasingOrderEmailPresenter;
use PrestaShop\Module\Unipayment\Order\SatrudnikFailureMailNotifier;

function assertSatrudnik(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class SatrudnikMemorySnapshots implements FinancingSnapshotStoreInterface
{
    /** @var array<int, array<string, mixed>> */
    public $rows = [];

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
        $this->rows[$attemptId] = array_replace($this->rows[$attemptId] ?? [], $changes);
    }
}

/**
 * @param array<string, mixed> $overrides
 *
 * @return array<string, mixed>
 */
function satrudnikSnapshot(array $overrides = []): array
{
    return array_replace([
        'id_order' => 88,
        'order_reference' => 'SATRUDREF001',
        'control_panel_order_id' => 0,
        'customer_json' => ['email' => 'customer@example.com'],
        'kop_code' => 'KOP1',
        'months' => 12,
        'first_installment' => '0.00',
        'monthly_installment' => '90.00',
        'financed_amount' => '1000.00',
        'total_payable' => '1080.00',
        'currency_iso' => 'BGN',
        'status_label' => BankStatus::LABEL_SEND_FAILED_CP,
    ], $overrides);
}

function satrudnikMailsTo(string $email): array
{
    return array_values(array_filter(
        Mail::$sent,
        static function (array $mail) use ($email): bool {
            return strcasecmp((string) $mail['to'], $email) === 0
                && strpos((string) $mail['subject'], 'Проблем при изпращане на заявка за финансиране') === 0;
        }
    ));
}

$notifierSrc = (string) file_get_contents($root . '/src/Order/LeasingEmailNotifier.php');
$satrudnikSrc = (string) file_get_contents($root . '/src/Order/SatrudnikFailureMailNotifier.php');
$dispatcherSrc = (string) file_get_contents($root . '/src/Order/FinancingOrderMailDispatcher.php');
$orchestratorSrc = (string) file_get_contents($root . '/src/Order/OrderOrchestrator.php');
$lifecycleSrc = (string) file_get_contents($root . '/src/Order/PostControlPanelLifecycleService.php');
$coordinatorSrc = (string) file_get_contents($root . '/src/SmartUcf/SmartUcfSessionCoordinator.php');

assertSatrudnik(strpos($notifierSrc, 'SatrudnikFailureMailNotifier') !== false, 'H: LeasingEmailNotifier references Satrudnik notifier');
assertSatrudnik(strpos($dispatcherSrc, 'notify($snapshot, $attemptId, $shop, $status)') !== false, 'H: dispatcher passes status into notify');
assertSatrudnik(strpos($orchestratorSrc, 'SatrudnikFailureMailNotifier') === false, 'H: orchestrator must not call Satrudnik directly');
assertSatrudnik(strpos($lifecycleSrc, 'SatrudnikFailureMailNotifier') === false, 'H: lifecycle must not call Satrudnik directly');
assertSatrudnik(strpos($coordinatorSrc, 'SatrudnikFailureMailNotifier') === false, 'H: SmartUCF coordinator must not call Satrudnik');
assertSatrudnik(
    strpos($satrudnikSrc, 'BankStatus::SEND_FAILED_CP') !== false
        && strpos($satrudnikSrc, 'BankStatus::SEND_FAILED_SMARTUCF') !== false,
    'H: notifier gates on canonical failure ids'
);

// --- A. CP failure ---
Mail::$sent = [];
Mail::$throwOnSatrudnik = false;
$snapshotsA = new SatrudnikMemorySnapshots();
$snapshotsA->rows[1] = ['leasing_email_sent' => 0];
$leasingA = new LeasingEmailNotifier($snapshotsA, new LeasingOrderEmailPresenter());
$leasingA->notify(
    satrudnikSnapshot(),
    1,
    ['satrudnik_email' => 'satrudnik@example.com', 'uni_email' => 'uni@example.com'],
    ['status_id' => BankStatus::SEND_FAILED_CP, 'status_label' => BankStatus::LABEL_SEND_FAILED_CP]
);
$satA = satrudnikMailsTo('satrudnik@example.com');
assertSatrudnik(count($satA) === 1, 'A: Satrudnik mail sent once for bank_send_failed_cp');
assertSatrudnik(
    strpos($satA[0]['subject'], 'SATRUDREF001') !== false,
    'A: subject contains order reference'
);
assertSatrudnik(
    strpos($satA[0]['message'], BankStatus::LABEL_SEND_FAILED_CP) !== false
        && strpos($satA[0]['message'], BankStatus::SEND_FAILED_CP) !== false,
    'A: body contains CP failure status'
);
assertSatrudnik(strpos($satA[0]['message'], 'КП поръчка:') === false, 'A: no CP order line when id=0');
assertSatrudnik((int) ($snapshotsA->rows[1]['leasing_email_sent'] ?? 0) === 1, 'A: leasing_email_sent finalized');

// --- B. SmartUCF failure with CP id ---
Mail::$sent = [];
$snapshotsB = new SatrudnikMemorySnapshots();
$snapshotsB->rows[2] = ['leasing_email_sent' => 0];
$leasingB = new LeasingEmailNotifier($snapshotsB, new LeasingOrderEmailPresenter());
$leasingB->notify(
    satrudnikSnapshot(['control_panel_order_id' => 373]),
    2,
    ['satrudnik_email' => 'satrudnik@example.com'],
    ['status_id' => BankStatus::SEND_FAILED_SMARTUCF, 'status_label' => BankStatus::LABEL_SEND_FAILED_SMARTUCF]
);
$satB = satrudnikMailsTo('satrudnik@example.com');
assertSatrudnik(count($satB) === 1, 'B: SmartUCF failure mail sent');
assertSatrudnik(strpos($satB[0]['message'], 'КП поръчка: 373') !== false, 'B: body contains CP order id');
assertSatrudnik(
    strpos($satB[0]['message'], BankStatus::LABEL_SEND_FAILED_SMARTUCF) !== false,
    'B: SmartUCF failure label present'
);

// --- C. Missing recipient ---
foreach ([[], ['satrudnik_email' => null], ['satrudnik_email' => ''], ['satrudnik_email' => '   ']] as $i => $shop) {
    Mail::$sent = [];
    $snapshotsC = new SatrudnikMemorySnapshots();
    $attemptC = 30 + $i;
    $snapshotsC->rows[$attemptC] = ['leasing_email_sent' => 0];
    (new LeasingEmailNotifier($snapshotsC, new LeasingOrderEmailPresenter()))->notify(
        satrudnikSnapshot(['order_reference' => 'MISSREC' . $i]),
        $attemptC,
        $shop,
        ['status_id' => BankStatus::SEND_FAILED_CP, 'status_label' => BankStatus::LABEL_SEND_FAILED_CP]
    );
    assertSatrudnik(satrudnikMailsTo('satrudnik@example.com') === [], 'C: no Satrudnik mail when recipient missing');
}
Mail::$sent = [];
$snapshotsCEmpty = new SatrudnikMemorySnapshots();
$snapshotsCEmpty->rows[39] = ['leasing_email_sent' => 0];
(new LeasingEmailNotifier($snapshotsCEmpty, new LeasingOrderEmailPresenter()))->notify(
    satrudnikSnapshot(),
    39,
    ['satrudnik_email' => ''],
    ['status_id' => BankStatus::SEND_FAILED_CP, 'status_label' => BankStatus::LABEL_SEND_FAILED_CP]
);
assertSatrudnik(true, 'C: no exception on empty recipient');

// --- D. Non-target statuses ---
foreach (
    [
        ['status_id' => BankStatus::SENT_PROCESS1, 'status_label' => BankStatus::LABEL_SENT_PROCESS1],
        ['status_id' => BankStatus::SENT_PROCESS2, 'status_label' => BankStatus::LABEL_SENT_PROCESS2],
        ['status_id' => BankStatus::SEND_FAILED, 'status_label' => BankStatus::LABEL_SEND_FAILED],
        ['status_id' => '', 'status_label' => 'outcome_unknown'],
    ] as $i => $status
) {
    Mail::$sent = [];
    $snapshotsD = new SatrudnikMemorySnapshots();
    $snapshotsD->rows[10 + $i] = ['leasing_email_sent' => 0];
    (new LeasingEmailNotifier($snapshotsD, new LeasingOrderEmailPresenter()))->notify(
        satrudnikSnapshot(['order_reference' => 'NONTARGET' . $i]),
        10 + $i,
        ['satrudnik_email' => 'satrudnik@example.com'],
        $status
    );
    assertSatrudnik(
        satrudnikMailsTo('satrudnik@example.com') === [],
        'D: no Satrudnik mail for ' . ($status['status_id'] !== '' ? $status['status_id'] : 'outcome_unknown')
    );
}

// --- E. Once-only guard ---
Mail::$sent = [];
$snapshotsE = new SatrudnikMemorySnapshots();
$snapshotsE->rows[5] = ['leasing_email_sent' => 1];
$leasingE = new LeasingEmailNotifier($snapshotsE, new LeasingOrderEmailPresenter());
$leasingE->notify(
    satrudnikSnapshot(),
    5,
    ['satrudnik_email' => 'satrudnik@example.com'],
    ['status_id' => BankStatus::SEND_FAILED_CP, 'status_label' => BankStatus::LABEL_SEND_FAILED_CP]
);
assertSatrudnik(satrudnikMailsTo('satrudnik@example.com') === [], 'E: leasing_email_sent blocks second Satrudnik mail');
assertSatrudnik(Mail::$sent === [], 'E: no leasing/Satrudnik mails when already sent');

// --- F. Mail failure isolation ---
Mail::$sent = [];
Mail::$throwOnSatrudnik = true;
PrestaShopLogger::$logs = [];
$snapshotsF = new SatrudnikMemorySnapshots();
$snapshotsF->rows[6] = ['leasing_email_sent' => 0];
$bankBefore = ['status_id' => BankStatus::SEND_FAILED_CP];
try {
    (new LeasingEmailNotifier($snapshotsF, new LeasingOrderEmailPresenter()))->notify(
        satrudnikSnapshot(),
        6,
        ['satrudnik_email' => 'satrudnik@example.com'],
        ['status_id' => BankStatus::SEND_FAILED_CP, 'status_label' => BankStatus::LABEL_SEND_FAILED_CP]
    );
    $outward = false;
} catch (Throwable $exception) {
    $outward = true;
}
Mail::$throwOnSatrudnik = false;
assertSatrudnik(!$outward, 'F: mail failure must not throw outward');
assertSatrudnik($bankBefore['status_id'] === BankStatus::SEND_FAILED_CP, 'F: bank status unchanged');
assertSatrudnik(
    strpos(implode("\n", PrestaShopLogger::$logs), 'Satrudnik failure email could not be sent') !== false,
    'F: Satrudnik failure logged'
);
assertSatrudnik(satrudnikMailsTo('satrudnik@example.com') === [], 'F: failed Satrudnik mail not recorded');
assertSatrudnik(Mail::$sent !== [], 'F: standard leasing mail flow remains intact');
assertSatrudnik((int) ($snapshotsF->rows[6]['leasing_email_sent'] ?? 0) === 1, 'F: leasing_email_sent still finalized');

// --- G. No fallback to uni_email ---
Mail::$sent = [];
$snapshotsG = new SatrudnikMemorySnapshots();
$snapshotsG->rows[7] = ['leasing_email_sent' => 0];
(new LeasingEmailNotifier($snapshotsG, new LeasingOrderEmailPresenter()))->notify(
    satrudnikSnapshot(),
    7,
    ['uni_email' => 'uni-fallback@example.com'],
    ['status_id' => BankStatus::SEND_FAILED_CP, 'status_label' => BankStatus::LABEL_SEND_FAILED_CP]
);
assertSatrudnik(satrudnikMailsTo('uni-fallback@example.com') === [], 'G: must not fallback to uni_email');
foreach (Mail::$sent as $mail) {
    assertSatrudnik(
        strpos((string) $mail['subject'], 'Проблем при изпращане на заявка за финансиране') !== 0,
        'G: no Satrudnik subject without satrudnik_email'
    );
}

// Direct notifier unit checks
Mail::$sent = [];
(new SatrudnikFailureMailNotifier())->notify(
    satrudnikSnapshot(['control_panel_order_id' => 9, 'id_order' => 88]),
    ['satrudnik_email' => 'satrudnik@example.com'],
    ['status_id' => BankStatus::SEND_FAILED_SMARTUCF, 'status_label' => BankStatus::LABEL_SEND_FAILED_SMARTUCF]
);
$direct = satrudnikMailsTo('satrudnik@example.com');
assertSatrudnik(count($direct) === 1, 'direct notifier sends');
assertSatrudnik(strpos($direct[0]['message'], '2026-09-18') !== false, 'order date included when Order loads');
assertSatrudnik(strpos($direct[0]['message'], 'EGN') === false, 'no EGN in body');
assertSatrudnik(strpos($direct[0]['message'], 'session') === false, 'no session diagnostics');

fwrite(STDOUT, "OK (Satrudnik failure mail notifier)\n");
