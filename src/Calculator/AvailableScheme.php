<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Calculator;

final class AvailableScheme
{
    public $type;
    public $kopCode;
    public $months;
    public $filterId;
    public $filter;
    public $coefficient;

    /** @param array<string, mixed>|null $filter @param array<string, mixed> $coefficient */
    public function __construct(string $type, string $kopCode, int $months, int $filterId, ?array $filter, array $coefficient)
    {
        $this->type = $type;
        $this->kopCode = $kopCode;
        $this->months = $months;
        $this->filterId = $filterId;
        $this->filter = $filter;
        $this->coefficient = $coefficient;
    }
}
