<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

use PrestaShop\Module\Unipayment\Security\ClockInterface;
use PrestaShop\Module\Unipayment\Security\SystemClock;

/**
 * Opportunistic PII redaction for financing snapshots older than the retention window.
 */
final class FinancingSnapshotRetentionService
{
    public const RETENTION_DAYS = 180;

    public const BATCH_SIZE = 200;

    public const THROTTLE_SECONDS = 86400;

    public const LAST_CLEANUP_KEY = 'UNIPAYMENT_LAST_PRIVACY_CLEANUP';

    /** @var FinancingSnapshotRepository */
    private $snapshots;

    /** @var ClockInterface */
    private $clock;

    public function __construct(?FinancingSnapshotRepository $snapshots = null, ?ClockInterface $clock = null)
    {
        $this->snapshots = $snapshots ?? new FinancingSnapshotRepository();
        $this->clock = $clock ?? new SystemClock();
    }

    public function maybeRun(): int
    {
        $now = $this->clock->now();
        $lastAttempt = (int) \Configuration::get(self::LAST_CLEANUP_KEY);
        if ($lastAttempt > 0 && ($now - $lastAttempt) < self::THROTTLE_SECONDS) {
            return 0;
        }

        \Configuration::updateValue(self::LAST_CLEANUP_KEY, (string) $now);

        try {
            return $this->snapshots->redactExpiredPii($this->retentionCutoff($now), self::BATCH_SIZE);
        } catch (\Throwable $exception) {
            \PrestaShopLogger::addLog('UniPayment financing snapshot privacy cleanup failed.', 2);

            return 0;
        }
    }

    public function retentionCutoff(?int $now = null): string
    {
        $now = $now ?? $this->clock->now();

        return gmdate('Y-m-d H:i:s', $now - (self::RETENTION_DAYS * 86400));
    }
}
