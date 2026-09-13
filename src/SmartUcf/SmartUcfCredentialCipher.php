<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\SmartUcf;

/**
 * PrestaShop-native reversible encryption for SmartUCF credentials (PhpEncryption / Defuse).
 *
 * Key material is `_NEW_COOKIE_KEY_` — never UNICID or module secret.
 * No plaintext fallback: values without the envelope prefix are rejected.
 */
final class SmartUcfCredentialCipher
{
    public const PREFIX = 'enc:v1:';

    /** @var \PhpEncryption */
    private $cipher;

    public function __construct(?\PhpEncryption $cipher = null)
    {
        $this->cipher = $cipher ?? new \PhpEncryption(_NEW_COOKIE_KEY_);
    }

    public static function encryptedPrefix(): string
    {
        return self::PREFIX;
    }

    public function encrypt(string $plaintext): string
    {
        return self::PREFIX . $this->cipher->encrypt($plaintext);
    }

    public function decrypt(string $encoded): string
    {
        if (strpos($encoded, self::PREFIX) !== 0) {
            throw new \RuntimeException('Encrypted SmartUCF credential has invalid prefix.');
        }

        $ciphertext = substr($encoded, strlen(self::PREFIX));
        $plaintext = $this->cipher->decrypt($ciphertext);
        if (!is_string($plaintext) || $plaintext === '') {
            throw new \RuntimeException('SmartUCF credential decryption failed.');
        }

        return $plaintext;
    }

    public function isEncryptedEnvelope(string $value): bool
    {
        return strpos($value, self::PREFIX) === 0;
    }
}
