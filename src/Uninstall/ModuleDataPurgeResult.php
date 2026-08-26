<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Uninstall;

/**
 * Result of module-owned local data cleanup during uninstall (AUD-006).
 */
final class ModuleDataPurgeResult
{
    /** @var bool */
    private $success;

    /** @var list<string> */
    private $completed;

    /** @var list<string> */
    private $errors;

    /**
     * @param list<string> $completed
     * @param list<string> $errors
     */
    public function __construct(bool $success, array $completed, array $errors)
    {
        $this->success = $success;
        $this->completed = array_values($completed);
        $this->errors = array_values($errors);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    /** @return list<string> */
    public function completed(): array
    {
        return $this->completed;
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
