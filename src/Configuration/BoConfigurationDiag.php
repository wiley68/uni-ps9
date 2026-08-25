<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Configuration;

/**
 * Opt-in BO diagnostics for credential-change investigation.
 *
 * Enable by creating an empty file:
 *   modules/unipayment/var/BO_DIAG
 *
 * Writes only booleans, lengths, short fingerprints, and counts to:
 *   modules/unipayment/var/bo-diag-last.json
 *
 * Never logs raw secrets or tokens.
 */
final class BoConfigurationDiag
{
    private const FLAG = 'BO_DIAG';
    private const OUTPUT = 'bo-diag-last.json';

    public static function enabled(): bool
    {
        return is_file(self::dir() . '/' . self::FLAG);
    }

    /** @param array<string, mixed> $payload */
    public static function write(array $payload): void
    {
        if (!self::enabled()) {
            return;
        }

        $dir = self::dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $payload['ts_utc'] = gmdate('c');
        @file_put_contents(
            $dir . '/' . self::OUTPUT,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    public static function fingerprint(?string $value): string
    {
        if ($value === null || $value === '') {
            return 'empty';
        }

        return substr(hash('sha256', $value), 0, 12);
    }

    private static function dir(): string
    {
        return dirname(__DIR__, 2) . '/var';
    }
}
