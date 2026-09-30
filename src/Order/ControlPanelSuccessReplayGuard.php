<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/** Proves a persisted CP create succeeded before a post-CP replay may continue. */
final class ControlPanelSuccessReplayGuard
{
    private OrderCurrencyGuard $currencyGuard;
    /** @var callable|null */
    private $attemptReader;

    /** @param callable|null $attemptReader fn(int): ?array */
    public function __construct(?OrderCurrencyGuard $currencyGuard = null, ?callable $attemptReader = null)
    {
        $this->currencyGuard = $currencyGuard ?? new OrderCurrencyGuard();
        $this->attemptReader = $attemptReader;
    }

    /** @param array<string, mixed> $snapshot */
    public function assertAttempt(int $attemptId, array $snapshot, ?int $expectedCpId = null): void
    {
        $attempt = $this->attemptReader !== null
            ? call_user_func($this->attemptReader, $attemptId)
            : (new OrderAttemptRepository())->findById($attemptId);
        $this->assertSuccessful(is_array($attempt) ? $attempt : null, $snapshot, $expectedCpId);
    }

    /** @param array<string, mixed>|null $attempt @param array<string, mixed> $snapshot */
    public function assertSuccessful(?array $attempt, array $snapshot, ?int $expectedCpId = null): void
    {
        $attemptId = (int) ($attempt['id_attempt'] ?? 0);
        $idOrder = (int) ($attempt['id_order'] ?? 0);
        $cpId = (int) ($attempt['control_panel_order_id'] ?? 0);
        if ($attemptId <= 0
            || $idOrder <= 0
            || (string) ($attempt['state'] ?? '') !== OrderOrchestrator::CP_CREATED
            || (string) ($snapshot['lifecycle_status'] ?? '') !== OrderOrchestrator::CP_CREATED
            || (int) ($snapshot['id_attempt'] ?? 0) !== $attemptId
            || (int) ($snapshot['id_order'] ?? 0) !== $idOrder
            || $cpId <= 0
            || (int) ($snapshot['control_panel_order_id'] ?? 0) !== $cpId
            || ($expectedCpId !== null && ($expectedCpId <= 0 || $expectedCpId !== $cpId))
        ) {
            throw new \RuntimeException('The successful Control Panel create cannot be proven.');
        }
        $this->currencyGuard->decodeSavedCpPayload($attempt['cp_payload'] ?? null, $snapshot);
    }
}
