<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Product;

use PrestaShop\Module\Unipayment\Checkout\ConsentResolver;

/**
 * Product popup presentation DTO (banner, button action, consents, customer prefill).
 */
final class ProductPopupPresenter
{
    /** @var ConsentResolver */
    private $consents;

    public function __construct(?ConsentResolver $consents = null)
    {
        $this->consents = $consents ?? new ConsentResolver();
    }

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
            'consents' => $this->consents->normalize($shop),
        ];
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
