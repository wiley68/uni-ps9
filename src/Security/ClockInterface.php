<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Security;

interface ClockInterface
{
    public function now(): int;
}
