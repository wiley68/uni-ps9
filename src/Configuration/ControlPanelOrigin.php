<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Configuration;

final class ControlPanelOrigin
{
    public const FIELD = 'cp_origin';

    public static function current(): string
    {
        return (new ModuleDeploymentEnvironment())->controlPanelUrl();
    }

    public static function matches(mixed $origin): bool
    {
        return is_string($origin) && hash_equals(self::current(), $origin);
    }

    public static function assertMatches(mixed $origin): void
    {
        if (!self::matches($origin)) {
            throw new ControlPanelOriginMismatchException();
        }
    }
}
