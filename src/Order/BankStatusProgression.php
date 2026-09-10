<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/**
 * Local bank-status progression decisions for inbound CP callbacks.
 *
 * bank_sent_process1 and bank_sent_process2 are mutually incompatible terminal
 * alternatives (same rule as durable outbound CP status sync / CP authority).
 */
final class BankStatusProgression
{
    public const DECISION_PUSH = 'push';

    public const DECISION_SKIP_SAME = 'skip_same';

    public const DECISION_CONFLICT = 'conflict';

    /** @var list<string> */
    private const TERMINAL_SENT = [
        BankStatus::SENT_PROCESS1,
        BankStatus::SENT_PROCESS2,
    ];

    /**
     * @return self::DECISION_PUSH|self::DECISION_SKIP_SAME|self::DECISION_CONFLICT
     */
    public function compare(?string $currentStatusId, string $nextStatusId): string
    {
        $current = $this->normalize($currentStatusId);
        $next = $this->normalize($nextStatusId);
        if ($next === null) {
            return self::DECISION_PUSH;
        }

        if ($current !== null && $current === $next) {
            return self::DECISION_SKIP_SAME;
        }

        if ($this->isIncompatibleTerminalSentPair($current, $next)) {
            return self::DECISION_CONFLICT;
        }

        return self::DECISION_PUSH;
    }

    public function isIncompatibleTerminalSentPair(?string $currentStatusId, string $nextStatusId): bool
    {
        $current = $this->normalize($currentStatusId);
        $next = $this->normalize($nextStatusId);
        if ($current === null || $next === null) {
            return false;
        }

        return in_array($current, self::TERMINAL_SENT, true)
            && in_array($next, self::TERMINAL_SENT, true)
            && $current !== $next;
    }

    private function normalize(?string $statusId): ?string
    {
        if ($statusId === null) {
            return null;
        }
        $normalized = strtolower(trim($statusId));

        return $normalized === '' ? null : $normalized;
    }
}
