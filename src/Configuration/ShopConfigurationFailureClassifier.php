<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Configuration;

use PrestaShop\Module\Unipayment\Api\Exception\AuthenticationException;
use PrestaShop\Module\Unipayment\Api\Exception\ConnectionException;
use PrestaShop\Module\Unipayment\Api\Exception\HttpException;
use PrestaShop\Module\Unipayment\Api\Exception\InvalidPayloadException;
use PrestaShop\Module\Unipayment\Api\Exception\MalformedJsonException;
use PrestaShop\Module\Unipayment\Configuration\Exception\ShopConfigurationSnapshotValidationException;

final class ShopConfigurationFailureClassifier
{
    public const TRANSIENT = 'transient';
    public const AUTHORITATIVE_NEGATIVE = 'authoritative_negative';
    public const CONTRACT_INVALID = 'contract_invalid';

    public function classify(\Throwable $exception): string
    {
        if ($exception instanceof AuthenticationException) {
            return self::AUTHORITATIVE_NEGATIVE;
        }
        if ($exception instanceof ShopConfigurationSnapshotValidationException
            || $exception instanceof MalformedJsonException
            || $exception instanceof InvalidPayloadException
        ) {
            return self::CONTRACT_INVALID;
        }
        if ($exception instanceof ConnectionException) {
            return self::TRANSIENT;
        }
        if ($exception instanceof HttpException) {
            $status = $exception->getStatusCode();
            if ($status === 422) {
                return self::CONTRACT_INVALID;
            }
            if ($status === 408 || $status === 429 || $status >= 500) {
                return self::TRANSIENT;
            }
            if (in_array($status, [403, 404, 410], true)
                && $this->isExplicitAuthoritativeNegative($exception->getResponse())
            ) {
                return self::AUTHORITATIVE_NEGATIVE;
            }

            return self::CONTRACT_INVALID;
        }

        return self::CONTRACT_INVALID;
    }

    /** @param array<string,mixed> $response */
    private function isExplicitAuthoritativeNegative(array $response): bool
    {
        $haystack = strtolower((string) json_encode($response));
        foreach (['not found', 'deleted', 'revoked', 'disabled', 'forbidden', 'credential', 'gone', 'shop_invalid', 'shop_not_found'] as $marker) {
            if (strpos($haystack, $marker) !== false) {
                return true;
            }
        }

        return false;
    }
}
