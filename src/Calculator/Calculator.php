<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Calculator;

final class Calculator
{
    /** @var MonthResolver */
    private $months;

    /** @var SchemaFilterMatcher */
    private $matcher;

    /** @var CoefficientResolver */
    private $coefficients;

    /** @var OfferFactory */
    private $offers;

    /** @var PreferredOfferSelector */
    private $selector;

    /** @var FirstInstallmentResolver */
    private $firstInstallment;

    /** @var FinancialCalculator */
    private $financial;

    public function __construct(?string $today = null)
    {
        $this->months = new MonthResolver();
        $this->matcher = new SchemaFilterMatcher($this->months, $today);
        $this->coefficients = new CoefficientResolver($this->months);
        $this->financial = new FinancialCalculator();
        $this->offers = new OfferFactory($this->financial);
        $this->selector = new PreferredOfferSelector();
        $this->firstInstallment = new FirstInstallmentResolver($this->months);
    }

    /** @param array<string, mixed> $shop */
    public function isAvailableForAmount(array $shop, float $price): bool
    {
        if (!$this->months->isEnabledFlag($shop['uni_status'] ?? 0)) {
            return false;
        }

        return $price >= (float) ($shop['uni_minstojnost'] ?? 0)
            && $price <= (float) ($shop['uni_maxstojnost'] ?? 0);
    }

    /** @param array<string, mixed> $shop @return array{standard:?Offer,promo:?Offer} */
    public function resolvePreferredOffers(array $shop, ProductContext $product): array
    {
        if (!$this->isAvailableForAmount($shop, $product->price)) {
            return ['standard' => null, 'promo' => null];
        }

        return [
            'standard' => $this->resolvePreferredOffer($shop, $product, 'standard'),
            'promo' => $this->resolvePreferredOffer($shop, $product, 'promo'),
        ];
    }

    /** @param array<string, mixed> $shop */
    public function resolvePreferredOffer(array $shop, ProductContext $product, string $type): ?Offer
    {
        $mode = (int) ($shop['uni_typekop'] ?? -1);
        if ($mode === 0 && $type === 'standard') {
            $byDefault = $this->byDefault($shop);
            $kop = trim((string) ($byDefault['uni_kop_default'] ?? ''));
            $preferred = (int) ($shop['uni_shema_current'] ?? 0);
            $entry = $this->coefficients->find($this->coefficientList($shop), $kop, $preferred);

            return $entry !== null ? $this->offers->create('standard', $kop, $preferred, $product->price, $entry) : null;
        }
        if ($mode === 0 && $type === 'promo') {
            $settings = $this->byDefault($shop);
            $kop = trim((string) ($settings['uni_kop_promo'] ?? ''));
            $allowed = $this->months->defaultPromoMonths(
                $settings,
                $product->price,
                range(MonthResolver::MIN, MonthResolver::MAX)
            );
            $entry = $this->coefficients->findPreferredOrHighest(
                $this->coefficientList($shop),
                $kop,
                $allowed,
                (int) ($shop['uni_shema_current'] ?? 0)
            );
            if ($entry === null || !$this->coefficients->isZeroInterest($entry)) {
                return null;
            }

            return $this->offers->create(
                'promo',
                $kop,
                (int) ($entry['installmentCount'] ?? 0),
                $product->price,
                $entry
            );
        }
        if ($mode !== 1) {
            return null;
        }

        $filters = $shop['kop']['by_schema']['filters'] ?? [];
        $candidates = [];
        foreach (is_array($filters) ? $filters : [] as $filter) {
            if (
                !is_array($filter)
                || (int) ($filter['uni_promo'] ?? 0) !== ($type === 'promo' ? 1 : 0)
                || !$this->matcher->matches($filter, $product)
            ) {
                continue;
            }
            $kop = trim((string) ($filter['uni_kop'] ?? ''));
            $allowed = $this->months->allowedForFilter($filter, $shop);
            $entry = $this->coefficients->findPreferredOrHighest(
                $this->coefficientList($shop),
                $kop,
                $allowed,
                (int) ($shop['uni_shema_current'] ?? 0)
            );
            if ($kop === '' || $entry === null || ($type === 'promo' && !$this->coefficients->isZeroInterest($entry))) {
                continue;
            }
            $scheme = new AvailableScheme(
                $type,
                $kop,
                (int) ($entry['installmentCount'] ?? 0),
                (int) ($filter['id'] ?? 0),
                $filter,
                $entry
            );
            $amount = $product->price;
            if ((int) ($filter['uni_parva'] ?? 0) === 1) {
                $amount = round($product->price - round($product->price / $scheme->months, 2), 2);
            }
            $offer = $this->offers->create($type, $scheme->kopCode, $scheme->months, $amount, $scheme->coefficient, $scheme->filterId);
            if ($offer !== null) {
                $candidates[] = $offer;
            }
        }

        return $this->selector->select($candidates, (int) ($shop['uni_shema_current'] ?? 0));
    }

    /** @param array<string, mixed> $shop @return AvailableScheme[] */
    public function availableSchemes(array $shop, ProductContext $product, string $type, bool $shopMonthsOnly = true): array
    {
        if (!in_array($type, ['standard', 'promo'], true) || !$this->isAvailableForAmount($shop, $product->price)) {
            return [];
        }
        $mode = (int) ($shop['uni_typekop'] ?? -1);

        return $mode === 0
            ? $this->defaultSchemes($shop, $product, $type, $shopMonthsOnly)
            : ($mode === 1 ? $this->schemaSchemes($shop, $product, $type) : []);
    }

    /** @param array<string, mixed> $shop */
    public function calculate(array $shop, ProductContext $product, int $months, string $type, float $requestedFirstInstallment = 0.0, int $filterId = 0): CalculationResult
    {
        $matches = array_values(array_filter(
            $this->availableSchemes($shop, $product, $type),
            static function (AvailableScheme $scheme) use ($months, $filterId): bool {
                return $scheme->months === $months && ($filterId <= 0 || $scheme->filterId === $filterId);
            }
        ));
        if ($filterId <= 0 && count($matches) > 1) {
            usort($matches, static function (AvailableScheme $a, AvailableScheme $b): int {
                return (int) ($b->filter['uni_parva'] ?? 0) <=> (int) ($a->filter['uni_parva'] ?? 0);
            });
        }
        $scheme = $matches[0] ?? null;
        if (!$scheme instanceof AvailableScheme) {
            throw new UnavailableSchemeException('The selected financing scheme is not available.');
        }
        return $this->calculateScheme($shop, $product->price, $scheme, $requestedFirstInstallment);
    }

    /** @param array<string, mixed> $shop */
    public function calculateScheme(array $shop, float $price, AvailableScheme $scheme, float $requestedFirstInstallment = 0.0): CalculationResult
    {
        if ($scheme->firstInstallmentAmbiguous) {
            throw new UnavailableSchemeException('The selected financing scheme has an ambiguous first-installment policy.');
        }
        $first = $this->firstInstallment->resolve($shop, $price, $scheme->months, $requestedFirstInstallment, $scheme->filter);
        $financed = round($price - $first->amount, 2);
        $kimb = (float) ($scheme->coefficient['coeff'] ?? 0);
        if ($financed <= 0 || $kimb <= 0) {
            throw new UnavailableSchemeException('The selected financing scheme cannot be calculated.');
        }
        $monthly = round($financed * $kimb, 2);
        $gpr = $this->financial->calculateGpr($scheme->months, $monthly, $financed);

        return new CalculationResult(
            $scheme,
            round($price, 2),
            $first,
            $financed,
            $monthly,
            round($monthly * $scheme->months, 2),
            round(abs((float) ($scheme->coefficient['interestPercent'] ?? 0)), 2),
            $gpr <= 0.1 ? 0.0 : round($gpr, 2)
        );
    }

    public function createButtonOffer(AvailableScheme $scheme, float $amount, string $buttonType): ?Offer
    {
        return $this->offers->create($buttonType, $scheme->kopCode, $scheme->months, $amount, $scheme->coefficient, $scheme->filterId);
    }

    /** @param Offer[] $offers */
    public function selectPreferredOffer(array $offers, int $preferredMonths): ?Offer
    {
        return $this->selector->select($offers, $preferredMonths);
    }

    /** @param array<string, mixed> $shop @return AvailableScheme[] */
    private function defaultSchemes(array $shop, ProductContext $product, string $type, bool $shopMonthsOnly): array
    {
        $settings = $this->byDefault($shop);
        $kop = trim((string) ($settings[$type === 'promo' ? 'uni_kop_promo' : 'uni_kop_default'] ?? ''));
        if ($kop === '') {
            return [];
        }
        $candidateMonths = $shopMonthsOnly ? $this->months->enabledMonths($shop) : range(MonthResolver::MIN, MonthResolver::MAX);
        if ($type === 'promo') {
            $candidateMonths = $this->months->defaultPromoMonths($settings, $product->price, $candidateMonths);
        }
        $result = [];
        foreach ($candidateMonths as $months) {
            $entry = $this->coefficients->find($this->coefficientList($shop), $kop, $months);
            if ($entry === null || ($type === 'promo' && !$this->coefficients->isZeroInterest($entry))) {
                continue;
            }
            $result[] = new AvailableScheme($type, $kop, $months, 0, null, $entry);
        }

        return $result;
    }

    /** @param array<string, mixed> $shop @return AvailableScheme[] */
    private function schemaSchemes(array $shop, ProductContext $product, string $type): array
    {
        $filters = $shop['kop']['by_schema']['filters'] ?? [];
        if (!is_array($filters)) {
            return [];
        }
        $result = [];
        foreach ($filters as $filter) {
            if (
                !is_array($filter)
                || (int) ($filter['uni_promo'] ?? 0) !== ($type === 'promo' ? 1 : 0)
                || !$this->matcher->matches($filter, $product)
            ) {
                continue;
            }
            $kop = trim((string) ($filter['uni_kop'] ?? ''));
            if ($kop === '') {
                continue;
            }
            foreach ($this->months->allowedForFilter($filter, $shop) as $months) {
                $entry = $this->coefficients->find($this->coefficientList($shop), $kop, $months);
                if ($entry === null || ($type === 'promo' && !$this->coefficients->isZeroInterest($entry))) {
                    continue;
                }
                $result[] = new AvailableScheme($type, $kop, $months, (int) ($filter['id'] ?? 0), $filter, $entry);
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $shop @return array<string, mixed> */
    private function byDefault(array $shop): array
    {
        return is_array($shop['kop']['by_default'] ?? null) ? $shop['kop']['by_default'] : [];
    }

    /** @param array<string, mixed> $shop @return array<int, mixed> */
    private function coefficientList(array $shop): array
    {
        return is_array($shop['coeff_list'] ?? null) ? $shop['coeff_list'] : [];
    }
}
