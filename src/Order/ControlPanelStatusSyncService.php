<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

use PrestaShop\Module\Unipayment\Api\Exception\AuthenticationException;
use PrestaShop\Module\Unipayment\Api\Exception\ConnectionException;
use PrestaShop\Module\Unipayment\Api\Exception\HttpException;
use PrestaShop\Module\Unipayment\Api\Exception\InvalidPayloadException;
use PrestaShop\Module\Unipayment\Api\Exception\MalformedJsonException;
use PrestaShop\Module\Unipayment\Api\Exception\TimeoutException;

/**
 * Durable, idempotent CP status PATCH synchronization after proven P1/P2 handoffs.
 *
 * Local bank_sent_* = business handoff proven.
 * cp_status_sync_* = CP confirmation state with concurrency-safe CAS transitions.
 *
 * bank_sent_process1 and bank_sent_process2 are mutually incompatible terminal
 * targets (CP ShopModuleBankStatusProgression), not sequential stages.
 */
final class ControlPanelStatusSyncService
{
    private const ADMIT = 'admit';
    private const SAME = 'same';
    private const CONFLICT = 'conflict';
    private const REJECT = 'reject';

    /** Positive allowlist of definitive non-retryable CP machine codes. */
    private const TERMINAL_ERROR_CODES = [
        'invalid_payload',
        'semantic_conflict',
        'unsupported_status',
        'order_not_found',
    ];

    /** Mutually incompatible CP terminal sent statuses (same lifecycle rank). */
    private const TERMINAL_SENT = [
        BankStatus::SENT_PROCESS1,
        BankStatus::SENT_PROCESS2,
    ];

    /** @var ControlPanelStatusSyncStoreInterface */
    private $store;

    /** @var ControlPanelOrderClientInterface|null */
    private $cpClient;

    public function __construct(
        ?ControlPanelStatusSyncStoreInterface $store = null,
        ?ControlPanelOrderClientInterface $cpClient = null
    ) {
        $this->store = $store ?? new FinancingSnapshotRepository();
        $this->cpClient = $cpClient;
    }

    /**
     * Persist pending sync for a proven business handoff, then attempt PATCH.
     *
     * @param array{status_id: string, status_label: string} $status
     */
    public function synchronizeAfterHandoff(int $attemptId, string $orderReference, array $status): string
    {
        if ($attemptId <= 0 || $orderReference === '') {
            return ControlPanelStatusSyncStates::NOT_NEEDED;
        }

        $statusId = (string) ($status['status_id'] ?? '');
        $statusLabel = (string) ($status['status_label'] ?? '');
        if ($statusId === '' || $statusLabel === '') {
            return ControlPanelStatusSyncStates::NOT_NEEDED;
        }

        $decision = $this->admitPendingTarget($attemptId, $statusId, $statusLabel);
        if ($decision === self::CONFLICT) {
            // Local authority already proves process1↔process2 incompatibility — no PATCH.
            return $this->currentStateOr($attemptId, ControlPanelStatusSyncStates::NOT_NEEDED);
        }

        return $this->attemptPending($attemptId, $orderReference);
    }

    /**
     * Retry a previously persisted pending sync.
     * Persistence remains authority for the target that is sent.
     */
    public function retryPending(int $attemptId, string $orderReference): string
    {
        if ($attemptId <= 0 || $orderReference === '') {
            return ControlPanelStatusSyncStates::NOT_NEEDED;
        }

        return $this->attemptPending($attemptId, $orderReference);
    }

    /** @return self::ADMIT|self::SAME|self::CONFLICT|self::REJECT */
    private function admitPendingTarget(int $attemptId, string $statusId, string $statusLabel): string
    {
        $snapshot = $this->store->findByAttempt($attemptId);
        if ($snapshot === null) {
            return self::REJECT;
        }

        $currentState = (string) ($snapshot['cp_status_sync_state'] ?? ControlPanelStatusSyncStates::NOT_NEEDED);
        $currentStatusId = $this->nullableString($snapshot['cp_status_sync_status_id'] ?? null);
        $currentStatus = $this->nullableString($snapshot['cp_status_sync_status'] ?? null);

        $decision = $this->decideAdmission($currentState, $currentStatusId, $statusId);
        if ($decision !== self::ADMIT) {
            if ($decision === self::CONFLICT) {
                \PrestaShopLogger::addLog(
                    'UniPayment CP status sync conflict: incompatible terminal targets '
                    . (string) $currentStatusId . ' vs ' . $statusId,
                    2
                );
            }

            return $decision;
        }

        $updated = $this->store->compareAndSetPendingTarget(
            $attemptId,
            $currentState,
            $currentStatusId,
            $currentStatus,
            $statusId,
            $statusLabel
        );
        if ($updated) {
            return self::ADMIT;
        }

        // Concurrent mutation — reload and preserve newer authority.
        $latest = $this->store->findByAttempt($attemptId);
        if ($latest === null) {
            return self::REJECT;
        }
        $latestState = (string) ($latest['cp_status_sync_state'] ?? ControlPanelStatusSyncStates::NOT_NEEDED);
        $latestStatusId = $this->nullableString($latest['cp_status_sync_status_id'] ?? null);
        $retryDecision = $this->decideAdmission($latestState, $latestStatusId, $statusId);
        if ($retryDecision !== self::ADMIT) {
            if ($retryDecision === self::CONFLICT) {
                \PrestaShopLogger::addLog(
                    'UniPayment CP status sync conflict after CAS miss: incompatible terminal targets '
                    . (string) $latestStatusId . ' vs ' . $statusId,
                    2
                );
            }

            return $retryDecision;
        }

        $this->store->compareAndSetPendingTarget(
            $attemptId,
            $latestState,
            $latestStatusId,
            $this->nullableString($latest['cp_status_sync_status'] ?? null),
            $statusId,
            $statusLabel
        );

        return self::ADMIT;
    }

    private function attemptPending(int $attemptId, string $orderReference): string
    {
        $snapshot = $this->store->findByAttempt($attemptId);
        if ($snapshot === null) {
            return ControlPanelStatusSyncStates::NOT_NEEDED;
        }

        $state = (string) ($snapshot['cp_status_sync_state'] ?? ControlPanelStatusSyncStates::NOT_NEEDED);
        if ($state === ControlPanelStatusSyncStates::CONFIRMED) {
            return ControlPanelStatusSyncStates::CONFIRMED;
        }
        if ($state === ControlPanelStatusSyncStates::TERMINAL_FAILED) {
            return ControlPanelStatusSyncStates::TERMINAL_FAILED;
        }
        if ($state !== ControlPanelStatusSyncStates::PENDING) {
            return $state !== '' ? $state : ControlPanelStatusSyncStates::NOT_NEEDED;
        }

        if ($this->cpClient === null) {
            return ControlPanelStatusSyncStates::PENDING;
        }

        // Persistence is authority: re-read immediately before transport.
        $snapshot = $this->store->findByAttempt($attemptId);
        if ($snapshot === null) {
            return ControlPanelStatusSyncStates::NOT_NEEDED;
        }
        $state = (string) ($snapshot['cp_status_sync_state'] ?? ControlPanelStatusSyncStates::NOT_NEEDED);
        if ($state !== ControlPanelStatusSyncStates::PENDING) {
            return $state !== '' ? $state : ControlPanelStatusSyncStates::NOT_NEEDED;
        }

        $statusId = (string) ($snapshot['cp_status_sync_status_id'] ?? '');
        $statusLabel = (string) ($snapshot['cp_status_sync_status'] ?? '');
        if ($statusId === '' || $statusLabel === '') {
            return ControlPanelStatusSyncStates::PENDING;
        }

        try {
            $this->cpClient->updateOrderStatus(
                substr($orderReference, 0, 13),
                $statusLabel,
                $statusId
            );
            $confirmed = $this->store->compareAndSetConfirmed($attemptId, $statusId, $statusLabel);
            if ($confirmed) {
                return ControlPanelStatusSyncStates::CONFIRMED;
            }

            return $this->currentStateOr($attemptId, ControlPanelStatusSyncStates::PENDING);
        } catch (\Throwable $exception) {
            $classification = $this->classifyFailure($exception);
            $newState = $classification['terminal']
                ? ControlPanelStatusSyncStates::TERMINAL_FAILED
                : ControlPanelStatusSyncStates::PENDING;
            $updated = $this->store->compareAndSetFailure(
                $attemptId,
                $statusId,
                $statusLabel,
                $newState,
                $classification['error_class']
            );
            if (!$updated) {
                return $this->currentStateOr($attemptId, ControlPanelStatusSyncStates::PENDING);
            }

            \PrestaShopLogger::addLog(
                $classification['terminal']
                    ? 'UniPayment CP status sync terminal failure: ' . $classification['error_class']
                    : 'UniPayment CP status sync remains pending: ' . $classification['error_class'],
                2
            );

            return $newState;
        }
    }

    private function currentStateOr(int $attemptId, string $fallback): string
    {
        $snapshot = $this->store->findByAttempt($attemptId);
        if ($snapshot === null) {
            return $fallback;
        }
        $state = (string) ($snapshot['cp_status_sync_state'] ?? $fallback);

        return $state !== '' ? $state : $fallback;
    }

    /** @return self::ADMIT|self::SAME|self::CONFLICT|self::REJECT */
    private function decideAdmission(string $currentState, ?string $currentStatusId, string $newStatusId): string
    {
        if ($currentState === ControlPanelStatusSyncStates::NOT_NEEDED || $currentState === '') {
            return self::ADMIT;
        }

        if ($currentStatusId !== null && $currentStatusId === $newStatusId) {
            return self::SAME;
        }

        if ($this->isIncompatibleTerminalSentPair($currentStatusId, $newStatusId)) {
            return self::CONFLICT;
        }

        // No synthetic process ranking — do not replace an established target with another.
        return self::REJECT;
    }

    private function isIncompatibleTerminalSentPair(?string $currentStatusId, string $newStatusId): bool
    {
        if ($currentStatusId === null) {
            return false;
        }

        return in_array($currentStatusId, self::TERMINAL_SENT, true)
            && in_array($newStatusId, self::TERMINAL_SENT, true)
            && $currentStatusId !== $newStatusId;
    }

    /** @return array{terminal: bool, error_class: string} */
    private function classifyFailure(\Throwable $exception): array
    {
        if ($exception instanceof ConnectionException || $exception instanceof TimeoutException) {
            return ['terminal' => false, 'error_class' => 'cp_status_transport_ambiguous'];
        }
        if ($exception instanceof MalformedJsonException || $exception instanceof InvalidPayloadException) {
            return ['terminal' => false, 'error_class' => 'cp_status_malformed_response'];
        }
        if ($exception instanceof AuthenticationException) {
            return ['terminal' => false, 'error_class' => 'cp_status_auth_retryable'];
        }
        if ($exception instanceof HttpException) {
            $status = $exception->getStatusCode();
            $response = $exception->getResponse();
            $error = isset($response['error']) && is_string($response['error']) ? $response['error'] : '';

            if ($error !== '' && in_array($error, self::TERMINAL_ERROR_CODES, true)) {
                return ['terminal' => true, 'error_class' => 'cp_status_' . $error];
            }

            if ($error === 'authentication_failed' || $error === 'token_expired') {
                return ['terminal' => false, 'error_class' => 'cp_status_' . $error];
            }
            if ($error === 'rate_limited') {
                return ['terminal' => false, 'error_class' => 'cp_status_rate_limited'];
            }
            if ($error === 'internal_error') {
                return ['terminal' => false, 'error_class' => 'cp_status_internal_error'];
            }

            // Unknown/unexpected 4xx/5xx and missing machine codes remain pending.
            return [
                'terminal' => false,
                'error_class' => $error !== '' ? 'cp_status_' . $error : 'cp_status_http_' . $status,
            ];
        }

        return ['terminal' => false, 'error_class' => 'cp_status_' . get_class($exception)];
    }

    /** @param mixed $value */
    private function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = (string) $value;

        return $string === '' ? null : $string;
    }
}
