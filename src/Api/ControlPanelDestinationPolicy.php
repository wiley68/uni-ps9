<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Api;

/** Structural CP origin policy; contains no deployment hostname knowledge. */
final class ControlPanelDestinationPolicy
{
    public static function canonicalOrigin(string $url): string
    {
        $url = trim($url);
        if (!preg_match('#\Ahttps://([a-z0-9.-]+)(?::443)?/?\z#iD', $url, $match)) {
            throw new \RuntimeException('Control Panel URL must be an HTTPS root origin on port 443.');
        }
        $host = strtolower($match[1]);
        self::assertPublicHostname($host);

        return 'https://' . $host;
    }

    public static function assertPublicHostname(string $host): void
    {
        if (strlen($host) > 253 || !str_contains($host, '.')
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || !preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+\z/iD', $host)
            || !preg_match('/[a-z]/i', substr($host, strrpos($host, '.') + 1))
        ) {
            throw new \RuntimeException('Control Panel destination requires a public DNS hostname.');
        }
        foreach (['localhost', 'local', 'internal', 'invalid', 'test', 'onion', 'arpa', 'lan', 'home', 'corp'] as $suffix) {
            if ($host === $suffix || str_ends_with(strtolower($host), '.' . $suffix)) {
                throw new \RuntimeException('Control Panel local or special-use hostname is forbidden.');
            }
        }
    }

    public static function isPublicAddress(string $address): bool
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 4) {
            $blocked = ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8',
                '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
                '192.31.196.0/24', '192.52.193.0/24', '192.88.99.0/24', '192.168.0.0/16',
                '192.175.48.0/24', '198.18.0.0/15', '198.51.100.0/24',
                '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'];
        } else {
            // Only global unicast; exclude protocol assignments, documentation and 6to4.
            if (!self::inNetwork($packed, '2000::/3')) {
                return false;
            }
            $blocked = ['2001::/23', '2001:db8::/32', '2002::/16', '2620:4f:8000::/48', '3fff::/20'];
        }
        foreach ($blocked as $network) {
            if (self::inNetwork($packed, $network)) {
                return false;
            }
        }

        return true;
    }

    private static function inNetwork(string $packed, string $network): bool
    {
        [$base, $bits] = explode('/', $network);
        $prefix = inet_pton($base);
        if ($prefix === false || strlen($prefix) !== strlen($packed)) {
            return false;
        }
        $bytes = intdiv((int) $bits, 8);
        $remainder = (int) $bits % 8;

        return substr($packed, 0, $bytes) === substr($prefix, 0, $bytes)
            && ($remainder === 0 || ((ord($packed[$bytes]) ^ ord($prefix[$bytes])) & (255 << (8 - $remainder))) === 0);
    }
}
