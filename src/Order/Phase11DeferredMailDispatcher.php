<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/**
 * Phase 11 temporary mail boundary (flush deferred native order_conf only).
 *
 * Superseded in Phase 12 by {@see FinancingOrderMailDispatcher}, which flushes
 * native order_conf and sends audience-specific leasing emails.
 *
 * Kept for explicit injection in tests/tools; not the production default.
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
