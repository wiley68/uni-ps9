<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Api\Exception;

final class ModuleApiException extends \RuntimeException
{
    /** @var int */
    private $statusCode;

    /** @var string|null */
    private $errorCode;

    /** @var array<string, mixed>|null */
    private $responseData;

    /**
     * @param array<string, mixed>|null $responseData
     */
    public function __construct(
        string $message,
        int $statusCode,
        ?string $errorCode = null,
        ?array $responseData = null
    ) {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
        $this->responseData = $responseData;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /** @return array<string, mixed>|null */
    public function getResponseData(): ?array
    {
        return $this->responseData;
    }
}
