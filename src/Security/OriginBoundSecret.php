<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Security;

use PrestaShop\Module\Unipayment\Configuration\ControlPanelOrigin;

/** Origin and value travel inside the same authenticated encrypted envelope. */
final class OriginBoundSecret
{
    public static function encode(string $value): string
    {
        return json_encode(['cp_origin' => ControlPanelOrigin::current(), 'value' => $value], JSON_THROW_ON_ERROR);
    }

    public static function decode(string $payload): ?string
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return null;
        }
        if (!is_array($decoded) || !ControlPanelOrigin::matches($decoded['cp_origin'] ?? null)
            || !isset($decoded['value']) || !is_string($decoded['value']) || $decoded['value'] === '') {
            return null;
        }

        return $decoded['value'];
    }
}
