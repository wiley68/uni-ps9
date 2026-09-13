<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Infrastructure;

interface MutationBoundaryInterface
{
    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function runExclusive(string $lockName, callable $callback);
}
