<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Checkout;

final class ConsentResolver
{
    /**
     * @param array<string, mixed> $shop
     * @return array<int, array{id:int,name:string,url:string,mandatory:bool,has_checkbox:bool}>
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
            ];
        }
        usort($result, static function (array $a, array $b): int {
            return $a['id'] <=> $b['id'];
        });

        return $result;
    }

    /** @param array<string, mixed> $shop @param mixed $accepted @return int[] */
    public function validate(array $shop, $accepted): array
    {
        $accepted = is_array($accepted) ? $accepted : (is_string($accepted) ? explode(',', $accepted) : []);
        $acceptedIds = array_values(array_unique(array_filter(array_map('intval', $accepted))));
        foreach ($this->normalize($shop) as $consent) {
            if ($consent['mandatory'] && !in_array($consent['id'], $acceptedIds, true)) {
                throw new CheckoutValidationException('Моля, приемете всички задължителни съгласия.');
            }
        }

        return $acceptedIds;
    }

    /** @param mixed $value */
    private function flag($value): bool
    {
        return in_array($value, [1, '1', true, 'yes', 'on', 'true'], true);
    }
}
