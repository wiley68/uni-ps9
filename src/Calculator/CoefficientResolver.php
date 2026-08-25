<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Calculator;

final class CoefficientResolver
{
    private $months;

    public function __construct(MonthResolver $months)
    {
        $this->months = $months;
    }

    /** @param array<int, mixed> $coefficients @return array<string, mixed>|null */
    public function find(array $coefficients, string $kopCode, int $months): ?array
    {
        foreach ($coefficients as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (trim((string) ($entry['onlineProductCode'] ?? '')) === $kopCode
                && (int) ($entry['installmentCount'] ?? 0) === $months) {
                return $entry;
            }
        }

        return null;
    }

    /** @param array<int, mixed> $coefficients @param int[] $allowed @return array<string, mixed>|null */
    public function findPreferredOrHighest(array $coefficients, string $kopCode, array $allowed, int $preferred): ?array
    {
        if ($preferred > 0 && in_array($preferred, $allowed, true)) {
            $entry = $this->find($coefficients, $kopCode, $preferred);
            if ($entry !== null) {
                return $entry;
            }
        }

        $best = null;
        $bestMonths = 0;
        foreach ($coefficients as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $entryMonths = (int) ($entry['installmentCount'] ?? 0);
            if (trim((string) ($entry['onlineProductCode'] ?? '')) !== $kopCode
                || !$this->months->isValid($entryMonths)
                || !in_array($entryMonths, $allowed, true)) {
                continue;
            }
            if ($entryMonths > $bestMonths) {
                $best = $entry;
                $bestMonths = $entryMonths;
            }
        }

        return $best;
    }

    /** @param array<string, mixed> $entry */
    public function isZeroInterest(array $entry): bool
    {
        return array_key_exists('interestPercent', $entry)
            && abs((float) $entry['interestPercent']) <= 0.00001;
    }
}
