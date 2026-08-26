<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Security;

/**
 * Resolves the SmartUCF mTLS private-key passphrase from the runtime environment only.
 *
 * Deployment must inject UNIPAYMENT_MTLS_KEY_PASSPHRASE (e.g. PHP-FPM env[]).
 * Never persists, logs, or accepts caller-supplied secrets.
 */
final class MtlsPrivateKeyPassphraseProvider
{
    public const ENV_VAR = 'UNIPAYMENT_MTLS_KEY_PASSPHRASE';

    /** @var callable|null Optional test/double override: (): ?string */
    private $reader;

    public function __construct(?callable $reader = null)
    {
        $this->reader = $reader;
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
     * Non-empty passphrase, or null when missing / blank.
     */
    public function resolve(): ?string
    {
        $raw = $this->reader !== null ? ($this->reader)() : $this->readFromEnvironment();
        if (!is_string($raw)) {
            return null;
        }

        // Trim intentional: env files / pool configs often append a trailing newline.
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

    private function readFromEnvironment(): ?string
    {
        $fromGetenv = getenv(self::ENV_VAR);
        if (is_string($fromGetenv) && $fromGetenv !== '') {
            return $fromGetenv;
        }

        if (isset($_ENV[self::ENV_VAR]) && is_string($_ENV[self::ENV_VAR]) && $_ENV[self::ENV_VAR] !== '') {
            return $_ENV[self::ENV_VAR];
        }

        // PHP-FPM may mirror pool env into $_SERVER; accept only the exact key (never HTTP_*).
        if (
            isset($_SERVER[self::ENV_VAR])
            && is_string($_SERVER[self::ENV_VAR])
            && $_SERVER[self::ENV_VAR] !== ''
            && strpos(self::ENV_VAR, 'HTTP_') !== 0
        ) {
            return $_SERVER[self::ENV_VAR];
        }

        return null;
    }
}
