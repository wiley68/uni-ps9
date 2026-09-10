<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/**
 * Optimistic CAS over {@see FinancingSnapshotStoreInterface} for test doubles / non-SQL stores.
 * Production persistence uses {@see FinancingSnapshotRepository} SQL CAS directly.
 */
final class ControlPanelStatusSyncStoreAdapter implements ControlPanelStatusSyncStoreInterface
{
    /** @var FinancingSnapshotStoreInterface */
    private $inner;

    public function __construct(FinancingSnapshotStoreInterface $inner)
    {
        $this->inner = $inner;
    }

    public function findByAttempt(int $attemptId): ?array
    {
        return $this->inner->findByAttempt($attemptId);
    }

    public function compareAndSetPendingTarget(
        int $attemptId,
        string $expectedState,
        ?string $expectedStatusId,
        ?string $expectedStatus,
        string $newStatusId,
        string $newStatus
    ): bool {
        $row = $this->inner->findByAttempt($attemptId);
        if ($row === null) {
            return false;
        }
        if (!$this->matchesExpectation($row, $expectedState, $expectedStatusId, $expectedStatus)) {
            return false;
        }

        $this->inner->update($attemptId, [
            'cp_status_sync_state' => ControlPanelStatusSyncStates::PENDING,
            'cp_status_sync_status_id' => $newStatusId,
            'cp_status_sync_status' => $newStatus,
            'cp_status_sync_error_class' => null,
            'cp_status_sync_updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return true;
    }

    public function compareAndSetConfirmed(
        int $attemptId,
        string $expectedStatusId,
        string $expectedStatus
    ): bool {
        $row = $this->inner->findByAttempt($attemptId);
        if ($row === null) {
            return false;
        }
        if (!$this->matchesExpectation(
            $row,
            ControlPanelStatusSyncStates::PENDING,
            $expectedStatusId,
            $expectedStatus
        )) {
            return false;
        }

        $this->inner->update($attemptId, [
            'cp_status_sync_state' => ControlPanelStatusSyncStates::CONFIRMED,
            'cp_status_sync_status_id' => $expectedStatusId,
            'cp_status_sync_status' => $expectedStatus,
            'cp_status_sync_error_class' => null,
            'cp_status_sync_updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return true;
    }

    public function compareAndSetFailure(
        int $attemptId,
        string $expectedStatusId,
        string $expectedStatus,
        string $newState,
        string $errorClass
    ): bool {
        if (!in_array($newState, [ControlPanelStatusSyncStates::PENDING, ControlPanelStatusSyncStates::TERMINAL_FAILED], true)) {
            return false;
        }

        $row = $this->inner->findByAttempt($attemptId);
        if ($row === null) {
            return false;
        }
        if (!$this->matchesExpectation(
            $row,
            ControlPanelStatusSyncStates::PENDING,
            $expectedStatusId,
            $expectedStatus
        )) {
            return false;
        }

        $this->inner->update($attemptId, [
            'cp_status_sync_state' => $newState,
            'cp_status_sync_error_class' => $errorClass,
            'cp_status_sync_updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return true;
    }

    /** @param array<string, mixed> $row */
    private function matchesExpectation(
        array $row,
        string $expectedState,
        ?string $expectedStatusId,
        ?string $expectedStatus
    ): bool {
        $state = (string) ($row['cp_status_sync_state'] ?? '');
        if ($state !== $expectedState) {
            return false;
        }

        return $this->nullSafeEquals($row['cp_status_sync_status_id'] ?? null, $expectedStatusId)
            && $this->nullSafeEquals($row['cp_status_sync_status'] ?? null, $expectedStatus);
    }

    /** @param mixed $actual */
    private function nullSafeEquals($actual, ?string $expected): bool
    {
        if ($expected === null) {
            return $actual === null || $actual === '';
        }

        return (string) $actual === $expected;
    }
}
