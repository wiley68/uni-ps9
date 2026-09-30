<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/** Checks durable provenance before an existing popup submission is returned. */
final class PopupOrderReplayCurrencyGuard
{
    private OrderAttemptRepository $attempts;
    private FinancingSnapshotRepository $snapshots;
    private OrderCurrencyGuard $currencyGuard;

    public function __construct(
        ?OrderAttemptRepository $attempts = null,
        ?FinancingSnapshotRepository $snapshots = null,
        ?OrderCurrencyGuard $currencyGuard = null
    ) {
        $this->attempts = $attempts ?? new OrderAttemptRepository();
        $this->snapshots = $snapshots ?? new FinancingSnapshotRepository();
        $this->currencyGuard = $currencyGuard ?? new OrderCurrencyGuard();
    }

    /** @param array<string, mixed> $submission */
    public function assertReplay(array $submission, int $idShop): void
    {
        $idOrder = (int) ($submission['id_order'] ?? 0);
        $attemptId = (int) ($submission['id_attempt'] ?? 0);
        $attempt = $this->attempts->findById($attemptId);
        $snapshot = $this->snapshots->findByAttempt($attemptId);
        if ($idOrder <= 0 || $attempt === null || $snapshot === null
            || (int) ($attempt['id_order'] ?? 0) !== $idOrder
            || (int) ($attempt['id_shop'] ?? 0) !== $idShop
            || (int) ($snapshot['id_order'] ?? 0) !== $idOrder
            || (int) ($snapshot['id_attempt'] ?? 0) !== $attemptId
        ) {
            throw new \RuntimeException('The financing order provenance is unavailable.');
        }
        $this->currencyGuard->assertNativeSnapshot($snapshot);
        $submissionCpId = (int) ($submission['control_panel_order_id'] ?? 0);
        $hasCpResult = (string) ($attempt['state'] ?? '') === OrderOrchestrator::CP_CREATED
            || $submissionCpId > 0
            || (int) ($attempt['control_panel_order_id'] ?? 0) > 0
            || (int) ($snapshot['control_panel_order_id'] ?? 0) > 0;
        if ($hasCpResult) {
            (new ControlPanelSuccessReplayGuard($this->currencyGuard))->assertSuccessful(
                $attempt, $snapshot, $submissionCpId
            );
        } elseif (!empty($attempt['cp_payload'])) {
            // Failed or ambiguous CP attempts may have a frozen payload, but cannot become success.
            $this->currencyGuard->decodeSavedCpPayload($attempt['cp_payload'], $snapshot);
        }
    }
}
