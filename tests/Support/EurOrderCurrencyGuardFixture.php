<?php

declare(strict_types=1);

use PrestaShop\Module\Unipayment\Order\OrderCurrencyGuard;
use PrestaShop\Module\Unipayment\Order\PostControlPanelLifecycleService;

function eurTestOrderCurrencyGuard(): OrderCurrencyGuard
{
    return new OrderCurrencyGuard(static function (int $idOrder): array {
        unset($idOrder);
        return ['id_currency' => 1, 'currency_iso' => 'EUR'];
    });
}

/** Test seam for lifecycle tests whose subject is unrelated to CP persistence. */
function eurTestCpSuccessVerifier(): callable
{
    return static function (int $attemptId, array $snapshot, ?int $cpId = null): void {
        unset($attemptId, $snapshot, $cpId);
    };
}

/** @param mixed ...$args */
function eurTestPostService(mixed ...$args): PostControlPanelLifecycleService
{
    while (count($args) < 6) {
        $args[] = null;
    }
    $args[] = eurTestOrderCurrencyGuard();
    $args[] = eurTestCpSuccessVerifier();

    return new PostControlPanelLifecycleService(...$args);
}
