<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Security;

use PrestaShop\Module\Unipayment\Api\Exception\ModuleApiException;

final class ModuleRequestSignatureVerifier
{
    /** @var ClockInterface */
    private $clock;

    public function __construct(?ClockInterface $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * @param array<string, string> $headers
     */
    public function verify(string $secret, string $rawBody, array $headers): void
    {
        $timestamp = $this->requireHeader($headers, ModuleRequestSignatureProtocol::HEADER_TIMESTAMP);
        $nonce = $this->requireHeader($headers, ModuleRequestSignatureProtocol::HEADER_NONCE);
        $signature = $this->requireHeader($headers, ModuleRequestSignatureProtocol::HEADER_SIGNATURE);

        $this->assertFreshTimestamp($timestamp);
        $this->assertNonceFormat($nonce);

        $expected = ModuleRequestSignatureProtocol::computeSignature($secret, $timestamp, $nonce, $rawBody);
        if (!hash_equals($expected, $signature)) {
            throw $this->authFailure();
        }
    }

    /**
     * @param array<string, string> $headers
     */
    public function extractNonce(array $headers): string
    {
        return $this->requireHeader($headers, ModuleRequestSignatureProtocol::HEADER_NONCE);
    }

    /**
     * @param array<string, string> $headers
     */
    private function requireHeader(array $headers, string $name): string
    {
        $value = $this->headerValue($headers, $name);
        if ($value === null || $value === '') {
            throw $this->authFailure();
        }

        return $value;
    }

    /**
     * @param array<string, string> $headers
     */
    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $headerName => $headerValue) {
            if (strcasecmp((string) $headerName, $name) === 0 && is_string($headerValue)) {
                return $headerValue;
            }
        }

        return null;
    }

    private function assertFreshTimestamp(string $timestamp): void
    {
        if (!ctype_digit($timestamp)) {
            throw $this->authFailure();
        }

        $requestTimestamp = (int) $timestamp;
        $now = $this->clock->now();
        if (abs($now - $requestTimestamp) > ModuleRequestSignatureProtocol::TIMESTAMP_TOLERANCE_SECONDS) {
            throw $this->authFailure();
        }
    }

    private function assertNonceFormat(string $nonce): void
    {
        if (!preg_match('/\A[0-9a-fA-F]{' . ModuleRequestSignatureProtocol::NONCE_HEX_LENGTH . '}\z/', $nonce)) {
            throw $this->authFailure();
        }
    }

    private function authFailure(): ModuleApiException
    {
        return new ModuleApiException(ModuleRequestSignatureProtocol::AUTH_FAILURE_MESSAGE, 401);
    }
}
