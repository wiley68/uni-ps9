<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Security;

final class SystemClock implements ClockInterface
{
    public function now(): int
    {
        return time();
    }
}
