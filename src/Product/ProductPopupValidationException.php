<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Product;

final class ProductPopupValidationException extends \RuntimeException
{
    /** @var array<string, string> */
    private $errors;

    /** @param array<string, string> $errors */
    public function __construct(array $errors)
    {
        parent::__construct('The customer details are invalid.');
        $this->errors = $errors;
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
