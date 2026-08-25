<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Checkout;

final class CartSnapshotSigner
{
    /** @var string */
    private $key;

    public function __construct(string $key)
    {
        if ($key === '') {
            throw new \InvalidArgumentException('A cart snapshot signing key is required.');
        }
        $this->key = $key;
    }

    public function sign(string $fingerprint): string
    {
        return $fingerprint . '.' . hash_hmac('sha256', $fingerprint, $this->key);
    }

    public function verify(string $token, string $fingerprint): bool
    {
        $expected = $this->sign($fingerprint);

        return $token !== '' && hash_equals($expected, $token);
    }
}
