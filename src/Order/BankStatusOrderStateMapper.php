<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/**
 * Optional PrestaShop order-state sync for definitive bank rejection (AUD-009).
 * Maps by stable status_id only. Never maps labels or positive/paid statuses.
 */
final class BankStatusOrderStateMapper
{
    /** @var BankStatusRejectionPolicy */
    private $rejectionPolicy;

    public function __construct(?BankStatusRejectionPolicy $rejectionPolicy = null)
    {
        $this->rejectionPolicy = $rejectionPolicy ?? new BankStatusRejectionPolicy();
    }

    /**
     * @return bool True when PrestaShop current_state was changed.
     */
    public function apply(int $idOrder, string $statusId, bool $syncRejectionEnabled): bool
    {
        if (!$syncRejectionEnabled || $idOrder <= 0) {
            return false;
        }

        $statusId = trim($statusId);
        if ($statusId === '' || !$this->rejectionPolicy->isRejection($statusId)) {
            return false;
        }

        $targetStateId = (int) \Configuration::get(OrderStateInstaller::REJECTED);
        if ($targetStateId <= 0) {
            return false;
        }

        $order = new \Order($idOrder);
        if (!\Validate::isLoadedObject($order)) {
            return false;
        }

        $currentState = (int) $order->getCurrentState();
        if ($currentState === $targetStateId) {
            return false;
        }

        $order->setCurrentState($targetStateId);

        return true;
    }
}
