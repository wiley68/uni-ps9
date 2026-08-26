<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/**
 * Normalized Phase 10 checkout submission outcomes (pre SmartUCF / Phase 11).
 */
final class Phase10CheckoutOutcome
{
    public const VALIDATION_FAILED_BEFORE_ORDER = 'validation_failed_before_order';
    public const CONCURRENT_REQUEST_IN_PROGRESS = 'concurrent_request_in_progress';
    public const PS_ORDER_CREATED_CP_CREATED = 'ps_order_created_cp_created';
    public const PS_ORDER_CREATED_CP_FAILED = 'ps_order_created_cp_failed';
    public const RECOVERED_EXISTING_ORDER = 'recovered_existing_order';

    /** @var string */
    public $code;
    /** @var int */
    public $idOrder;
    /** @var string */
    public $orderReference;
    /** @var int */
    public $attemptId;
    /** @var int */
    public $controlPanelOrderId;
    /** @var bool */
    public $retryable;
    /** @var bool */
    public $outcomeUnknown;

    public function __construct(
        string $code,
        int $idOrder = 0,
        string $orderReference = '',
        int $attemptId = 0,
        int $controlPanelOrderId = 0,
        bool $retryable = false,
        bool $outcomeUnknown = false
    ) {
        $this->code = $code;
        $this->idOrder = $idOrder;
        $this->orderReference = $orderReference;
        $this->attemptId = $attemptId;
        $this->controlPanelOrderId = $controlPanelOrderId;
        $this->retryable = $retryable;
        $this->outcomeUnknown = $outcomeUnknown;
    }

    public static function fromOrchestrationResult(OrderOrchestrationResult $result): self
    {
        return new self(
            $result->recovered ? self::RECOVERED_EXISTING_ORDER : self::PS_ORDER_CREATED_CP_CREATED,
            $result->idOrder,
            $result->orderReference,
            $result->attemptId,
            $result->controlPanelOrderId
        );
    }

    public static function fromOrchestrationException(OrderOrchestrationException $exception): self
    {
        return new self(
            self::PS_ORDER_CREATED_CP_FAILED,
            $exception->idOrder(),
            $exception->orderReference(),
            $exception->attemptId(),
            0,
            $exception->isRetryable(),
            $exception->isOutcomeUnknown()
        );
    }

    public function isPostOrder(): bool
    {
        return $this->idOrder > 0;
    }
}
