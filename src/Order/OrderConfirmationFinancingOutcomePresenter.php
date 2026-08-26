<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/**
 * Native order-confirmation notice from persisted UniCredit operational status.
 *
 * Source of truth is the order-bound bank-status row. Internal snapshot
 * lifecycle_status distinguishes CP outcome-unknown from definitive CP failure
 * without inventing a new cross-platform status_id.
 */
final class OrderConfirmationFinancingOutcomePresenter
{
    public const NONE = 'none';
    public const SMARTUCF_FAILED = 'smartucf_failed';
    public const CP_FAILED = 'cp_failed';
    public const CP_OUTCOME_UNKNOWN = 'cp_outcome_unknown';

    /** @var BankStatusReaderPort */
    private $bankStatuses;

    /** @var FinancingSnapshotByOrderReaderPort|null */
    private $snapshots;

    public function __construct(
        ?BankStatusReaderPort $bankStatuses = null,
        ?FinancingSnapshotByOrderReaderPort $snapshots = null
    ) {
        $this->bankStatuses = $bankStatuses ?? new OrderBankStatusRepository();
        $this->snapshots = $snapshots;
    }

    public function outcome(int $idOrder): string
    {
        if ($idOrder <= 0) {
            return self::NONE;
        }

        $bankStatus = $this->bankStatuses->findByOrderId($idOrder);
        $statusId = (string) ($bankStatus['status_id'] ?? '');
        if ($statusId === BankStatus::SEND_FAILED_SMARTUCF) {
            return self::SMARTUCF_FAILED;
        }

        if ($statusId !== BankStatus::SEND_FAILED_CP && $statusId !== BankStatus::SEND_FAILED) {
            return self::NONE;
        }

        if ($this->isOutcomeUnknown($idOrder)) {
            return self::CP_OUTCOME_UNKNOWN;
        }

        return self::CP_FAILED;
    }

    private function isOutcomeUnknown(int $idOrder): bool
    {
        if ($this->snapshots === null) {
            return false;
        }

        $snapshot = $this->snapshots->findByOrderId($idOrder);

        return (string) ($snapshot['lifecycle_status'] ?? '') === OrderOrchestrator::CP_OUTCOME_UNKNOWN;
    }
}
