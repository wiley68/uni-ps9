<?php

declare(strict_types=1);

/**
 * AUD-007 — LeasingEmailNotifier must not mutate schema; leasing_email_sent is install baseline.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('_PS_MODULE_DIR_', sys_get_temp_dir() . '/');
define('_NEW_COOKIE_KEY_', 'aud007-test-key');

final class Configuration
{
    /** @var array<string, mixed> */
    public static $values = [
        'PS_SHOP_EMAIL' => 'shop@example.com',
        'PS_SHOP_NAME' => 'Test Shop',
        'PS_LANG_DEFAULT' => 1,
    ];

    /**
     * @param string $key
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

final class Mail
{
    /** @var list<array<string, mixed>> */
    public static $sent = [];

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
        self::$sent[] = [
            'to' => $to,
            'subject' => $subject,
            'template' => $template,
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
    public function __construct(string $key) {}

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

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Order\FinancingSnapshotStoreInterface;
use PrestaShop\Module\Unipayment\Order\LeasingEmailNotifier;
use PrestaShop\Module\Unipayment\Order\LeasingOrderEmailPresenter;

function assertAud007(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$notifierSrc = (string) file_get_contents($root . '/src/Order/LeasingEmailNotifier.php');
assertAud007(strpos($notifierSrc, 'ensureColumn') === false, '1: ensureColumn removed');
assertAud007(stripos($notifierSrc, 'SHOW COLUMNS') === false, '1b: no SHOW COLUMNS');
assertAud007(stripos($notifierSrc, 'ALTER TABLE') === false, '1c: no ALTER TABLE');
assertAud007(stripos($notifierSrc, 'INFORMATION_SCHEMA') === false, '1d: no INFORMATION_SCHEMA');

$installSrc = (string) file_get_contents($root . '/src/Order/FinancingSnapshotRepository.php');
assertAud007(
    preg_match(
        '/`leasing_email_sent`\s+TINYINT\(1\)\s+UNSIGNED\s+NOT\s+NULL\s+DEFAULT\s+0/',
        $installSrc
    ) === 1,
    '2: install DDL includes leasing_email_sent TINYINT(1) UNSIGNED NOT NULL DEFAULT 0'
);
assertAud007(
    strpos($installSrc, "'leasing_email_sent'") !== false
        || strpos($installSrc, '"leasing_email_sent"') !== false,
    '4: repository update allow-list includes leasing_email_sent'
);

final class Aud007MemorySnapshots implements FinancingSnapshotStoreInterface
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
        if (!isset($this->rows[$attemptId])) {
            $this->rows[$attemptId] = [];
        }
        $this->rows[$attemptId] = array_replace($this->rows[$attemptId], $changes);
    }
}

$snapshots = new Aud007MemorySnapshots();
$snapshots->rows[42] = ['leasing_email_sent' => 0];
Mail::$sent = [];

$snapshot = [
    'order_reference' => 'ABCDEFGHIJKLM',
    'customer_json' => ['email' => 'customer@example.com'],
    'status_label' => 'Изпратен Банка - Процес 1',
    'kop_code' => 'KOP1',
    'months' => 12,
    'first_installment' => '10.00',
    'monthly_installment' => '50.00',
    'financed_amount' => '500.00',
    'total_payable' => '600.00',
    'currency_iso' => 'EUR',
];

$notifier = new LeasingEmailNotifier($snapshots, new LeasingOrderEmailPresenter());
$notifier->notify($snapshot, 42, ['uni_process' => 1]);

assertAud007(Mail::$sent !== [], '3: email sent on first notify');
assertAud007((int) ($snapshots->rows[42]['leasing_email_sent'] ?? 0) === 1, '4b: leasing_email_sent set to 1');
assertAud007($snapshots->updateCalls === 1, '4c: one update on first notify');

$sentCount = count(Mail::$sent);
$notifier->notify($snapshot, 42, ['uni_process' => 1]);
assertAud007(count(Mail::$sent) === $sentCount, '5: second notify is idempotent (no extra mail)');
assertAud007($snapshots->updateCalls === 1, '5b: second notify does not re-update marker');

assertAud007(
    !is_dir($root . '/upgrade') || glob($root . '/upgrade/upgrade-*.php') === [] || glob($root . '/upgrade/upgrade-*.php') === false,
    'no upgrade scripts created'
);
$moduleSrc = (string) file_get_contents($root . '/unipayment.php');
assertAud007(strpos($moduleSrc, "version = '2.0.1'") !== false, 'version is 2.0.1');

fwrite(STDOUT, "OK (AUD-007 LeasingEmailNotifier no runtime schema mutation)\n");
