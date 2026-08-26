<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCoordinationResult;

/**
 * SmartUCF coordination boundary for post-CP lifecycle (implemented by SmartUcfSessionCoordinator).
 */
interface PostControlPanelSmartUcfPort
{
    /**
     * @param array<string, mixed> $shop
     * @param array<string, mixed>|null $snapshot
     */
    public function run(int $attemptId, array $shop, bool $process2, ?array $snapshot = null): SmartUcfCoordinationResult;

    /**
     * @param array<string, mixed> $shop
     */
    public function resume(int $attemptId, array $shop, bool $process2): SmartUcfCoordinationResult;
}
