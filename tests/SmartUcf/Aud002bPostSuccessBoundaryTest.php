<?php

declare(strict_types=1);

/**
 * AUD-002B review correction — post-createSession failure must never authorize another create.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCoordinationResult;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfFailureClassification;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfFailureClassifier;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfLifecyclePersistenceException;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfLifecycleStates;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfSessionCoordinator;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfSessionException;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfSessionGatewayInterface;

if (!class_exists('PrestaShopLogger', false)) {
    class PrestaShopLogger
    {
        /** @var list<string> */
        public static $messages = [];

        public static function addLog(string $message, int $severity = 1): void
        {
            self::$messages[] = $message;
        }
    }
}

function assertPostSuccess(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class Aud002bFakeSessionGateway implements SmartUcfSessionGatewayInterface
{
    /** @var int */
    public $createCalls = 0;
    /** @var \Throwable|null */
    public $throwOnCreate = null;
    /** @var array<string, mixed> */
    public $session = [
        'session_id' => 'remote-sess-1',
        'redirect_url' => 'https://onlinetest.ucfin.bg/sucf-online/Request/Start/remote-sess-1',
        'http_code' => 200,
        'raw_request' => '{}',
        'raw_response' => '{"ok":1}',
    ];

    public function createSession(array $shop, array $snapshot, $certificateLease = null): array
    {
        ++$this->createCalls;
        if ($this->throwOnCreate !== null) {
            throw $this->throwOnCreate;
        }

        return $this->session;
    }
}

/**
 * In-memory lifecycle for coordinator boundary tests (does not touch Db).
 * Injected via reflection because SmartUcfLifecycleRepository is final.
 */
final class Aud002bMemoryLifecycle
{
    /** @var array<string, mixed> */
    public $row;
    /** @var bool */
    public $markCreatedThrows = false;
    /** @var bool */
    public $markCreatedZeroRows = false;
    /** @var int */
    public $markCreatedCalls = 0;
    /** @var int */
    public $createSessionAuthorizedClaims = 0;

    public function __construct(array $row)
    {
        $this->row = $row;
    }

    public function findByAttempt(int $attemptId): ?array
    {
        return $this->row;
    }

    public function readAndNormalize(int $attemptId): ?array
    {
        return $this->row;
    }

    public function claimForSubmitting(int $attemptId): ?array
    {
        $state = (string) ($this->row['smartucf_state'] ?? '');
        $retryable = !empty($this->row['smartucf_retryable']);
        if (
            $state === SmartUcfLifecycleStates::NOT_STARTED
            || ($state === SmartUcfLifecycleStates::FAILED && $retryable)
        ) {
            $this->row['smartucf_state'] = SmartUcfLifecycleStates::SUBMITTING;
            $this->row['smartucf_retryable'] = 0;
            $this->row['smartucf_claimed_at'] = gmdate('Y-m-d H:i:s');
            ++$this->createSessionAuthorizedClaims;

            return $this->row;
        }

        return null;
    }

    public function markCreated(int $attemptId, string $sessionId, string $redirectUrl, int $httpCode): void
    {
        ++$this->markCreatedCalls;
        if ($this->markCreatedThrows) {
            throw new \RuntimeException('simulated markCreated DB failure');
        }
        if ($this->markCreatedZeroRows || (string) ($this->row['smartucf_state'] ?? '') !== SmartUcfLifecycleStates::SUBMITTING) {
            throw new SmartUcfLifecyclePersistenceException(
                'The SmartUCF created transition did not update exactly one submitting row.'
            );
        }
        $this->row['smartucf_state'] = SmartUcfLifecycleStates::CREATED;
        $this->row['smartucf_session_id'] = $sessionId;
        $this->row['smartucf_redirect_url'] = $redirectUrl;
        $this->row['smartucf_http_code'] = $httpCode;
        $this->row['smartucf_retryable'] = 0;
    }

    public function markOutcomeUnknown(int $attemptId, string $errorClass, int $httpCode = 0): void
    {
        $state = (string) ($this->row['smartucf_state'] ?? '');
        if (
            $state !== SmartUcfLifecycleStates::SUBMITTING
            && $state !== SmartUcfLifecycleStates::NOT_STARTED
        ) {
            throw new SmartUcfLifecyclePersistenceException(
                'The SmartUCF outcome_unknown transition did not update exactly one eligible row.'
            );
        }
        $this->row['smartucf_state'] = SmartUcfLifecycleStates::OUTCOME_UNKNOWN;
        $this->row['smartucf_error_class'] = $errorClass;
        $this->row['smartucf_http_code'] = $httpCode;
        $this->row['smartucf_retryable'] = 0;
    }

    public function markFailed(int $attemptId, string $errorClass, bool $retryable, int $httpCode = 0): void
    {
        if ((string) ($this->row['smartucf_state'] ?? '') !== SmartUcfLifecycleStates::SUBMITTING) {
            throw new SmartUcfLifecyclePersistenceException(
                'The SmartUCF failed transition did not update exactly one submitting row.'
            );
        }
        $this->row['smartucf_state'] = SmartUcfLifecycleStates::FAILED;
        $this->row['smartucf_error_class'] = $errorClass;
        $this->row['smartucf_retryable'] = $retryable ? 1 : 0;
        $this->row['smartucf_http_code'] = $httpCode;
    }
}

/**
 * @param Aud002bMemoryLifecycle $lifecycle
 */
function aud002bCoordinatorWith(Aud002bMemoryLifecycle $lifecycle, Aud002bFakeSessionGateway $gateway): SmartUcfSessionCoordinator
{
    $ref = new ReflectionClass(SmartUcfSessionCoordinator::class);
    /** @var SmartUcfSessionCoordinator $coordinator */
    $coordinator = $ref->newInstanceWithoutConstructor();
    $props = [
        'lifecycle' => $lifecycle,
        'client' => $gateway,
        'payloadBuilder' => new \PrestaShop\Module\Unipayment\SmartUcf\SmartUcfPayloadBuilder(),
        'classifier' => new SmartUcfFailureClassifier(),
        'snapshots' => null,
        'cpClient' => null,
        'module' => null,
        'context' => null,
    ];
    foreach ($props as $name => $value) {
        $prop = $ref->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($coordinator, $value);
    }

    return $coordinator;
}

$snapshot = [
    'id_attempt' => 42,
    'id_order' => 42,
    'order_reference' => 'POSTSUCC42',
    'currency_iso' => 'EUR',
    'kop_code' => 'X',
    'order_total' => 100,
    'first_installment' => 0,
    'months' => 12,
    'monthly_installment' => 10,
];
$shop = ['_currency_iso' => 'EUR'];

$baseRow = [
    'id_attempt' => 42,
    'smartucf_state' => SmartUcfLifecycleStates::NOT_STARTED,
    'smartucf_retryable' => 0,
    'smartucf_session_id' => null,
    'smartucf_redirect_url' => null,
];

// 1) createSession success → markCreated throws → never pre_send retryable → no second create
$gateway1 = new Aud002bFakeSessionGateway();
$life1 = new Aud002bMemoryLifecycle($baseRow);
$life1->markCreatedThrows = true;
$coord1 = aud002bCoordinatorWith($life1, $gateway1);
$result1 = $coord1->run(42, $shop, false, $snapshot);
assertPostSuccess($result1->isOutcomeUnknown(), '1: post-success markCreated failure → outcome_unknown');
assertPostSuccess(!$result1->isFailed(), '1: must not be failed');
assertPostSuccess($result1->isRetryable() === false, '1: must not be retryable');
assertPostSuccess($gateway1->createCalls === 1, '1: createSession called once');
assertPostSuccess(
    (string) $life1->row['smartucf_state'] === SmartUcfLifecycleStates::OUTCOME_UNKNOWN,
    '1: persisted outcome_unknown (not failed+retryable)'
);
assertPostSuccess(empty($life1->row['smartucf_retryable']), '1: retryable flag must stay 0');

$result1b = $coord1->run(42, $shop, false, $snapshot);
assertPostSuccess($result1b->isOutcomeUnknown(), '1 replay: still outcome_unknown');
assertPostSuccess($gateway1->createCalls === 1, '1 replay: NEVER second createSession');
assertPostSuccess($life1->createSessionAuthorizedClaims === 1, '1 replay: no new claim');

// 2) createSession success → markCreated affected_rows=0 → not silently created
$gateway2 = new Aud002bFakeSessionGateway();
$life2 = new Aud002bMemoryLifecycle($baseRow);
$life2->markCreatedZeroRows = true;
$coord2 = aud002bCoordinatorWith($life2, $gateway2);
$result2 = $coord2->run(42, $shop, false, $snapshot);
assertPostSuccess($result2->isOutcomeUnknown(), '2: zero-row markCreated → outcome_unknown');
assertPostSuccess(
    (string) $life2->row['smartucf_state'] !== SmartUcfLifecycleStates::CREATED,
    '2: must not silently accept created'
);
assertPostSuccess($gateway2->createCalls === 1, '2: single createSession');
$result2b = $coord2->run(42, $shop, false, $snapshot);
assertPostSuccess($gateway2->createCalls === 1, '2 replay: no second createSession');

// 3) Normal success: submitting → created → same redirect replay
$gateway3 = new Aud002bFakeSessionGateway();
$life3 = new Aud002bMemoryLifecycle($baseRow);
$coord3 = aud002bCoordinatorWith($life3, $gateway3);
$result3 = $coord3->run(42, $shop, false, $snapshot);
assertPostSuccess($result3->isCreated(), '3: created');
assertPostSuccess($result3->redirectUrl() === 'https://onlinetest.ucfin.bg/sucf-online/Request/Start/remote-sess-1', '3: redirect');
assertPostSuccess((string) $life3->row['smartucf_state'] === SmartUcfLifecycleStates::CREATED, '3: persisted created');
$result3b = $coord3->run(42, $shop, false, $snapshot);
assertPostSuccess($result3b->isCreated(), '3 replay: created');
assertPostSuccess($result3b->redirectUrl() === $result3->redirectUrl(), '3 replay: same redirect');
assertPostSuccess($gateway3->createCalls === 1, '3 replay: no second createSession');

// 4) Timeout / outcome_unknown path remains non-retryable (classifier + coordinator)
$classifier = new SmartUcfFailureClassifier();
$timeout = $classifier->classify(new SmartUcfSessionException(
    'timed out',
    false,
    '',
    0,
    SmartUcfSessionException::KIND_TRANSPORT
));
assertPostSuccess($timeout->targetState() === SmartUcfLifecycleStates::OUTCOME_UNKNOWN, '4: timeout → unknown');
assertPostSuccess($timeout->isRetryable() === false, '4: timeout not retryable');

$gateway4 = new Aud002bFakeSessionGateway();
$gateway4->throwOnCreate = new SmartUcfSessionException(
    'SmartUCF connection failed: Operation timed out',
    false,
    '',
    0,
    SmartUcfSessionException::KIND_TRANSPORT
);
$life4 = new Aud002bMemoryLifecycle($baseRow);
$coord4 = aud002bCoordinatorWith($life4, $gateway4);
$result4 = $coord4->run(42, $shop, false, $snapshot);
assertPostSuccess($result4->isOutcomeUnknown(), '4: coordinator timeout → outcome_unknown');
assertPostSuccess($gateway4->createCalls === 1, '4: one create attempt');
$result4b = $coord4->run(42, $shop, false, $snapshot);
assertPostSuccess($result4b->isOutcomeUnknown(), '4 replay: still unknown');
assertPostSuccess($gateway4->createCalls === 1, '4 replay: no second create');

// 5) Existing failed+retryable PRE-SEND still supports safe retry
$gateway5 = new Aud002bFakeSessionGateway();
$gateway5->throwOnCreate = new SmartUcfSessionException(
    'missing SmartUCF url',
    true,
    '',
    0,
    SmartUcfSessionException::KIND_PRE_SEND
);
$life5 = new Aud002bMemoryLifecycle($baseRow);
$coord5 = aud002bCoordinatorWith($life5, $gateway5);
$result5 = $coord5->run(42, $shop, false, $snapshot);
assertPostSuccess($result5->isFailed() && $result5->isRetryable(), '5: pre-send failed retryable');
assertPostSuccess(
    (string) $life5->row['smartucf_state'] === SmartUcfLifecycleStates::FAILED
        && !empty($life5->row['smartucf_retryable']),
    '5: persisted failed+retryable'
);
assertPostSuccess($result5->errorClass() === SmartUcfFailureClassification::CLASS_PRE_SEND, '5: pre_send class');

$gateway5->throwOnCreate = null;
$result5b = $coord5->run(42, $shop, false, $snapshot);
assertPostSuccess($result5b->isCreated(), '5: safe retry after pre-send can create');
assertPostSuccess($gateway5->createCalls === 2, '5: second createSession only after retryable failed');

// Classifier must not be used for post-success local throw classification path in coordinator source
$coordSrc = (string) file_get_contents($root . '/src/SmartUcf/SmartUcfSessionCoordinator.php');
$createCatchPos = strpos($coordSrc, 'return $this->handleCreateFailure');
$markCreatedPos = strpos($coordSrc, '$this->lifecycle->markCreated');
assertPostSuccess($createCatchPos !== false && $markCreatedPos !== false, 'source: create failure + markCreated present');
assertPostSuccess($markCreatedPos > $createCatchPos, 'source: markCreated is after createSession try/catch');
assertPostSuccess(
    strpos($coordSrc, 'classifyThrowable') !== false
        && strpos($coordSrc, 'handleCreateFailure') !== false,
    'source: classifyThrowable only via handleCreateFailure'
);

fwrite(STDOUT, "OK (AUD-002B post-success failure boundary)\n");
