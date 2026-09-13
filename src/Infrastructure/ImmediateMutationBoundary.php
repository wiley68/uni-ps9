<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Infrastructure;

/**
 * No-lock / no-transaction boundary for unit tests.
 */
final class ImmediateMutationBoundary implements MutationBoundaryInterface
{
    public function runExclusive(string $lockName, callable $callback)
    {
        return $callback();
    }
}
