<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/**
 * Phase 11 mail boundary: flush deferred native order_conf only.
 * Full customer/admin financing leasing emails remain Phase 12.
 */
final class Phase11DeferredMailDispatcher implements LeasingMailDispatchPort
{
    /** @var LeasingOrderEmailPresenter */
    private $presenter;

    public function __construct(?LeasingOrderEmailPresenter $presenter = null)
    {
        $this->presenter = $presenter ?? new LeasingOrderEmailPresenter();
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $shop
     * @param array{status_id: string, status_label: string} $status
     */
    public function send(array $snapshot, int $attemptId, array $shop, array $status): void
    {
        unset($attemptId);
        $snapshot['status_label'] = $status['status_label'];
        DeferredOrderMailQueue::flush($this->presenter->mailExtraVarsFromSnapshot($snapshot, $shop));
    }
}
