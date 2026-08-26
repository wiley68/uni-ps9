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
        $idShop = (int) ($this->context->shop->id ?? 0);
        if ($idShop <= 0) {
            throw new ModuleApiException('Контекстът на магазина е невалиден.', 400);
        }

        $orderId = $payload['order_id'] ?? null;
        if (!is_string($orderId) && !is_int($orderId)) {
            throw new ModuleApiException('Полето order_id е задължително.', 400);
        }

        $orderId = trim((string) $orderId);
        if ($orderId === '' || strlen($orderId) > 64) {
            throw new ModuleApiException('Полето order_id е невалидно.', 400);
        }

        $log = (new SmartUcfDiagnosticJournal(
            new ConfigurationRepository(),
            new SmartUcfDebugLogRepository()
        ))->findLatestByOrderIdAndShop($orderId, $idShop);
        if ($log === null) {
            // Same outward response whether missing locally or owned by another shop (no cross-tenant oracle).
            throw new ModuleApiException('Не е намерен SmartUCF диагностичен запис за тази поръчка.', 404);
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
