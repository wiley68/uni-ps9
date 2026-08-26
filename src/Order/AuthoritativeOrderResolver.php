<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/**
 * Chooses the single authoritative native order after PaymentModule::validateOrder().
 *
 * PS may materialize multiple Order rows for one cart/reference when package/delivery
 * option state is stale (empty twin + real twin). Never bind financing to an empty twin.
 */
final class AuthoritativeOrderResolver
{
    /**
     * @param list<CreatedOrder> $candidates Orders already loaded for the expected cart
     * @throws \RuntimeException when no safe authoritative order exists
     */
    public function resolve(array $candidates, int $preferredOrderId = 0): CreatedOrder
    {
        $withLines = [];
        foreach ($candidates as $candidate) {
            if (!$candidate instanceof CreatedOrder) {
                continue;
            }
            if ($candidate->idOrder <= 0) {
                continue;
            }
            if ($candidate->lines === []) {
                continue;
            }
            $withLines[] = $candidate;
        }

        if ($withLines === []) {
            throw new \RuntimeException('The financing order has no order lines.');
        }

        if ($preferredOrderId > 0) {
            foreach ($withLines as $candidate) {
                if ($candidate->idOrder === $preferredOrderId) {
                    return $candidate;
                }
            }
        }

        if (count($withLines) === 1) {
            return $withLines[0];
        }

        throw new \RuntimeException('Multiple non-empty financing orders exist for the same cart.');
    }

    public function hasLines(CreatedOrder $order): bool
    {
        return $order->lines !== [];
    }
}
