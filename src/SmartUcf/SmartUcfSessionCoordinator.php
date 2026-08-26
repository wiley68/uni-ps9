<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\SmartUcf;

use PrestaShop\Module\Unipayment\Api\ControlPanelClient;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationFlags;
use PrestaShop\Module\Unipayment\Order\BankStatus;
use PrestaShop\Module\Unipayment\Order\ControlPanelOrderClientAdapter;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotRepository;
use PrestaShop\Module\Unipayment\Order\OrderBankStatusRepository;
use PrestaShop\Module\Unipayment\SmartUcf\Certificate\CertificateConsumerLease;
use PrestaShop\Module\Unipayment\SmartUcf\Certificate\CertificateSynchronizer;
use PrestaShop\Module\Unipayment\SmartUcf\Certificate\CertificateSyncException;

/**
 * Authoritative SmartUCF create-session lifecycle after PS+CP success (AUD-002B / AUD-008).
 *
 * Controllers must not call createSession() directly for Process 1 flows.
 */
final class SmartUcfSessionCoordinator implements \PrestaShop\Module\Unipayment\Order\PostControlPanelSmartUcfPort
{
    public const CUSTOMER_OUTCOME_UNKNOWN =
    'Поръчката е създадена, но потвърждението от банковата система не беше получено. Не изпращайте заявката повторно.';

    public const CUSTOMER_PROCESSING =
    'Заявката към банката се обработва. Моля, изчакайте.';

    public const CUSTOMER_FAILED =
    'Възникна грешка при обработката на заявката.';

    /** @var SmartUcfLifecycleRepository */
    private $lifecycle;
    /** @var SmartUcfSessionGatewayInterface */
    private $client;
    /** @var SmartUcfPayloadBuilder */
    private $payloadBuilder;
    /** @var SmartUcfFailureClassifier */
    private $classifier;
    /** @var FinancingSnapshotRepository */
    private $snapshots;
    /** @var ControlPanelOrderClientAdapter|null */
    private $cpClient;
    /** @var ControlPanelClient|null */
    private $controlPanelApi;
    /** @var CertificateSynchronizer|null */
    private $certificateSynchronizer;
    /** @var object|null Module instance retained for constructor compatibility */
    private $module;
    /** @var \Context|null */
    private $context;

    public function __construct(
        ?SmartUcfLifecycleRepository $lifecycle = null,
        ?SmartUcfSessionGatewayInterface $client = null,
        ?SmartUcfPayloadBuilder $payloadBuilder = null,
        ?SmartUcfFailureClassifier $classifier = null,
        ?FinancingSnapshotRepository $snapshots = null,
        ?ControlPanelOrderClientAdapter $cpClient = null,
        $module = null,
        ?\Context $context = null,
        ?ControlPanelClient $controlPanelApi = null,
        ?CertificateSynchronizer $certificateSynchronizer = null
    ) {
        $this->payloadBuilder = $payloadBuilder ?? new SmartUcfPayloadBuilder();
        $this->lifecycle = $lifecycle ?? new SmartUcfLifecycleRepository();
        $this->client = $client ?? new SmartUcfSessionClient($this->payloadBuilder);
        $this->classifier = $classifier ?? new SmartUcfFailureClassifier();
        $this->snapshots = $snapshots ?? new FinancingSnapshotRepository();
        $this->cpClient = $cpClient;
        $this->module = $module;
        $this->context = $context;
        $this->controlPanelApi = $controlPanelApi;
        $this->certificateSynchronizer = $certificateSynchronizer;
    }

    /**
     * @param array<string, mixed> $shop
     * @param array<string, mixed>|null $snapshot Preloaded snapshot row (optional)
     */
    public function run(
        int $attemptId,
        array $shop,
        bool $process2,
        ?array $snapshot = null
    ): SmartUcfCoordinationResult {
        if ($process2) {
            return SmartUcfCoordinationResult::process2();
        }

        if ($snapshot === null) {
            $snapshot = $this->snapshots->findByAttempt($attemptId);
        }
        if ($snapshot === null) {
            return SmartUcfCoordinationResult::failed(self::CUSTOMER_FAILED, true, SmartUcfFailureClassification::CLASS_PRE_SEND);
        }

        $row = $this->lifecycle->readAndNormalize($attemptId);
        if ($row === null) {
            return SmartUcfCoordinationResult::failed(self::CUSTOMER_FAILED, true, SmartUcfFailureClassification::CLASS_PRE_SEND);
        }

        $replay = $this->resultFromState($row);
        if ($replay !== null) {
            return $replay;
        }

        $certificateLease = null;
        if (ShopConfigurationFlags::usesSmartUcfCertificate($shop)) {
            try {
                $certificateLease = $this->resolveCertificateSynchronizer()->ensureCurrent();
            } catch (CertificateSyncException $exception) {
                \PrestaShopLogger::addLog(
                    'UniPayment SSL certificate sync failed before SmartUCF claim: '
                        . $exception->reason() . ' ' . $exception->getMessage(),
                    3
                );

                return SmartUcfCoordinationResult::failed(
                    self::CUSTOMER_FAILED,
                    true,
                    SmartUcfFailureClassification::CLASS_PRE_SEND
                );
            } catch (\Throwable $exception) {
                \PrestaShopLogger::addLog(
                    'UniPayment SSL certificate sync unexpected failure: ' . get_class($exception),
                    3
                );

                return SmartUcfCoordinationResult::failed(
                    self::CUSTOMER_FAILED,
                    true,
                    SmartUcfFailureClassification::CLASS_PRE_SEND
                );
            }
        }

        $claimed = $this->lifecycle->claimForSubmitting($attemptId);
        if ($claimed === null) {
            if ($certificateLease !== null) {
                $certificateLease->release();
            }
            $latest = $this->lifecycle->readAndNormalize($attemptId);
            if ($latest === null) {
                return SmartUcfCoordinationResult::processing(self::CUSTOMER_PROCESSING);
            }
            $fromLatest = $this->resultFromState($latest);
            if ($fromLatest !== null) {
                return $fromLatest;
            }

            return SmartUcfCoordinationResult::processing(self::CUSTOMER_PROCESSING);
        }

        $shop['_currency_iso'] = (string) ($shop['_currency_iso'] ?? ($snapshot['currency_iso'] ?? 'BGN'));
        $smartUcfPayload = null;

        try {
            $smartUcfPayload = $this->payloadBuilder->build($shop, $snapshot);
            $session = $this->client->createSession($shop, $snapshot, $certificateLease);
        } catch (\Throwable $exception) {
            return $this->handleCreateFailure(
                $attemptId,
                $snapshot,
                $exception,
                $smartUcfPayload
            );
        } finally {
            if ($certificateLease !== null) {
                $certificateLease->release();
            }
        }

        // Remote success is proven (valid session returned). Never authorize another createSession.
        try {
            $this->lifecycle->markCreated(
                $attemptId,
                (string) $session['session_id'],
                (string) $session['redirect_url'],
                (int) ($session['http_code'] ?? 0)
            );
        } catch (\Throwable $persistException) {
            \PrestaShopLogger::addLog(
                'UniPayment SmartUCF markCreated failed after remote success: '
                    . get_class($persistException) . ' ' . $persistException->getMessage(),
                3
            );

            try {
                $this->lifecycle->markOutcomeUnknown(
                    $attemptId,
                    SmartUcfFailureClassification::CLASS_TRANSPORT_AMBIGUOUS,
                    (int) ($session['http_code'] ?? 0)
                );
            } catch (\Throwable $fallbackException) {
                \PrestaShopLogger::addLog(
                    'UniPayment SmartUCF outcome_unknown fallback failed after remote success: '
                        . get_class($fallbackException) . ' ' . $fallbackException->getMessage(),
                    3
                );
            }

            return SmartUcfCoordinationResult::outcomeUnknown(self::CUSTOMER_OUTCOME_UNKNOWN);
        }

        try {
            $this->persistSuccessfulBankStatus((string) ($snapshot['order_reference'] ?? ''));
            $this->logSession(
                (int) ($snapshot['id_order'] ?? 0),
                (string) ($snapshot['order_reference'] ?? ''),
                $session
            );
        } catch (\Throwable $postSuccessException) {
            \PrestaShopLogger::addLog(
                'UniPayment post-SmartUCF success side effect failed (lifecycle remains created): '
                    . get_class($postSuccessException) . ' ' . $postSuccessException->getMessage(),
                2
            );
        }

        return SmartUcfCoordinationResult::created(
            (string) $session['redirect_url'],
            (string) $session['session_id'],
            $session
        );
    }

    /**
     * Replay helper for already-known attempt (e.g. AUD-002A existing order).
     *
     * @param array<string, mixed> $shop
     */
    public function resume(int $attemptId, array $shop, bool $process2): SmartUcfCoordinationResult
    {
        return $this->run($attemptId, $shop, $process2, null);
    }

    private function resolveCertificateSynchronizer(): CertificateSynchronizer
    {
        if ($this->certificateSynchronizer !== null) {
            return $this->certificateSynchronizer;
        }
        if ($this->controlPanelApi === null) {
            throw new CertificateSyncException(
                'Control Panel client is not configured for certificate synchronization.',
                CertificateSyncException::REASON_CP_TRANSPORT
            );
        }
        $this->certificateSynchronizer = new CertificateSynchronizer($this->controlPanelApi);

        return $this->certificateSynchronizer;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param mixed $smartUcfPayload
     */
    private function handleCreateFailure(
        int $attemptId,
        array $snapshot,
        \Throwable $exception,
        $smartUcfPayload
    ): SmartUcfCoordinationResult {
        $classification = $this->classifier->classifyThrowable($exception);
        $this->logFailure(
            (int) ($snapshot['id_order'] ?? 0),
            (string) ($snapshot['order_reference'] ?? ''),
            $exception,
            $smartUcfPayload,
            $exception instanceof SmartUcfSessionException ? $exception->rawResponse() : ''
        );

        if ($classification->targetState() === SmartUcfLifecycleStates::OUTCOME_UNKNOWN) {
            try {
                $this->lifecycle->markOutcomeUnknown(
                    $attemptId,
                    $classification->errorClass(),
                    $classification->httpCode()
                );
            } catch (SmartUcfLifecyclePersistenceException $persistException) {
                \PrestaShopLogger::addLog(
                    'UniPayment SmartUCF outcome_unknown persistence failed: ' . $persistException->getMessage(),
                    3
                );
            }

            return SmartUcfCoordinationResult::outcomeUnknown(self::CUSTOMER_OUTCOME_UNKNOWN);
        }

        try {
            $this->lifecycle->markFailed(
                $attemptId,
                $classification->errorClass(),
                $classification->isRetryable(),
                $classification->httpCode()
            );
        } catch (SmartUcfLifecyclePersistenceException $persistException) {
            \PrestaShopLogger::addLog(
                'UniPayment SmartUCF failed persistence failed: ' . $persistException->getMessage(),
                3
            );
        }

        $this->markDefinitiveFailure(
            (int) ($snapshot['id_order'] ?? 0),
            (string) ($snapshot['order_reference'] ?? '')
        );

        return SmartUcfCoordinationResult::failed(
            self::CUSTOMER_FAILED,
            $classification->isRetryable(),
            $classification->errorClass()
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resultFromState(array $row): ?SmartUcfCoordinationResult
    {
        $state = (string) ($row['smartucf_state'] ?? SmartUcfLifecycleStates::NOT_STARTED);
        if ($state === SmartUcfLifecycleStates::CREATED) {
            $redirect = (string) ($row['smartucf_redirect_url'] ?? '');
            $sessionId = (string) ($row['smartucf_session_id'] ?? '');
            if ($redirect !== '' && (new SmartUcfEndpointPolicy())->isTrustedApplicationRedirect($redirect)) {
                return SmartUcfCoordinationResult::created($redirect, $sessionId);
            }

            return SmartUcfCoordinationResult::outcomeUnknown(self::CUSTOMER_OUTCOME_UNKNOWN);
        }
        if ($state === SmartUcfLifecycleStates::SUBMITTING) {
            return SmartUcfCoordinationResult::processing(self::CUSTOMER_PROCESSING);
        }
        if ($state === SmartUcfLifecycleStates::OUTCOME_UNKNOWN) {
            return SmartUcfCoordinationResult::outcomeUnknown(self::CUSTOMER_OUTCOME_UNKNOWN);
        }
        if ($state === SmartUcfLifecycleStates::FAILED) {
            $retryable = !empty($row['smartucf_retryable']);
            if (!$retryable) {
                return SmartUcfCoordinationResult::failed(
                    self::CUSTOMER_FAILED,
                    false,
                    (string) ($row['smartucf_error_class'] ?? '')
                );
            }

            return null;
        }

        return null;
    }

    private function persistSuccessfulBankStatus(string $orderReference): void
    {
        if ($orderReference === '') {
            return;
        }
        $status = BankStatus::successfulSend(false);
        if ($this->cpClient !== null) {
            try {
                $this->cpClient->updateOrderStatus(
                    substr($orderReference, 0, 13),
                    $status['status_label'],
                    $status['status_id']
                );
            } catch (\Throwable $exception) {
                \PrestaShopLogger::addLog(
                    'UniPayment CP status update failed after SmartUCF created: ' . get_class($exception),
                    2
                );
            }
        }
        try {
            (new OrderBankStatusRepository())->updateByOrderIdentifier(
                $this->authorizedShopId(),
                $orderReference,
                $status['status_id'],
                $status['status_label']
            );
        } catch (\Throwable $exception) {
            \PrestaShopLogger::addLog(
                'UniPayment local bank status update failed after SmartUCF created: ' . get_class($exception),
                2
            );
        }
    }

    private function markDefinitiveFailure(int $idOrder, string $orderReference): void
    {
        $failedStatus = BankStatus::smartUcfFailure();
        if ($this->cpClient !== null && $orderReference !== '') {
            try {
                $this->cpClient->updateOrderStatus(
                    substr($orderReference, 0, 13),
                    $failedStatus['status_label'],
                    $failedStatus['status_id']
                );
            } catch (\Throwable $e) {
                \PrestaShopLogger::addLog('UniPayment CP status update failed after SmartUCF error: ' . get_class($e), 2);
            }
        }

        if ($orderReference !== '') {
            try {
                (new OrderBankStatusRepository())->updateByOrderIdentifier(
                    $this->authorizedShopId(),
                    $orderReference,
                    $failedStatus['status_id'],
                    $failedStatus['status_label']
                );
            } catch (\Throwable $exception) {
                \PrestaShopLogger::addLog(
                    'UniPayment local bank status update failed: ' . get_class($exception),
                    2
                );
            }
        }

    }

    /** @param array<string, mixed> $session */
    private function logSession(int $idOrder, string $orderReference, array $session): void
    {
        try {
            $journal = new SmartUcfDiagnosticJournal(
                new ConfigurationRepository(),
                new SmartUcfDebugLogRepository()
            );
            $journal->record(
                $idOrder,
                $orderReference,
                (int) ($session['http_code'] ?? 0),
                $session['raw_request'] ?? '',
                $session['raw_response'] ?? ''
            );
        } catch (\Throwable $e) {
            // Diagnostic only.
        }
    }

    private function authorizedShopId(): int
    {
        return $this->context instanceof \Context && isset($this->context->shop)
            ? (int) $this->context->shop->id
            : 0;
    }

    /** @param mixed $request */
    private function logFailure(int $idOrder, string $orderReference, \Throwable $exception, $request = '', string $response = ''): void
    {
        \PrestaShopLogger::addLog('UniPayment SmartUCF session failed: ' . $exception->getMessage(), 2);
        try {
            $journal = new SmartUcfDiagnosticJournal(
                new ConfigurationRepository(),
                new SmartUcfDebugLogRepository()
            );
            $http = $exception instanceof SmartUcfSessionException ? $exception->httpCode() : 0;
            $journal->record(
                $idOrder,
                $orderReference,
                $http,
                $request,
                $response !== '' ? $response : $exception->getMessage(),
                $exception->getMessage()
            );
        } catch (\Throwable $e) {
            // Diagnostic only.
        }
    }
}
