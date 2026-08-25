<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Api\Exception;

final class HttpException extends ControlPanelException
{
    /** @var int */
    private $statusCode;

    /** @var array<string, mixed> */
    private $response;

    /** @param array<string, mixed> $response */
    public function __construct(int $statusCode, array $response)
    {
        parent::__construct(sprintf('Control Panel returned HTTP %d.', $statusCode));
        $this->statusCode = $statusCode;
        $this->response = $response;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string, mixed> */
    public function getResponse(): array
    {
        return $this->response;
    }
}
