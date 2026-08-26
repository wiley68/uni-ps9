<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\SmartUcf;

final class SmartUcfSessionException extends \RuntimeException
{
    public const KIND_PRE_SEND = 'pre_send';
    public const KIND_TRANSPORT = 'transport';
    public const KIND_REMOTE = 'remote';
    public const KIND_DUPLICATE = 'duplicate';

    /** @var bool */
    private $retryable;
    /** @var int */
    private $httpCode;
    /** @var string */
    private $rawResponse;
    /** @var string */
    private $failureKind;

    public function __construct(
        string $message,
        bool $retryable = false,
        string $rawResponse = '',
        int $httpCode = 0,
        string $failureKind = self::KIND_REMOTE,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->retryable = $retryable;
        $this->rawResponse = $rawResponse;
        $this->httpCode = $httpCode;
        $this->failureKind = $failureKind;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function httpCode(): int
    {
        return $this->httpCode;
    }

    public function rawResponse(): string
    {
        return $this->rawResponse;
    }

    public function getFailureKind(): string
    {
        return $this->failureKind;
    }
}
