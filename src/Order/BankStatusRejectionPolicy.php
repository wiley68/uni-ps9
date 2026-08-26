<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/**
 * Stable SmartUCF/CP status_id values that mean definitive bank rejection (AUD-009).
 *
 * Mapping identity is status_id only — never human labels.
 * Whitelist is empty until production rejection codes are proven in CP/runtime evidence.
 */
final class BankStatusRejectionPolicy
{
    /**
     * Proven SmartUCF reqStatusCode values for definitive rejection.
     * Intentionally empty: CP/docs/tests do not prove codes for
     * "Отказана" / "Отказана от клиент" / "Отказана от клиент при контакт".
     *
     * @var list<string>
     */
    private const REJECTION_STATUS_IDS = [];

    /** @var list<string> */
    private $rejectionStatusIds;

    /**
     * @param list<string>|null $rejectionStatusIds Override for tests only; null uses production whitelist.
     */
    public function __construct(?array $rejectionStatusIds = null)
    {
        $this->rejectionStatusIds = $rejectionStatusIds ?? self::REJECTION_STATUS_IDS;
    }

    public function isRejection(string $statusId): bool
    {
        $statusId = trim($statusId);
        if ($statusId === '') {
            return false;
        }

        return in_array($statusId, $this->rejectionStatusIds, true);
    }

    /** @return list<string> */
    public function rejectionStatusIds(): array
    {
        return $this->rejectionStatusIds;
    }
}
