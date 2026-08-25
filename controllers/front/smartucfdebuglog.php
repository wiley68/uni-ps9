<?php

declare(strict_types=1);

use PrestaShop\Module\Unipayment\Api\Exception\ModuleApiException;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Controller\ModuleApiController;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDebugLogRepository;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDiagnosticJournal;

final class UnipaymentSmartucfdebuglogModuleFrontController extends ModuleApiController
{
    protected function handleAuthenticatedRequest(array $payload, string $unicid): array
    {
        unset($unicid);
        $orderId = $payload['order_id'] ?? null;
        if (!is_string($orderId) && !is_int($orderId)) {
            throw new ModuleApiException('The order_id field is required.', 400);
        }

        $orderId = trim((string) $orderId);
        if ($orderId === '' || strlen($orderId) > 64) {
            throw new ModuleApiException('The order_id field is invalid.', 400);
        }

        $log = (new SmartUcfDiagnosticJournal(
            new ConfigurationRepository(),
            new SmartUcfDebugLogRepository()
        ))->findLatestByOrderId($orderId);
        if ($log === null) {
            throw new ModuleApiException('No SmartUCF diagnostic record was found for this order.', 404);
        }

        return [
            'success' => true,
            'data' => [
                'order_id' => $orderId,
                'ps_order_id' => $log['ps_order_id'],
                'log' => $log,
            ],
        ];
    }
}
