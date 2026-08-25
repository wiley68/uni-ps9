<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Security;

final class TokenRepository
{
    public const ACCESS_TOKEN = 'UNIPAYMENT_CP_ACCESS_TOKEN';
    public const TOKEN_TYPE = 'UNIPAYMENT_CP_TOKEN_TYPE';
    public const EXPIRES_AT = 'UNIPAYMENT_CP_TOKEN_EXPIRES_AT';

    private const ENCRYPTED_PREFIX = 'enc:v1:';

    public function save(string $accessToken, string $tokenType, int $expiresAt): bool
    {
        if ($accessToken === '' || $expiresAt <= 0) {
            return false;
        }

        return \Configuration::updateValue(self::ACCESS_TOKEN, $this->encrypt($accessToken))
            && \Configuration::updateValue(self::TOKEN_TYPE, $tokenType !== '' ? $tokenType : 'Bearer')
            && \Configuration::updateValue(self::EXPIRES_AT, $expiresAt);
    }

    public function getAccessToken(): ?string
    {
        $storedToken = (string) \Configuration::get(self::ACCESS_TOKEN);
        if (strpos($storedToken, self::ENCRYPTED_PREFIX) !== 0) {
            return null;
        }

        try {
            $token = $this->getCipher()->decrypt(substr($storedToken, strlen(self::ENCRYPTED_PREFIX)));
        } catch (\Throwable $exception) {
            return null;
        }

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function getTokenType(): string
    {
        $tokenType = trim((string) \Configuration::get(self::TOKEN_TYPE));

        return $tokenType !== '' ? $tokenType : 'Bearer';
    }

    public function getExpiresAt(): int
    {
        return (int) \Configuration::get(self::EXPIRES_AT);
    }

    public function hasToken(): bool
    {
        return $this->getAccessToken() !== null;
    }

    public function invalidate(): bool
    {
        $result = true;
        foreach ([self::ACCESS_TOKEN, self::TOKEN_TYPE, self::EXPIRES_AT] as $key) {
            $result = \Configuration::deleteByName($key) && $result;
        }

        return $result;
    }

    private function encrypt(string $token): string
    {
        return self::ENCRYPTED_PREFIX . $this->getCipher()->encrypt($token);
    }

    private function getCipher(): \PhpEncryption
    {
        return new \PhpEncryption(_NEW_COOKIE_KEY_);
    }
}
