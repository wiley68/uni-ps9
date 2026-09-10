<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Api;

/**
 * Canonical inbound module API response envelope.
 *
 * @phpstan-type EnvelopeData array<string, mixed>
 */
final class ModuleApiResponse
{
    /**
     * @param EnvelopeData $data
     * @return array{success: true, error: null, message: string, data: array<string, mixed>|\stdClass}
     */
    public static function success(string $message, array $data = []): array
    {
        return [
            'success' => true,
            'error' => null,
            'message' => $message,
            'data' => self::normalizeData($data),
        ];
    }

    /**
     * @param EnvelopeData $data
     * @return array{success: false, error: string, message: string, data: array<string, mixed>|\stdClass}
     */
    public static function failure(string $error, string $message, array $data = []): array
    {
        return [
            'success' => false,
            'error' => $error,
            'message' => $message,
            'data' => self::normalizeData($data),
        ];
    }

    /**
     * @param EnvelopeData $data
     * @return array<string, mixed>|\stdClass
     */
    public static function normalizeData(array $data)
    {
        return $data === [] ? new \stdClass() : $data;
    }
}
