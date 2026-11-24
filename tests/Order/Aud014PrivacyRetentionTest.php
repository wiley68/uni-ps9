<?php

declare(strict_types=1);

/**
 * AUD-014 — audience-specific leasing email privacy and financing snapshot retention.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('_NEW_COOKIE_KEY_', 'aud014-test-key');
define('_PS_MODULE_DIR_', sys_get_temp_dir() . '/');

final class Configuration
{
    /** @var array<string, mixed> */
    public static $values = [
        'PS_SHOP_EMAIL' => 'admin@example.com',
        'PS_SHOP_NAME' => 'Test Shop',
        'PS_LANG_DEFAULT' => 1,
    ];

    public static function get(
        string $key,
        ?int $idLang = null,
        ?int $idShopGroup = null,
        ?int $idShop = null,
        mixed $default = false
    ): mixed {
        return self::$values[$key] ?? $default;
    }

    public static function updateValue(string $key, mixed $value): bool
    {
        self::$values[$key] = $value;

        return true;
    }
}

final class Mail
{
    /** @var list<array<string, mixed>> */
    public static $sent = [];

    public static function Send(
        int $idLang,
        string $template,
        string $subject,
        array $templateVars,
        string $to,
        ?string $toName = null,
        ?string $from = null,
        ?string $fromName = null,
        mixed $fileAttachment = null,
        mixed $mode_smtp = null,
        ?string $templatePath = null,
        bool $die = false,
        ?int $idShop = null,
        ?string $bcc = null,
        ?string $replyTo = null
    ): bool {
        self::$sent[] = [
            'to' => $to,
            'subject' => $subject,
            'template' => $template,
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
        self::$logs[] = (string) $message;
    }
}

final class PhpEncryption
{
    public function __construct(string $key) {}

    public function encrypt(string $plaintext): string
    {
        return base64_encode($plaintext);
    }

    public function decrypt(string $ciphertext)
    {
        $decoded = base64_decode($ciphertext, true);

        return is_string($decoded) ? $decoded : false;
    }
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

require_once dirname(__DIR__, 2) . '/src/Order/EmailAudience.php';
require_once dirname(__DIR__, 2) . '/src/Order/SensitiveDataCipher.php';
require_once dirname(__DIR__, 2) . '/src/Configuration/ShopConfigurationFlags.php';
require_once dirname(__DIR__, 2) . '/src/Order/LeasingOrderEmailPresenter.php';
require_once dirname(__DIR__, 2) . '/src/Order/LeasingEmailNotifier.php';
require_once dirname(__DIR__, 2) . '/src/Order/FinancingSnapshotStoreInterface.php';
require_once dirname(__DIR__, 2) . '/src/Security/ClockInterface.php';
require_once dirname(__DIR__, 2) . '/src/Security/FixedClock.php';
require_once dirname(__DIR__, 2) . '/src/Order/FinancingSnapshotRepository.php';
require_once dirname(__DIR__, 2) . '/src/Order/FinancingSnapshotRetentionService.php';

use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Configuration\ControlPanelOrigin;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationCache;
use PrestaShop\Module\Unipayment\Order\BankStatus;
use PrestaShop\Module\Unipayment\Order\EmailAudience;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotRepository;
use PrestaShop\Module\Unipayment\Order\OrderBankStatusRepository;
use PrestaShop\Module\Unipayment\Order\OrderLeasingDetailsPresenter;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotRetentionService;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotStoreInterface;
use PrestaShop\Module\Unipayment\Order\LeasingEmailNotifier;
use PrestaShop\Module\Unipayment\Order\LeasingOrderEmailPresenter;
use PrestaShop\Module\Unipayment\Order\SensitiveDataCipher;
use PrestaShop\Module\Unipayment\Security\FixedClock;

function assertAud014(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function assertNoRemovedFieldsInOutput(array $rows, string $text, string $html, string $egn = '9001011234'): void
{
    foreach ([$egn, '0888123456', 'ЕГН', 'Втори телефон'] as $forbidden) {
        assertAud014(stripos($text, $forbidden) === false, 'removed field must be absent from text');
        assertAud014(stripos($html, $forbidden) === false, 'removed field must be absent from HTML');
    }
    assertAud014(!array_intersect(['ЕГН', 'Втори телефон'], array_keys($rows)), 'removed rows must be absent');
}

/** @return array<string, mixed> */
function aud014Process2Snapshot(SensitiveDataCipher $cipher, string $egn = '9001011234'): array
{
    return [
        'order_reference' => 'REF-AUD014',
        'status_label' => 'Изпратен Банка - Процес 2',
        'months' => 12,
        'kop_code' => 'KOP1',
        'first_installment' => 100.0,
        'financed_amount' => 1000.0,
        'monthly_installment' => 90.0,
        'total_payable' => 1100.0,
        'glp' => 5.0,
        'gpr' => 6.0,
        'customer_json' => ['email' => 'customer@example.com', 'first_name' => 'Ivan'],
        'sensitive_payload' => $cipher->encrypt(['egn' => $egn, 'phone2' => '0888123456']),
    ];
}

$presenter = new LeasingOrderEmailPresenter();
$cipher = new SensitiveDataCipher(new PhpEncryption('aud014-test-key'));
$process1Shop = ['uni_proces' => 0];
$process2Shop = ['uni_proces' => 1];
$snapshotP2 = aud014Process2Snapshot($cipher);
$snapshotP1 = $snapshotP2;
$snapshotP1['status_label'] = 'Изпратен Банка - Процес 1';
$snapshotP1['sensitive_payload'] = null;

// Test A/B — Process 1 customer/admin no EGN
foreach ([EmailAudience::CUSTOMER, EmailAudience::ADMIN] as $audience) {
    $rows = $presenter->rowsFromSnapshot($snapshotP1, $process1Shop, $audience);
    $text = $presenter->renderText($rows);
    $html = $presenter->renderHtml($rows);
    assertNoRemovedFieldsInOutput($rows, $text, $html);
}

// Test C — Process 2 customer no EGN
$customerRows = $presenter->customerRowsFromSnapshot($snapshotP2, $process2Shop);
$customerText = $presenter->renderText($customerRows);
$customerHtml = $presenter->renderHtml($customerRows);
$customerVars = $presenter->mailExtraVarsFromSnapshot($snapshotP2, $process2Shop);
assertNoRemovedFieldsInOutput($customerRows, $customerText, $customerHtml);
assertAud014(strpos($customerText, LeasingOrderEmailPresenter::process2ConfirmationMessage()) !== false, 'C: customer Process 2 message retained');
assertAud014(strpos((string) ($customerVars['{unipayment_leasing_txt}'] ?? ''), '9001011234') === false, 'C: mail vars must not contain EGN');

// Test D — Process 2 admin output also omits both historical fields.
$adminRows = $presenter->adminRowsFromSnapshot($snapshotP2, $process2Shop);
$adminText = $presenter->renderText($adminRows);
assertNoRemovedFieldsInOutput($adminRows, $adminText, $presenter->renderHtml($adminRows));
assertAud014($adminRows['КОП'] === 'KOP1' && $adminRows['Сума на заема'] === '1000.00', 'admin keeps financial rows');
assertAud014($customerText !== $adminText, 'customer and admin bodies retain their audience-specific content');

final class Aud014MemorySnapshots implements FinancingSnapshotStoreInterface
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

// Test F — duplicate recipient sends admin variant once
Configuration::$values['PS_SHOP_EMAIL'] = 'same@example.com';
Mail::$sent = [];
$snapshotsF = new Aud014MemorySnapshots();
$snapshotsF->rows[7] = ['leasing_email_sent' => 0];
$dupSnapshot = aud014Process2Snapshot($cipher);
$dupSnapshot['customer_json'] = ['email' => 'same@example.com'];
(new LeasingEmailNotifier($snapshotsF, $presenter))->notify($dupSnapshot, 7, $process2Shop);
assertAud014(count(Mail::$sent) === 1, 'F: duplicate recipient must receive one email');
$duplicateText = (string) Mail::$sent[0]['message'];
assertAud014(strpos($duplicateText, 'KOP1') !== false && strpos($duplicateText, LeasingOrderEmailPresenter::process2ConfirmationMessage()) === false, 'F: duplicate recipient gets admin financial content');
assertNoRemovedFieldsInOutput([], $duplicateText, $duplicateText);
Configuration::$values['PS_SHOP_EMAIL'] = 'admin@example.com';

// Test G — no EGN in logs (static contract)
$notifierSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Order/LeasingEmailNotifier.php');
$presenterSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Order/LeasingOrderEmailPresenter.php');
assertAud014(stripos($notifierSrc, 'egn') === false, 'G: notifier must not reference egn in logs');
assertAud014(strpos($notifierSrc, 'getMessage()') === false, 'G: notifier must not log exception messages with payload');
assertAud014(stripos($presenterSrc, 'PrestaShopLogger') === false, 'G: presenter must not log sensitive data');

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}

if (!function_exists('pSQL')) {
    function pSQL(string $string, bool $htmlOK = false): string
    {
        unset($htmlOK);

        return str_replace("'", "\\'", $string);
    }
}

final class Db
{
    public function __construct(private array $historicalSnapshot) {}

    public function getRow(string $sql): array|false
    {
        if (str_contains($sql, 'unipayment_financing_snapshot')) {
            return $this->historicalSnapshot;
        }
        if (str_contains($sql, 'unipayment_shop_cache')) {
            return ['shop_data' => json_encode(['uni_proces' => 1, 'cp_origin' => ControlPanelOrigin::current()], JSON_THROW_ON_ERROR)];
        }
        if (str_contains($sql, 'unipayment_order_bank_status')) {
            return BankStatus::successfulSend(true);
        }

        return false;
    }
}

$boSnapshot = array_replace($snapshotP2, [
    'customer_json' => json_encode($snapshotP2['customer_json'], JSON_THROW_ON_ERROR),
    'address_json' => '{}',
    'lines_json' => '[]',
    'consents_json' => '[]',
]);
$boDb = new Db($boSnapshot);
Configuration::$values[ConfigurationRepository::UNICID] = 'synthetic-shop';
$boCache = new ShopConfigurationCache($boDb);
assertAud014(($boCache->getFresh('synthetic-shop')['uni_proces'] ?? 0) === 1, 'BO fixture resolves current Process 2 configuration');
$boRows = (new OrderLeasingDetailsPresenter(
    new FinancingSnapshotRepository($boDb),
    new OrderBankStatusRepository($boDb),
    $presenter,
    new ConfigurationRepository(),
    $boCache
))->rowsForOrder(70);
assertNoRemovedFieldsInOutput($boRows, $presenter->renderText($boRows), $presenter->renderHtml($boRows));
assertAud014($boRows['КОП'] === 'KOP1' && $boRows['Статус към банката'] === BankStatus::LABEL_SENT_PROCESS2, 'BO keeps financial rows and persisted status');
unset(Configuration::$values[ConfigurationRepository::UNICID]);

final class Aud014RetentionFakeDb
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public int $affectedRows = 0;

    public bool $throwOnUpdate = false;

    public function execute(string $sql): bool
    {
        if ($this->throwOnUpdate && strpos($sql, 'UPDATE') === 0) {
            throw new RuntimeException('db down');
        }
        $this->affectedRows = 0;
        if (!preg_match(
            "#UPDATE `[^`]+`\s+SET `customer_json` = '\{\}',\s+`address_json` = '\{\}',\s+`sensitive_payload` = NULL,\s+`updated_at` = '([^']+)'\s+WHERE `created_at` < '([^']+)'#s",
            $sql,
            $matches
        )) {
            return true;
        }

        $cutoff = $matches[2];
        $updated = 0;
        foreach ($this->rows as $index => $row) {
            if ((string) $row['created_at'] >= $cutoff) {
                continue;
            }
            $needs = ((string) $row['customer_json'] !== '{}')
                || ((string) $row['address_json'] !== '{}')
                || $row['sensitive_payload'] !== null;
            if (!$needs) {
                continue;
            }
            $this->rows[$index]['customer_json'] = '{}';
            $this->rows[$index]['address_json'] = '{}';
            $this->rows[$index]['sensitive_payload'] = null;
            $this->rows[$index]['updated_at'] = $matches[1];
            ++$updated;
            if ($updated >= 200) {
                break;
            }
        }
        $this->affectedRows = $updated;

        return true;
    }

    public function insert(string $table, array $data, $nullValues = false, $useCache = true, $type = 1): bool
    {
        unset($table, $nullValues, $useCache, $type);
        $this->rows[] = $data;

        return true;
    }

    public function Affected_Rows(): int
    {
        return $this->affectedRows;
    }
}

$now = strtotime('2026-06-01 12:00:00');
$cutoff = gmdate('Y-m-d H:i:s', $now - (FinancingSnapshotRetentionService::RETENTION_DAYS * 86400));
$repo = new \PrestaShop\Module\Unipayment\Order\FinancingSnapshotRepository(new Aud014RetentionFakeDb());

// Test H — 179 days retained (not redacted)
$dbH = new Aud014RetentionFakeDb();
$dbH->rows[] = [
    'created_at' => gmdate('Y-m-d H:i:s', $now - (179 * 86400)),
    'customer_json' => '{"email":"keep@example.com"}',
    'address_json' => '{"city":"Sofia"}',
    'sensitive_payload' => 'enc',
    'consents_json' => '{"terms":true}',
];
$repoH = new \PrestaShop\Module\Unipayment\Order\FinancingSnapshotRepository($dbH);
assertAud014($repoH->redactExpiredPii($cutoff, 200) === 0, 'H: 179-day snapshot must not be redacted');

// Test I — exactly 180 days retained (created_at == cutoff is not < cutoff)
$dbI = new Aud014RetentionFakeDb();
$dbI->rows[] = [
    'created_at' => $cutoff,
    'customer_json' => '{"email":"edge@example.com"}',
    'address_json' => '{}',
    'sensitive_payload' => 'enc',
    'consents_json' => '{"terms":true}',
];
$repoI = new \PrestaShop\Module\Unipayment\Order\FinancingSnapshotRepository($dbI);
assertAud014($repoI->redactExpiredPii($cutoff, 200) === 0, 'I: exactly 180-day boundary snapshot must be retained');

// Test J — older than 180 days redacted
$dbJ = new Aud014RetentionFakeDb();
$dbJ->rows[] = [
    'created_at' => gmdate('Y-m-d H:i:s', $now - (181 * 86400)),
    'customer_json' => '{"email":"old@example.com"}',
    'address_json' => '{"city":"Plovdiv"}',
    'sensitive_payload' => 'enc:v1:payload',
    'consents_json' => '{"terms":true}',
    'order_reference' => 'OLDREF',
    'financed_amount' => '500.00',
];
$repoJ = new \PrestaShop\Module\Unipayment\Order\FinancingSnapshotRepository($dbJ);
assertAud014($repoJ->redactExpiredPii($cutoff, 200) === 1, 'J: expired snapshot must be redacted');
$rowJ = $dbJ->rows[0];
assertAud014($rowJ['customer_json'] === '{}' && $rowJ['address_json'] === '{}' && $rowJ['sensitive_payload'] === null, 'J: PII fields redacted');
assertAud014($rowJ['consents_json'] === '{"terms":true}' && $rowJ['order_reference'] === 'OLDREF', 'J/K/L: metadata preserved');

// Test M — already redacted not rewritten
$dbM = new Aud014RetentionFakeDb();
$dbM->rows[] = [
    'created_at' => gmdate('Y-m-d H:i:s', $now - (200 * 86400)),
    'customer_json' => '{}',
    'address_json' => '{}',
    'sensitive_payload' => null,
    'consents_json' => '{"terms":true}',
];
$repoM = new \PrestaShop\Module\Unipayment\Order\FinancingSnapshotRepository($dbM);
assertAud014($repoM->redactExpiredPii($cutoff, 200) === 0, 'M: already-redacted row must be skipped');

// Test N — batch limit
$dbN = new Aud014RetentionFakeDb();
for ($i = 0; $i < 250; ++$i) {
    $dbN->rows[] = [
        'created_at' => gmdate('Y-m-d H:i:s', $now - (200 * 86400)),
        'customer_json' => '{"email":"batch' . $i . '@example.com"}',
        'address_json' => '{}',
        'sensitive_payload' => 'enc',
        'consents_json' => '{}',
    ];
}
$repoN = new \PrestaShop\Module\Unipayment\Order\FinancingSnapshotRepository($dbN);
$batchCount = $repoN->redactExpiredPii($cutoff, FinancingSnapshotRetentionService::BATCH_SIZE);
assertAud014($batchCount === FinancingSnapshotRetentionService::BATCH_SIZE, 'N: batch limit must be enforced');

// Test O — daily throttle
Configuration::$values = [];
$clockO = new FixedClock($now);
$dbO = new Aud014RetentionFakeDb();
$dbO->rows[] = [
    'created_at' => gmdate('Y-m-d H:i:s', $now - (200 * 86400)),
    'customer_json' => '{"email":"throttle@example.com"}',
    'address_json' => '{}',
    'sensitive_payload' => 'enc',
    'consents_json' => '{}',
];
$serviceO = new FinancingSnapshotRetentionService(
    new \PrestaShop\Module\Unipayment\Order\FinancingSnapshotRepository($dbO),
    $clockO
);
assertAud014($serviceO->maybeRun() === 1, 'O: first cleanup run must redact');
assertAud014($serviceO->maybeRun() === 0, 'O: second run within 24h must be throttled');
$serviceLater = new FinancingSnapshotRetentionService(
    new \PrestaShop\Module\Unipayment\Order\FinancingSnapshotRepository($dbO),
    new FixedClock($now + FinancingSnapshotRetentionService::THROTTLE_SECONDS + 1)
);
$dbO->rows[0]['customer_json'] = '{"email":"throttle2@example.com"}';
$dbO->rows[0]['sensitive_payload'] = 'enc';
assertAud014($serviceLater->maybeRun() === 1, 'O: cleanup runs again after throttle window');

// Test P — cleanup failure does not throw
Configuration::$values = [];
$dbP = new Aud014RetentionFakeDb();
$dbP->throwOnUpdate = true;
$dbP->rows[] = [
    'created_at' => gmdate('Y-m-d H:i:s', $now - (200 * 86400)),
    'customer_json' => '{"email":"fail@example.com"}',
    'address_json' => '{}',
    'sensitive_payload' => 'enc',
    'consents_json' => '{}',
];
$serviceP = new FinancingSnapshotRetentionService(
    new \PrestaShop\Module\Unipayment\Order\FinancingSnapshotRepository($dbP),
    new FixedClock($now)
);
assertAud014($serviceP->maybeRun() === 0, 'P: cleanup failure returns zero without throwing');

$orchestratorSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Order/OrderOrchestrator.php');
assertAud014(strpos($orchestratorSrc, 'FinancingSnapshotRetentionService') !== false, 'retention triggered after snapshot save');
assertAud014(strpos((string) file_get_contents(dirname(__DIR__, 2) . '/unipayment.php'), "version = '2.0.5'") !== false, 'version is 2.0.5');

$redactedSnapshot = aud014Process2Snapshot($cipher);
$redactedSnapshot['customer_json'] = [];
$redactedSnapshot['address_json'] = [];
$redactedSnapshot['sensitive_payload'] = null;
$adminAfterRedaction = $presenter->adminRowsFromSnapshot($redactedSnapshot, $process2Shop);
assertAud014($adminAfterRedaction !== [], 'admin order display survives PII redaction');
assertAud014(!isset($adminAfterRedaction['ЕГН']), 'redacted snapshot must not expose EGN in admin rows');

fwrite(STDOUT, "OK (AUD-014 privacy email audiences and snapshot retention)\n");
