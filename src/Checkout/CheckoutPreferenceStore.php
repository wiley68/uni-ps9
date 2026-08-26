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
        // PrestaShop Cookie::__set rejects "|" and "¤" (Cookie.php delimiter alphabet).
        unset($preference['scheme_key'], $preference['calculation']);
        foreach ($preference as $key => $value) {
            if (is_string($value) && preg_match('/¤|\|/', $key . $value)) {
                throw new \InvalidArgumentException(
                    'Checkout preference values must be cookie-safe (no pipe or section delimiters).'
                );
            }
        }

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
     *                                      rebind once if lines identity still matches.
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
            || (int) ($preference['created_at'] ?? 0) < time() - self::TTL_SECONDS
        ) {
            $this->clear($cookie);

            return null;
        }

        $storedCustomerId = (int) ($preference['customer_id'] ?? 0);
        $customerRebound = false;
        if ($storedCustomerId !== $customerId) {
            // Guest Product "Купи" often stores customer_id=0; checkout may create a customer
            // before the payment step. Allow one identity bind for product_preselect only.
            if (
                (string) ($preference['flow'] ?? '') === 'product_preselect'
                && $storedCustomerId === 0
                && empty($preference['customer_identity_bound'])
            ) {
                $preference['customer_id'] = $customerId;
                $preference['customer_identity_bound'] = 1;
                $customerRebound = true;
            } else {
                $this->clear($cookie);

                return null;
            }
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
                    $preference['cart_fingerprint'] = $expectedFingerprint;
                    $preference['checkout_fingerprint_bound'] = 1;
                    $this->save($cookie, $preference, $cartId, $customerId);

                    return $preference;
                }
                $this->clear($cookie);

                return null;
            }
        }

        if ($customerRebound) {
            $this->save($cookie, $preference, $cartId, $customerId);
        }

        return $preference;
    }

    /**
     * Mark intentional Product "Купи" payment auto-select as consumed so later
     * checkout AJAX refreshes do not keep forcing UniCredit after a manual switch.
     *
     * @param object $cookie
     */
    public function markPaymentHandoffConsumed($cookie): void
    {
        $raw = (string) $cookie->{self::COOKIE_NAME};
        $preference = json_decode($raw, true);
        if (!is_array($preference)) {
            return;
        }
        $preference['payment_handoff_consumed'] = 1;
        $cartId = (int) ($preference['cart_id'] ?? 0);
        $customerId = (int) ($preference['customer_id'] ?? 0);
        $this->save($cookie, $preference, $cartId, $customerId);
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
