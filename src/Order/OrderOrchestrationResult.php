<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

final class OrderOrchestrationResult
{
    public int $attemptId;
    public string $state;
    public int $idOrder;
    public string $orderReference;
    public int $controlPanelOrderId;
    public bool $recovered;

    public function __construct(
        int $attemptId,
        string $state,
        int $idOrder,
        string $orderReference,
        int $controlPanelOrderId = 0,
        bool $recovered = false
    ) {
        $this->attemptId = $attemptId;
        $this->state = $state;
        $this->idOrder = $idOrder;
        $this->orderReference = $orderReference;
        $this->controlPanelOrderId = $controlPanelOrderId;
        $this->recovered = $recovered;
    }
}
