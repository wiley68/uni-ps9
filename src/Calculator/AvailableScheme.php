<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Calculator;

final class AvailableScheme
{
    /** @var string */
    public $type;

    /** @var string */
    public $kopCode;

    /** @var int */
    public $months;

    /** @var int */
    public $filterId;

    /** @var array<string, mixed>|null */
    public $filter;

    /** @var array<string, mixed> */
    public $coefficient;

    /**
     * True when contributing cart lines disagree on filter-level uni_parva for the same
     * type|KOP|months identity. Membership may retain the scheme; financial calculation must not.
     *
     * @var bool
     */
    public $firstInstallmentAmbiguous;

    /**
     * @param array<string, mixed>|null $filter
     * @param array<string, mixed> $coefficient
     */
    public function __construct(
        string $type,
        string $kopCode,
        int $months,
        int $filterId,
        ?array $filter,
        array $coefficient,
        bool $firstInstallmentAmbiguous = false
    ) {
        $this->type = $type;
        $this->kopCode = $kopCode;
        $this->months = $months;
        $this->filterId = $filterId;
        $this->filter = $filter;
        $this->coefficient = $coefficient;
        $this->firstInstallmentAmbiguous = $firstInstallmentAmbiguous;
    }
}
