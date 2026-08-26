<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/**
 * Normalized outcome after PrestaShop + CP order creation.
 */
final class PostControlPanelLifecycleResult
{
    public const OUTCOME_PROCESS2 = 'process2';

    public const OUTCOME_SMARTUCF_CREATED = 'smartucf_created';

    public const OUTCOME_SMARTUCF_PROCESSING = 'smartucf_processing';

    public const OUTCOME_SMARTUCF_OUTCOME_UNKNOWN = 'smartucf_outcome_unknown';

    public const OUTCOME_SMARTUCF_FAILED = 'smartucf_failed';

    public const OUTCOME_SNAPSHOT_MISSING = 'snapshot_missing';

    public const OUTCOME_POST_ORDER_FAILURE = 'post_order_failure';

    /** @var string */
    private $outcome;

    /** @var bool */
    private $process2;

    /** @var string */
    private $redirectUrl;

    /** @var string */
    private $customerMessage;

    /** @var array{status_id: string, status_label: string}|null */
    private $finalBankStatus;

    /** @var bool */
    private $snapshotAvailable;

    /** @var bool */
    private $emailSent;

    /** @var string|null */
    private $emailError;

    /** @var string|null */
    private $postOrderError;

    /**
     * @param array{status_id: string, status_label: string}|null $finalBankStatus
     */
    private function __construct(
        string $outcome,
        bool $process2 = false,
        string $redirectUrl = '',
        string $customerMessage = '',
        ?array $finalBankStatus = null,
        bool $snapshotAvailable = true,
        bool $emailSent = false,
        ?string $emailError = null,
        ?string $postOrderError = null
    ) {
        $this->outcome = $outcome;
        $this->process2 = $process2;
        $this->redirectUrl = $redirectUrl;
        $this->customerMessage = $customerMessage;
        $this->finalBankStatus = $finalBankStatus;
        $this->snapshotAvailable = $snapshotAvailable;
        $this->emailSent = $emailSent;
        $this->emailError = $emailError;
        $this->postOrderError = $postOrderError;
    }

    public static function process2(?array $finalBankStatus = null): self
    {
        return new self(self::OUTCOME_PROCESS2, true, '', '', $finalBankStatus);
    }

    public static function smartUcfCreated(string $redirectUrl, ?array $finalBankStatus = null, bool $emailSent = false): self
    {
        return new self(self::OUTCOME_SMARTUCF_CREATED, false, $redirectUrl, '', $finalBankStatus, true, $emailSent);
    }

    public static function smartUcfProcessing(string $customerMessage): self
    {
        return new self(self::OUTCOME_SMARTUCF_PROCESSING, false, '', $customerMessage);
    }

    public static function smartUcfOutcomeUnknown(string $customerMessage, ?array $finalBankStatus = null, bool $emailSent = false): self
    {
        return new self(self::OUTCOME_SMARTUCF_OUTCOME_UNKNOWN, false, '', $customerMessage, $finalBankStatus, true, $emailSent);
    }

    public static function smartUcfFailed(string $customerMessage, ?array $finalBankStatus = null, bool $emailSent = false): self
    {
        return new self(self::OUTCOME_SMARTUCF_FAILED, false, '', $customerMessage, $finalBankStatus, true, $emailSent);
    }

    public static function snapshotMissing(): self
    {
        return new self(self::OUTCOME_SNAPSHOT_MISSING, false, '', '', null, false);
    }

    public static function postOrderFailure(string $message): self
    {
        return new self(self::OUTCOME_POST_ORDER_FAILURE, false, '', '', null, false, false, null, $message);
    }

    public function withEmailSent(bool $emailSent, ?string $emailError = null): self
    {
        return new self(
            $this->outcome,
            $this->process2,
            $this->redirectUrl,
            $this->customerMessage,
            $this->finalBankStatus,
            $this->snapshotAvailable,
            $emailSent,
            $emailError,
            $this->postOrderError
        );
    }

    public function outcome(): string
    {
        return $this->outcome;
    }

    public function isProcess2(): bool
    {
        return $this->process2;
    }

    public function redirectUrl(): string
    {
        return $this->redirectUrl;
    }

    public function customerMessage(): string
    {
        return $this->customerMessage;
    }

    /** @return array{status_id: string, status_label: string}|null */
    public function finalBankStatus(): ?array
    {
        return $this->finalBankStatus;
    }

    public function snapshotAvailable(): bool
    {
        return $this->snapshotAvailable;
    }

    public function emailSent(): bool
    {
        return $this->emailSent;
    }

    public function emailError(): ?string
    {
        return $this->emailError;
    }

    public function postOrderError(): ?string
    {
        return $this->postOrderError;
    }

    public function isProcessing(): bool
    {
        return $this->outcome === self::OUTCOME_SMARTUCF_PROCESSING;
    }

    public function isOutcomeUnknown(): bool
    {
        return $this->outcome === self::OUTCOME_SMARTUCF_OUTCOME_UNKNOWN;
    }

    public function isFailed(): bool
    {
        return $this->outcome === self::OUTCOME_SMARTUCF_FAILED;
    }

    public function isCreated(): bool
    {
        return $this->outcome === self::OUTCOME_SMARTUCF_CREATED;
    }
}
