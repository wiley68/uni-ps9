<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Security;

final class ModuleRequestSignatureProtocol
{
    public const HEADER_TIMESTAMP = 'X-UniPayment-Timestamp';

    public const HEADER_NONCE = 'X-UniPayment-Nonce';

    public const HEADER_SIGNATURE = 'X-UniPayment-Signature';

    public const TIMESTAMP_TOLERANCE_SECONDS = 300;

    public const NONCE_HEX_LENGTH = 64;

    public const NONCE_RETENTION_SECONDS = 900;

    public const AUTH_FAILURE_MESSAGE = 'Invalid or expired module request.';

    public const CONTRACT_SECRET = 'test_shared_secret_123';

    public const CONTRACT_TIMESTAMP = '1787380000';

    public const CONTRACT_NONCE = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    public const CONTRACT_RAW_BODY = '{"unicid":"TEST-UNICID","order_id":"ABC123","status":"approved","status_id":"10"}';

    public const CONTRACT_SIGNATURE = '2f4a55c19a2dd0f2f7f2390a6d720e95dbdff577c096d7ff291ef8f84a53e94f';

    public static function buildCanonicalString(string $timestamp, string $nonce, string $rawBody): string
    {
        return $timestamp . "\n" . $nonce . "\n" . $rawBody;
    }

    public static function computeSignature(string $secret, string $timestamp, string $nonce, string $rawBody): string
    {
        return hash_hmac(
            'sha256',
            self::buildCanonicalString($timestamp, $nonce, $rawBody),
            $secret
        );
    }
}
