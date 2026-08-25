<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Calculator;

final class FirstInstallmentState
{
    public $amount;
    public $locked;
    public $visible;

    public function __construct(float $amount, bool $locked, bool $visible)
    {
        $this->amount = $amount;
        $this->locked = $locked;
        $this->visible = $visible;
    }
}
