<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\SmartUcf;

/**
 * Classification of a SmartUCF create-session failure (AUD-008).
 */
final class SmartUcfFailureClassification
{
    public const CLASS_PRE_SEND = 'pre_send';
    public const CLASS_REMOTE_REJECT = 'remote_reject';
    public const CLASS_TRANSPORT_AMBIGUOUS = 'transport_ambiguous';
    public const CLASS_DUPLICATE_ORDER_NO = 'duplicate_order_no';

    /** @var string */
    private $targetState;
    /** @var bool */
    private $retryable;
    /** @var string */
    private $errorClass;
    /** @var int */
    private $httpCode;

    public function __construct(string $targetState, bool $retryable, string $errorClass, int $httpCode = 0)
    {
        $this->targetState = $targetState;
        $this->retryable = $retryable;
        $this->errorClass = $errorClass;
        $this->httpCode = $httpCode;
    }

    public function targetState(): string
    {
        return $this->targetState;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function errorClass(): string
    {
        return $this->errorClass;
    }

    public function httpCode(): int
    {
        return $this->httpCode;
    }
}
