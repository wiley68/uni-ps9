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
     * Flush deferred native order_conf, then send leasing audience mails.
     *
     * Native order_conf flush runs first and empties the deferred queue (once).
     * If leasing notify fails afterward, bank lifecycle is unchanged and
     * leasing_email_sent stays unset — replay retries leasing mail only.
     *
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $shop
     * @param array{status_id: string, status_label: string} $status
     *
     * @throws LeasingEmailDeliveryException|\Throwable
     */
    public function send(array $snapshot, int $attemptId, array $shop, array $status): void
    {
        $snapshot['status_label'] = $status['status_label'];
        DeferredOrderMailQueue::flush($this->presenter->mailExtraVarsFromSnapshot($snapshot, $shop));
        $this->notifier->notify($snapshot, $attemptId, $shop);
    }
}
