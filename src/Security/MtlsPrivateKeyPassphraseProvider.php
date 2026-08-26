<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Security;

/**
 * Resolves the SmartUCF mTLS private-key passphrase from module ZIP secrets only.
 *
 * Authoritative source: secrets/smartucf-key.php
 * Never reads environment variables, PrestaShop Configuration, DB, or request input.
 */
final class MtlsPrivateKeyPassphraseProvider
{
    public const RELATIVE_PATH = 'secrets/smartucf-key.php';
    public const ARRAY_KEY = 'passphrase';

    /** @var string Absolute path to secrets/smartucf-key.php */
    private $secretFilePath;

    /** @var callable|null Optional test double: (): mixed (raw include result or passphrase string) */
    private $loader;

    public function __construct(?string $secretFilePath = null, ?callable $loader = null)
    {
        $this->secretFilePath = $secretFilePath ?? (dirname(__DIR__, 2) . '/' . self::RELATIVE_PATH);
        $this->loader = $loader;
    }

    /**
     * @throws MtlsPrivateKeyPassphraseNotConfiguredException
     */
    public function require(): string
    {
        $value = $this->resolve();
        if ($value === null) {
            throw new MtlsPrivateKeyPassphraseNotConfiguredException();
        }

        return $value;
    }

    /**
     * Non-empty passphrase, or null when missing / invalid / blank.
     */
    public function resolve(): ?string
    {
        $loaded = $this->loadFile();
        if ($loaded === null) {
            return null;
        }
        if (!is_array($loaded)) {
            return null;
        }
        if (!array_key_exists(self::ARRAY_KEY, $loaded)) {
            return null;
        }
        $raw = $loaded[self::ARRAY_KEY];
        if (!is_string($raw)) {
            return null;
        }

        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        return $trimmed;
    }

    public function isConfigured(): bool
    {
        return $this->resolve() !== null;
    }

    public function secretFilePath(): string
    {
        return $this->secretFilePath;
    }

    /** @return mixed|null */
    private function loadFile()
    {
        if ($this->loader !== null) {
            return ($this->loader)();
        }

        if (!is_file($this->secretFilePath) || !is_readable($this->secretFilePath)) {
            return null;
        }

        return include $this->secretFilePath;
    }
}
