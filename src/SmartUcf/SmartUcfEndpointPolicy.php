<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\SmartUcf;

/**
 * Module-owned allowlist for SmartUCF server-side and browser destinations (AUD-003).
 *
 * Control Panel must not expand this trust boundary.
 */
final class SmartUcfEndpointPolicy
{
    public const HOST_PRODUCTION = 'online.ucfin.bg';
    public const HOST_TEST = 'onlinetest.ucfin.bg';

    public const SERVICE_PATH = '/suos/api/otp';
    public const APPLICATION_PATH = '/sucf-online/Request/Start';

    public const SESSION_START_SUFFIX = 'sucfOnlineSessionStart';

    /** @var list<string> */
    private const TRUSTED_HOSTS = [
        self::HOST_PRODUCTION,
        self::HOST_TEST,
    ];

    /**
     * Normalize and accept a CP-supplied service base URL, or throw.
     *
     * @throws \InvalidArgumentException
     */
    public function assertTrustedServiceBase(string $url): string
    {
        $parts = $this->parseStrictHttpsUrl($url, 'SmartUCF service');
        $this->assertTrustedHostAndPort($parts, 'SmartUCF service');
        $path = $this->normalizedAbsolutePath((string) ($parts['path'] ?? ''));
        if ($path !== self::SERVICE_PATH) {
            throw new \InvalidArgumentException('The SmartUCF service path is not trusted.');
        }

        return 'https://' . strtolower((string) $parts['host']) . self::SERVICE_PATH . '/';
    }

    /**
     * Full sucfOnlineSessionStart URL after validating the service base.
     *
     * @throws \InvalidArgumentException
     */
    public function buildSessionStartUrl(string $serviceBaseUrl): string
    {
        $normalized = $this->assertTrustedServiceBase($serviceBaseUrl);

        return $normalized . self::SESSION_START_SUFFIX;
    }

    /**
     * Normalize and accept a CP-supplied application base URL, or throw.
     *
     * @throws \InvalidArgumentException
     */
    public function assertTrustedApplicationBase(string $url): string
    {
        $parts = $this->parseStrictHttpsUrl($url, 'SmartUCF application');
        $this->assertTrustedHostAndPort($parts, 'SmartUCF application');
        $path = $this->normalizedAbsolutePath((string) ($parts['path'] ?? ''));
        if ($path !== self::APPLICATION_PATH) {
            throw new \InvalidArgumentException('The SmartUCF application path is not trusted.');
        }

        return 'https://' . strtolower((string) $parts['host']) . self::APPLICATION_PATH;
    }

    /**
     * Build browser redirect URL from a trusted application base + session id.
     *
     * @throws \InvalidArgumentException
     */
    public function buildApplicationRedirect(string $applicationBaseUrl, string $sessionId): string
    {
        $base = $this->assertTrustedApplicationBase($applicationBaseUrl);
        $sessionId = $this->assertSafeSessionId($sessionId);

        return $base . '/' . $sessionId;
    }

    /**
     * Whether a final browser redirect URL is within a trusted SmartUCF application origin.
     */
    public function isTrustedApplicationRedirect(string $redirectUrl): bool
    {
        try {
            $this->assertTrustedApplicationRedirect($redirectUrl);

            return true;
        } catch (\InvalidArgumentException $exception) {
            return false;
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function assertTrustedApplicationRedirect(string $redirectUrl): string
    {
        $parts = $this->parseStrictHttpsUrl($redirectUrl, 'SmartUCF redirect');
        $this->assertTrustedHostAndPort($parts, 'SmartUCF redirect');
        $path = (string) ($parts['path'] ?? '');
        if ($path === '' || $path[0] !== '/') {
            throw new \InvalidArgumentException('The SmartUCF redirect path is not trusted.');
        }
        $prefix = self::APPLICATION_PATH . '/';
        if (strpos($path, $prefix) !== 0) {
            throw new \InvalidArgumentException('The SmartUCF redirect path is not trusted.');
        }
        $sessionId = substr($path, strlen($prefix));
        $this->assertSafeSessionId($sessionId);

        return 'https://' . strtolower((string) $parts['host']) . $prefix . $sessionId;
    }

    /**
     * Sanitized diagnostic fragment for logs (never secrets).
     */
    public function describeUrlForLog(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return '[unparseable]';
        }
        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        $authority = $host;
        if ($port !== null) {
            $authority .= ':' . $port;
        }

        return ($scheme !== '' ? $scheme . '://' : '') . $authority . $path;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException
     */
    private function parseStrictHttpsUrl(string $url, string $label): array
    {
        $url = trim($url);
        if ($url === '') {
            throw new \InvalidArgumentException('The ' . $label . ' URL is empty.');
        }
        if (strpos($url, '#') !== false) {
            throw new \InvalidArgumentException('The ' . $label . ' URL must not contain a fragment.');
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('The ' . $label . ' URL is malformed.');
        }
        if (strtolower((string) $parts['scheme']) !== 'https') {
            throw new \InvalidArgumentException('The ' . $label . ' URL must use HTTPS.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('The ' . $label . ' URL must not contain userinfo.');
        }
        if (isset($parts['query']) && (string) $parts['query'] !== '') {
            throw new \InvalidArgumentException('The ' . $label . ' URL must not contain a query string.');
        }
        if (isset($parts['fragment']) && (string) $parts['fragment'] !== '') {
            throw new \InvalidArgumentException('The ' . $label . ' URL must not contain a fragment.');
        }

        return $parts;
    }

    /**
     * @param array<string, mixed> $parts
     *
     * @throws \InvalidArgumentException
     */
    private function assertTrustedHostAndPort(array $parts, string $label): void
    {
        $host = strtolower((string) $parts['host']);
        if (!in_array($host, self::TRUSTED_HOSTS, true)) {
            throw new \InvalidArgumentException('The ' . $label . ' hostname is not trusted.');
        }
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            throw new \InvalidArgumentException('The ' . $label . ' URL must use the default HTTPS port.');
        }
    }

    private function normalizedAbsolutePath(string $path): string
    {
        if ($path === '') {
            return '';
        }
        $path = '/' . ltrim($path, '/');
        if ($path !== '/' && substr($path, -1) === '/') {
            $path = rtrim($path, '/');
        }

        return $path;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function assertSafeSessionId(string $sessionId): string
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '' || strlen($sessionId) > 128) {
            throw new \InvalidArgumentException('The SmartUCF session identifier is invalid.');
        }
        if (!preg_match('/^[A-Za-z0-9._~-]+$/', $sessionId)) {
            throw new \InvalidArgumentException('The SmartUCF session identifier is invalid.');
        }

        return $sessionId;
    }
}
