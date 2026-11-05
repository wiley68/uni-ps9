<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Checkout;

use PrestaShop\Module\Unipayment\Calculator\CalculationResult;
use PrestaShop\Module\Unipayment\Calculator\CoefficientResolver;
use PrestaShop\Module\Unipayment\Calculator\MonthResolver;

final class ConsentResolver
{
    /**
     * @param array<string, mixed> $shop
     * @return array<int, array{id:int,name:string,url:string,mandatory:bool,has_checkbox:bool,interest_scope:string}>
     */
    public function normalize(array $shop): array
    {
        $raw = $shop['consents'] ?? [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        $result = [];
        foreach (is_array($raw) ? $raw : [] as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = trim(strip_tags((string) ($item['name'] ?? '')));
            if ($name === '') {
                continue;
            }
            $mandatory = $this->flag($item['mandatory'] ?? 0);
            $result[] = [
                'id' => max(1, (int) ($item['id'] ?? $index + 1)),
                'name' => $name,
                'url' => filter_var((string) ($item['url'] ?? ''), FILTER_VALIDATE_URL) ?: '',
                'mandatory' => $mandatory,
                'has_checkbox' => $mandatory,
                'interest_scope' => $this->normalizeScope($item['interest_scope'] ?? null),
            ];
        }
        usort($result, static function (array $a, array $b): int {
            return $a['id'] <=> $b['id'];
        });

        return $result;
    }

    /** @param mixed $scope */
    public function normalizeScope($scope): string
    {
        return is_string($scope) && in_array($scope, ['all', 'zero_interest', 'nonzero_interest'], true) ? $scope : 'all';
    }

    /** @return array{kop_code:string,months:int,filter_id:int,scheme_type:string} */
    public function selection(CalculationResult $calculation): array
    {
        $scheme = $calculation->scheme;

        return ['kop_code' => $scheme->kopCode, 'months' => $scheme->months, 'filter_id' => $scheme->filterId, 'scheme_type' => $scheme->type];
    }

    /** @param array<string, mixed>|null $entry */
    public function interestState(?array $entry): string
    {
        $glp = $entry['interestPercent'] ?? null;
        if (!$this->finiteNumber($glp)) {
            return 'unknown';
        }

        return abs((float) $glp) <= 0.00001 ? 'zero' : 'nonzero';
    }

    /**
     * Selection must come from a resolved scheme or persisted financing snapshot, never POST.
     * Display/historical GLP is deliberately ignored; retry uses saved KOP/months.
     *
     * @param array<string, mixed> $shop
     * @param array<string, mixed> $selection
     * @return array{interest_state:string,scheme_identity:string,applicable_ids:int[],required_ids:int[],valid:bool}
     */
    public function context(array $shop, array $selection): array
    {
        $kop = (string) ($selection['kop_code'] ?? '');
        $months = (int) ($selection['months'] ?? 0);
        $entry = (new CoefficientResolver(new MonthResolver()))->find(
            is_array($shop['coeff_list'] ?? null) ? $shop['coeff_list'] : [], $kop, $months
        );
        $coeff = $entry['coeff'] ?? null;
        $state = $kop !== '' && $months > 0 && $this->finiteNumber($coeff) && (float) $coeff > 0
            ? $this->interestState($entry) : 'unknown';
        $context = [
            'interest_state' => $state,
            'scheme_identity' => json_encode([$kop, $months, (int) ($selection['filter_id'] ?? 0), (string) ($selection['scheme_type'] ?? ''), $state], JSON_THROW_ON_ERROR),
            'applicable_ids' => [],
            'required_ids' => [],
            'valid' => true,
        ];
        foreach ($this->normalize($shop) as $consent) {
            $scope = $consent['interest_scope'];
            if ($state === 'unknown' && $scope !== 'all') {
                $context['valid'] = false;
            }
            if ($scope !== 'all' && !($scope === 'zero_interest' && $state === 'zero') && !($scope === 'nonzero_interest' && $state === 'nonzero')) {
                continue;
            }
            $context['applicable_ids'][] = $consent['id'];
            if ($consent['mandatory']) {
                $context['required_ids'][] = $consent['id'];
            }
        }

        $context['applicable_ids'] = array_values(array_unique($context['applicable_ids']));
        $context['required_ids'] = array_values(array_unique($context['required_ids']));

        return $context;
    }

    /** @param mixed $accepted @return int[] */
    public function parseAcceptedIds($accepted): array
    {
        $parts = is_array($accepted) ? $accepted : (is_string($accepted) ? explode(',', $accepted) : []);
        $ids = [];
        foreach ($parts as $part) {
            if (!is_int($part) && !is_string($part)) {
                continue;
            }
            $id = filter_var($part, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @param array<string, mixed> $shop @param mixed $accepted @param array<string, mixed> $selection @return int[] */
    public function validate(array $shop, $accepted, array $selection = []): array
    {
        $context = $this->context($shop, $selection);
        if (!$context['valid']) {
            throw new CheckoutValidationException('Лихвата на избраната схема не може да бъде определена. Обновете страницата и изберете схема отново.');
        }
        $acceptedIds = $this->parseAcceptedIds($accepted);
        if (array_diff($context['required_ids'], $acceptedIds) !== []) {
            throw new CheckoutValidationException('Моля, приемете всички приложими задължителни съгласия. Ако липсват, обновете страницата.');
        }

        return array_values(array_intersect($acceptedIds, $context['applicable_ids']));
    }

    /** @param mixed $value */
    private function finiteNumber($value): bool
    {
        return (is_int($value) || is_float($value) || is_string($value)) && is_numeric($value) && is_finite((float) $value);
    }

    /** @param mixed $value */
    private function flag($value): bool
    {
        return in_array($value, [1, '1', true, 'yes', 'on', 'true'], true);
    }
}
