<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Configuration;

/**
 * Narrow shop-cache sanitizer: retain safe unknown fields, strip unknown secret-like keys.
 *
 * Known SmartUCF credential keys (uni_user / uni_password) are retained through ingress so the
 * shared credential partitioner can classify/persist them, then strip them from general shop_data.
 */
final class ShopSnapshotSanitizer
{
    /** @var list<string> */
    private const KNOWN_SENSITIVE_SCHEMA_KEYS = [
        'uni_user',
        'uni_password',
    ];

    /** @var list<string> */
    private const SECRET_TOKENS_STRICT = [
        'pem',
        'cert',
        'pass',
        'token',
        'secret',
        'bearer',
    ];

    /** @var list<string> */
    private const SECRET_TOKENS_CONTAINS = [
        'apikey',
        'accesstoken',
        'refreshtoken',
        'privatekey',
        'privatekeypem',
        'clientsecret',
        'beartoken',
        'bearertoken',
        'authorization',
        'password',
        'passwd',
        'passphrase',
        'certificate',
    ];

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function sanitize(array $data): array
    {
        return self::sanitizeNode($data);
    }

    /**
     * @param array<mixed> $node
     *
     * @return array<mixed>
     */
    private static function sanitizeNode(array $node): array
    {
        $out = [];
        $isList = $node === [] || array_keys($node) === range(0, count($node) - 1);

        foreach ($node as $key => $value) {
            if (!$isList) {
                if (!is_string($key)) {
                    continue;
                }
                if (self::shouldStripUnknownSensitive($key)) {
                    continue;
                }
            }

            if (is_array($value)) {
                $value = self::sanitizeNode($value);
            }

            if ($isList) {
                $out[] = $value;
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private static function shouldStripUnknownSensitive(string $key): bool
    {
        // Exact canonical ingress keys only — case/format variants must not survive into cache.
        if (in_array($key, self::KNOWN_SENSITIVE_SCHEMA_KEYS, true)) {
            return false;
        }

        $normalized = self::normalizeKey($key);
        if ($normalized === 'uniuser' || $normalized === 'unipassword') {
            return true;
        }

        foreach (self::SECRET_TOKENS_CONTAINS as $token) {
            if ($normalized === $token || strpos($normalized, $token) !== false) {
                return true;
            }
        }

        foreach (self::SECRET_TOKENS_STRICT as $token) {
            if (
                $normalized === $token
                || strpos($normalized, $token) === 0
                || substr($normalized, -strlen($token)) === $token
            ) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeKey(string $key): string
    {
        $spaced = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $key) ?? $key;
        $spaced = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1 $2', $spaced) ?? $spaced;

        return strtolower(str_replace(['_', '-', ' '], '', $spaced));
    }
}
