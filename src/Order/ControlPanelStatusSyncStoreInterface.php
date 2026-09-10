<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/**
 * Durable CP status-sync persistence with compare-and-set transitions.
 */
interface ControlPanelStatusSyncStoreInterface
{
    /** @return array<string, mixed>|null */
    public function findByAttempt(int $attemptId): ?array;

    /**
     * Install/replace pending target only if expected state/target still match.
     *
     * @return bool true when the row was updated
     */
    public function compareAndSetPendingTarget(
        int $attemptId,
        string $expectedState,
        ?string $expectedStatusId,
        ?string $expectedStatus,
        string $newStatusId,
        string $newStatus
    ): bool;

    /**
     * Confirm pending target T only if still pending with exact T.
     *
     * @return bool true when the row was updated
     */
    public function compareAndSetConfirmed(
        int $attemptId,
        string $expectedStatusId,
        string $expectedStatus
    ): bool;

    /**
     * Record pending/terminal failure for exact pending target T only.
     *
     * @return bool true when the row was updated
     */
    public function compareAndSetFailure(
        int $attemptId,
        string $expectedStatusId,
        string $expectedStatus,
        string $newState,
        string $errorClass
    ): bool;
}
