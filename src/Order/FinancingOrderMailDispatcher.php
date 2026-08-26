<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

final class FinancingOrderMailDispatcher implements LeasingMailDispatchPort
{
    private LeasingOrderEmailPresenter $presenter;
    private LeasingEmailNotifier $notifier;

    public function __construct(?LeasingOrderEmailPresenter $presenter = null, ?LeasingEmailNotifier $notifier = null)
    {
        $this->presenter = $presenter ?? new LeasingOrderEmailPresenter();
        $this->notifier = $notifier ?? new LeasingEmailNotifier();
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $shop
     * @param array{status_id: string, status_label: string} $status
     */
    public function send(array $snapshot, int $attemptId, array $shop, array $status): void
    {
        $snapshot['status_label'] = $status['status_label'];
        DeferredOrderMailQueue::flush($this->presenter->mailExtraVarsFromSnapshot($snapshot, $shop));
        $this->notifier->notify($snapshot, $attemptId, $shop);
    }
}
