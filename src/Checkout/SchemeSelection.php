<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Checkout;

final class SchemeSelection
{
    /** @var string */
    public $schemeType;
    /** @var int */
    public $months;
    /** @var int */
    public $filterId;
    /** @var string */
    public $kopCode;
    /** @var float */
    public $firstInstallment;

    public function __construct(string $schemeType, int $months, int $filterId, string $kopCode, float $firstInstallment)
    {
        $this->schemeType = $schemeType;
        $this->months = $months;
        $this->filterId = $filterId;
        $this->kopCode = $kopCode;
        $this->firstInstallment = $firstInstallment;
    }

    /** @param array<string, mixed> $posted */
    public static function fromPosted(array $posted): self
    {
        $key = trim((string) ($posted['scheme_key'] ?? ''));
        $schemeType = 'standard';
        if (strpos($key, 'p:') === 0) {
            $schemeType = 'promo';
            $key = substr($key, 2);
        }
        $parts = explode(':', $key, 2);
        $months = ctype_digit($parts[0] ?? '') ? (int) $parts[0] : 0;
        $filterId = ctype_digit($parts[1] ?? '') ? (int) $parts[1] : 0;
        $kop = trim((string) ($posted['kop_code'] ?? ''));
        $firstRaw = $posted['first_installment'] ?? 0;
        $first = is_numeric($firstRaw) ? (float) $firstRaw : 0.0;

        return new self($schemeType, $months, $filterId, $kop, $first);
    }

    public static function key(string $type, int $months, int $filterId): string
    {
        return ($type === 'promo' ? 'p:' : '') . $months . ':' . $filterId;
    }
}
