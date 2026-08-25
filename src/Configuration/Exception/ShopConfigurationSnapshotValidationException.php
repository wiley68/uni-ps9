<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Configuration\Exception;

/**
 * Thrown when a shop configuration snapshot fails canonical structural validation.
 * Must not purge known-good cache on pull.
 */
final class ShopConfigurationSnapshotValidationException extends \RuntimeException
{
    public const ERROR_CODE = 'shop_snapshot_invalid';

    /** @var list<array{path: string, code: string}> */
    private $violations;

    /**
     * @param list<array{path: string, code: string}> $violations
     */
    public function __construct(array $violations, string $message = 'The shop configuration snapshot is invalid.')
    {
        parent::__construct($message);
        $this->violations = array_values($violations);
    }

    /** @return list<array{path: string, code: string}> */
    public function violations(): array
    {
        return $this->violations;
    }

    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }

    /** @return array{violations: list<array{path: string, code: string}>} */
    public function responseData(): array
    {
        return ['violations' => $this->violations];
    }
}
