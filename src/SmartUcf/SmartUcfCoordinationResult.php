<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\SmartUcf;

/**
 * Typed result of SmartUCF lifecycle coordination (AUD-002B).
 */
final class SmartUcfCoordinationResult
{
    public const KIND_CREATED = 'created';
    public const KIND_PROCESSING = 'processing';
    public const KIND_OUTCOME_UNKNOWN = 'outcome_unknown';
    public const KIND_FAILED = 'failed';
    public const KIND_PROCESS2 = 'process2';

    /** @var string */
    private $kind;
    /** @var string */
    private $redirectUrl;
    /** @var string */
    private $sessionId;
    /** @var string */
    private $customerMessage;
    /** @var bool */
    private $retryable;
    /** @var string */
    private $errorClass;
    /** @var array<string, mixed>|null */
    private $sessionPayload;

    private function __construct(
        string $kind,
        string $redirectUrl = '',
        string $sessionId = '',
        string $customerMessage = '',
        bool $retryable = false,
        string $errorClass = '',
        ?array $sessionPayload = null
    ) {
        $this->kind = $kind;
        $this->redirectUrl = $redirectUrl;
        $this->sessionId = $sessionId;
        $this->customerMessage = $customerMessage;
        $this->retryable = $retryable;
        $this->errorClass = $errorClass;
        $this->sessionPayload = $sessionPayload;
    }

    public static function created(string $redirectUrl, string $sessionId, ?array $sessionPayload = null): self
    {
        return new self(self::KIND_CREATED, $redirectUrl, $sessionId, '', false, '', $sessionPayload);
    }

    public static function processing(string $customerMessage = ''): self
    {
        return new self(self::KIND_PROCESSING, '', '', $customerMessage);
    }

    public static function outcomeUnknown(string $customerMessage): self
    {
        return new self(self::KIND_OUTCOME_UNKNOWN, '', '', $customerMessage);
    }

    public static function failed(string $customerMessage, bool $retryable = false, string $errorClass = ''): self
    {
        return new self(self::KIND_FAILED, '', '', $customerMessage, $retryable, $errorClass);
    }

    public static function process2(): self
    {
        return new self(self::KIND_PROCESS2);
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function isCreated(): bool
    {
        return $this->kind === self::KIND_CREATED;
    }

    public function isProcessing(): bool
    {
        return $this->kind === self::KIND_PROCESSING;
    }

    public function isOutcomeUnknown(): bool
    {
        return $this->kind === self::KIND_OUTCOME_UNKNOWN;
    }

    public function isFailed(): bool
    {
        return $this->kind === self::KIND_FAILED;
    }

    public function isProcess2(): bool
    {
        return $this->kind === self::KIND_PROCESS2;
    }

    public function redirectUrl(): string
    {
        return $this->redirectUrl;
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }

    public function customerMessage(): string
    {
        return $this->customerMessage;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function errorClass(): string
    {
        return $this->errorClass;
    }

    /** @return array<string, mixed>|null */
    public function sessionPayload(): ?array
    {
        return $this->sessionPayload;
    }
}
