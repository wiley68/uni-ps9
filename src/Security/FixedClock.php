<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Security;

final class FixedClock implements ClockInterface
{
    /** @var int */
    private $now;

    public function __construct(int $now)
    {
        $this->now = $now;
    }

    public function now(): int
    {
        return $this->now;
    }
}
