<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/**
 * Whether native order-confirmation should show the post-order SmartUCF failure notice.
 *
 * Source of truth is the persisted UniCredit bank status for this order.
 */
final class OrderConfirmationSmartUcfFailurePresenter
{
    /** @var BankStatusReaderPort */
    private $bankStatuses;

    public function __construct(?BankStatusReaderPort $bankStatuses = null)
    {
        $this->bankStatuses = $bankStatuses ?? new OrderBankStatusRepository();
    }

    public function shouldDisplay(int $idOrder): bool
    {
        if ($idOrder <= 0) {
            return false;
        }

        $bankStatus = $this->bankStatuses->findByOrderId($idOrder);

        return (string) ($bankStatus['status_id'] ?? '') === BankStatus::SEND_FAILED_SMARTUCF;
    }
}
