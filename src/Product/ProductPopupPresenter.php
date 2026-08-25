<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Product;

/**
 * Product popup presentation DTO (banner, button action, consents, customer prefill).
 * Consent normalization is inlined here so Phase 6 does not depend on Checkout\ConsentResolver.
 */
final class ProductPopupPresenter
{
    /** @param array<string, mixed> $shop @return array<string, mixed> */
    public function present(array $shop, string $buttonAction, array $customer = []): array
    {
        $bannerLink = $this->url($shop['reklama_url'] ?? '');
        if ($bannerLink === '') {
            $bannerLink = $this->url($shop['uni_backurl'] ?? '');
        }

        return [
            'banner_url' => $this->url($shop['uni_picture'] ?? ''),
            'banner_url_mobile' => $this->url($shop['uni_picturem'] ?? ''),
            'banner_link' => $bannerLink,
            'currency_mode' => (int) ($shop['uni_eur'] ?? 0),
            'button_action' => $buttonAction === 'buy' ? 'buy' : 'add_to_cart',
            'secondary_label' => $buttonAction === 'buy' ? 'Купи' : 'Добави в количката',
            'customer' => array_replace([
                'first_name' => '',
                'last_name' => '',
                'address' => '',
                'phone' => '',
                'email' => '',
                'is_logged' => false,
            ], $customer),
            'consents' => $this->normalizeConsents($shop),
        ];
    }

    /**
     * @param array<string, mixed> $shop
     * @return array<int, array{id:int,name:string,url:string,mandatory:bool,has_checkbox:bool}>
     */
    private function normalizeConsents(array $shop): array
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
            $mandatory = in_array($item['mandatory'] ?? 0, [1, '1', true, 'yes', 'on', 'true'], true);
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

    /** @param mixed $value */
    private function url($value): string
    {
        $url = trim((string) $value);

        return filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            ? $url
            : '';
    }
}
