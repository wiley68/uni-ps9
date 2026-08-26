<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Checkout;

use PrestaShop\Module\Unipayment\Security\ClockInterface;
use PrestaShop\Module\Unipayment\Security\SystemClock;

/**
 * Short-lived atomic checkout submit lock (secondary guard before OrderAttempt reservation).
 */
final class CheckoutSubmitLock
{
    /** @var CheckoutSubmitLockRepository */
    private $repository;

    /** @var ClockInterface */
    private $clock;

    public function __construct(?CheckoutSubmitLockRepository $repository = null, ?ClockInterface $clock = null)
    {
        $this->repository = $repository ?? new CheckoutSubmitLockRepository();
        $this->clock = $clock ?? new SystemClock();
    }

    public function acquire(int $idShop, int $idCart): ?string
    {
        if ($idShop <= 0 || $idCart <= 0) {
            return null;
        }

        $ownerToken = bin2hex(random_bytes(16));
        if (!$this->repository->acquire($idShop, $idCart, $this->clock->now(), $ownerToken)) {
            return null;
        }

        return $ownerToken;
    }

    public function release(int $idShop, int $idCart, string $ownerToken): void
    {
        $this->repository->release($idShop, $idCart, $ownerToken);
    }
}
