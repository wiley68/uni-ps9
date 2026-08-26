<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

final class SensitiveDataCipher
{
    private const PREFIX = 'enc:v1:';
    private $cipher;

    public function __construct(?\PhpEncryption $cipher = null)
    {
        $this->cipher = $cipher ?? new \PhpEncryption(_NEW_COOKIE_KEY_);
    }

    /** @param array<string, string> $data */
    public function encrypt(array $data): ?string
    {
        $filtered = array_filter($data, static function (string $value): bool { return $value !== ''; });
        if ($filtered === []) return null;

        return self::PREFIX . $this->cipher->encrypt(json_encode($filtered, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, string> */
    public function decrypt(?string $encrypted): array
    {
        if ($encrypted === null || strpos($encrypted, self::PREFIX) !== 0) return [];
        $json = $this->cipher->decrypt(substr($encrypted, strlen(self::PREFIX)));
        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) ? array_map('strval', $decoded) : [];
    }
}
