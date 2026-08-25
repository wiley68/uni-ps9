<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Checkout;

final class CheckoutPreferenceStore
{
    private const COOKIE_NAME = 'unipayment_checkout_preference';
    private const TTL_SECONDS = 1800;

    /** @param array<string, mixed> $preference */
    public function save(\Cookie $cookie, array $preference, int $cartId, int $customerId): void
    {
        $preference['cart_id'] = $cartId;
        $preference['customer_id'] = $customerId;
        $preference['created_at'] = time();
        $cookie->{self::COOKIE_NAME} = json_encode($preference, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $cookie->write();
    }

    /** @return array<string, mixed>|null */
    public function load(\Cookie $cookie, int $cartId, int $customerId): ?array
    {
        $raw = (string) $cookie->{self::COOKIE_NAME};
        $preference = json_decode($raw, true);
        if (!is_array($preference)
            || (int) ($preference['cart_id'] ?? 0) !== $cartId
            || (int) ($preference['customer_id'] ?? 0) !== $customerId
            || (int) ($preference['created_at'] ?? 0) < time() - self::TTL_SECONDS
        ) {
            $this->clear($cookie);

            return null;
        }

        return $preference;
    }

    public function clear(\Cookie $cookie): void
    {
        unset($cookie->{self::COOKIE_NAME});
        $cookie->write();
    }
}
