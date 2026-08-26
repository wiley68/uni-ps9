<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Checkout;

final class CheckoutPreferenceStore
{
    private const COOKIE_NAME = 'unipayment_checkout_preference';
    private const TTL_SECONDS = 1800;

    /** @param object $cookie PrestaShop Cookie or test double with write() */
    public function save($cookie, array $preference, int $cartId, int $customerId): void
    {
        $preference['cart_id'] = $cartId;
        $preference['customer_id'] = $customerId;
        $preference['created_at'] = time();
        $cookie->{self::COOKIE_NAME} = json_encode($preference, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $cookie->write();
    }

    /**
     * @param object $cookie PrestaShop Cookie or test double with write()
     * @param string|null $expectedFingerprint When provided, stored cart_fingerprint must be
     *                                         non-empty and match (legacy cookies without fingerprint are rejected).
     * @param string|null $linesFingerprint When full fingerprint mismatches, product_preselect handoff may
     *                                      rebind once if lines+total identity still matches.
     * @return array<string, mixed>|null
     */
    public function load(
        $cookie,
        int $cartId,
        int $customerId,
        ?string $expectedFingerprint = null,
        ?string $linesFingerprint = null
    ): ?array {
        $raw = (string) $cookie->{self::COOKIE_NAME};
        $preference = json_decode($raw, true);
        if (
            !is_array($preference)
            || (int) ($preference['cart_id'] ?? 0) !== $cartId
            || (int) ($preference['customer_id'] ?? 0) !== $customerId
            || (int) ($preference['created_at'] ?? 0) < time() - self::TTL_SECONDS
        ) {
            $this->clear($cookie);

            return null;
        }

        if ($expectedFingerprint !== null) {
            $storedFingerprint = trim((string) ($preference['cart_fingerprint'] ?? ''));
            if ($storedFingerprint === '' || !hash_equals($storedFingerprint, $expectedFingerprint)) {
                if (
                    $linesFingerprint !== null
                    && (string) ($preference['flow'] ?? '') === 'product_preselect'
                    && empty($preference['checkout_fingerprint_bound'])
                    && $this->linesFingerprintMatches($preference, $linesFingerprint)
                ) {
                    // First checkout encounter after Product "Купи": carrier/shipping may evolve.
                    // Rebind full fingerprint once; later material drift still rejects.
                    $preference['cart_fingerprint'] = $expectedFingerprint;
                    $preference['checkout_fingerprint_bound'] = 1;
                    $this->save($cookie, $preference, $cartId, $customerId);

                    return $preference;
                }
                $this->clear($cookie);

                return null;
            }
        }

        return $preference;
    }

    /**
     * @param array<string, mixed> $preference
     */
    private function linesFingerprintMatches(array $preference, string $linesFingerprint): bool
    {
        $stored = trim((string) ($preference['lines_fingerprint'] ?? ''));

        return $stored !== '' && hash_equals($stored, $linesFingerprint);
    }

    /** @param object $cookie PrestaShop Cookie or test double with write() */
    public function clear($cookie): void
    {
        unset($cookie->{self::COOKIE_NAME});
        $cookie->write();
    }
}
