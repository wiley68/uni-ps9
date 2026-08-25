<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Calculator;

final class PreferredOfferSelector
{
    /** @param Offer[] $offers */
    public function select(array $offers, int $preferredMonths): ?Offer
    {
        if ($offers === []) {
            return null;
        }
        $matches = $preferredMonths > 0 ? array_values(array_filter($offers, static function (Offer $offer) use ($preferredMonths): bool {
            return $offer->months === $preferredMonths;
        })) : [];
        if ($matches === []) {
            $highest = max(array_map(static function (Offer $offer): int {
                return $offer->months;
            }, $offers));
            $matches = array_values(array_filter($offers, static function (Offer $offer) use ($highest): bool {
                return $offer->months === $highest;
            }));
        }
        usort($matches, static function (Offer $a, Offer $b): int {
            return $a->monthlyInstallment <=> $b->monthlyInstallment;
        });

        return $matches[0] ?? null;
    }
}
