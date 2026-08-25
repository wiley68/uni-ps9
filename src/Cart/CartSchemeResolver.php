<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Cart;

use PrestaShop\Module\Unipayment\Calculator\AvailableScheme;
use PrestaShop\Module\Unipayment\Calculator\Calculator;

final class CartSchemeResolver
{
    /** @var Calculator */
    private $calculator;

    public function __construct(Calculator $calculator)
    {
        $this->calculator = $calculator;
    }

    /** @param array<string, mixed> $shop */
    public function resolve(array $shop, CartContext $cart): CartResolution
    {
        if ($cart->lines === [] || !$this->calculator->isAvailableForAmount($shop, $cart->total)) {
            return new CartResolution([], [], null, null);
        }

        $standardSets = [];
        $promoSets = [];
        foreach ($cart->lines as $line) {
            // Intentional Woo parity: every line keeps its product/category identity, but
            // price, promo and range rules are evaluated against the tax-inclusive cart total.
            $product = clone $line->product;
            $product->price = $cart->total;
            $standard = $this->calculator->availableSchemes($shop, $product, 'standard');
            $promo = $this->calculator->availableSchemes($shop, $product, 'promo');
            if ((int) ($shop['uni_typekop'] ?? -1) === 1) {
                $standard = array_merge($standard, $promo);
            }
            $standardSets[] = $standard;
            $promoSets[] = $promo;
        }

        $standard = $this->intersect($standardSets);
        $promo = $this->intersect($promoSets);

        return new CartResolution(
            $standard,
            $promo,
            $this->preferred($standard, $cart->total, 'standard', (int) ($shop['uni_shema_current'] ?? 0)),
            $this->preferred($promo, $cart->total, 'promo', (int) ($shop['uni_shema_current'] ?? 0))
        );
    }

    /** @return AvailableScheme[] */
    public function unifiedSchemes(CartResolution $resolution): array
    {
        $schemes = $resolution->standardSchemes;
        $seen = [];
        foreach ($schemes as $scheme) {
            $seen[$this->key($scheme)] = true;
        }
        foreach ($resolution->promoSchemes as $scheme) {
            if (isset($seen[$this->key($scheme)])) {
                continue;
            }
            $schemes[] = $scheme;
            $seen[$this->key($scheme)] = true;
        }

        return $schemes;
    }

    /** @param array<int, AvailableScheme[]> $sets @return AvailableScheme[] */
    public function intersect(array $sets): array
    {
        if ($sets === []) {
            return [];
        }
        $common = array_values($sets[0]);
        foreach ($sets as $set) {
            $keys = [];
            foreach ($set as $scheme) {
                $keys[$this->key($scheme)] = true;
            }
            $common = array_values(array_filter($common, function (AvailableScheme $scheme) use ($keys): bool {
                return isset($keys[$this->key($scheme)]);
            }));
            if ($common === []) {
                return [];
            }
        }

        // Intentional Woo parity: filterId is metadata. Intersection identity is only
        // scheme type + KOP + months, so different matching filters remain compatible.
        $existing = [];
        foreach ($common as $scheme) {
            $existing[$this->key($scheme)] = true;
        }
        foreach ($this->groups($sets) as $group) {
            $lineMonthSets = [];
            foreach ($sets as $set) {
                $months = [];
                foreach ($set as $scheme) {
                    if ($scheme->type === $group['type'] && $scheme->kopCode === $group['kop']) {
                        $months[] = $scheme->months;
                    }
                }
                if ($months === []) {
                    continue 2;
                }
                $lineMonthSets[] = $months;
            }
            $lineLcms = array_map([$this, 'lcm'], $lineMonthSets);
            $target = $this->lcm($lineLcms);
            $key = $group['type'] . '|' . $group['kop'] . '|' . $target;
            if ($target <= 0 || isset($existing[$key])) {
                continue;
            }
            $template = null;
            foreach ($sets as $set) {
                $found = null;
                foreach ($set as $scheme) {
                    if ($this->key($scheme) === $key) {
                        $found = $scheme;
                        break;
                    }
                }
                if ($found === null) {
                    continue 2;
                }
                $template = $found;
            }
            if ($template !== null) {
                $common[] = $template;
                $existing[$key] = true;
            }
        }
        usort($common, static function (AvailableScheme $a, AvailableScheme $b): int {
            if ($a->months !== $b->months) {
                return $a->months <=> $b->months;
            }
            $typeOrder = ['standard' => 0, 'promo' => 1];
            $typeComparison = ($typeOrder[$a->type] ?? 99) <=> ($typeOrder[$b->type] ?? 99);

            return $typeComparison !== 0 ? $typeComparison : $a->filterId <=> $b->filterId;
        });

        return $common;
    }

    /** @param AvailableScheme[] $schemes */
    private function preferred(array $schemes, float $total, string $buttonType, int $preferredMonths)
    {
        $offers = [];
        foreach ($schemes as $scheme) {
            if ($buttonType === 'promo' && abs((float) ($scheme->coefficient['interestPercent'] ?? -1)) > 0.00001) {
                continue;
            }
            $offer = $this->calculator->createButtonOffer($scheme, $total, $buttonType);
            if ($offer !== null) {
                $offers[] = $offer;
            }
        }

        return $this->calculator->selectPreferredOffer($offers, $preferredMonths);
    }

    private function key(AvailableScheme $scheme): string
    {
        return $scheme->type . '|' . $scheme->kopCode . '|' . $scheme->months;
    }

    /** @param array<int, AvailableScheme[]> $sets @return array<int, array{type:string,kop:string}> */
    private function groups(array $sets): array
    {
        $groups = [];
        foreach ($sets as $set) {
            foreach ($set as $scheme) {
                $key = $scheme->type . '|' . $scheme->kopCode;
                $groups[$key] = ['type' => $scheme->type, 'kop' => $scheme->kopCode];
            }
        }

        return array_values($groups);
    }

    /** @param int[] $values */
    public function lcm(array $values): int
    {
        $values = array_values(array_filter(array_map('abs', $values)));
        if ($values === []) {
            return 0;
        }
        $result = (int) $values[0];
        foreach (array_slice($values, 1) as $value) {
            $result = (int) (($result / $this->gcd($result, (int) $value)) * $value);
        }

        return $result;
    }

    private function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            $temp = $b;
            $b = $a % $b;
            $a = $temp;
        }

        return max(1, abs($a));
    }
}
