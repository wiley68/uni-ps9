<?php

declare(strict_types=1);

use PrestaShop\Module\Unipayment\Api\Exception\ModuleApiException;
use PrestaShop\Module\Unipayment\Controller\ModuleApiController;
use PrestaShop\Module\Unipayment\Order\OrderBankStatusRepository;

final class UnipaymentOrderbankstatusModuleFrontController extends ModuleApiController
{
    protected function handleAuthenticatedRequest(array $payload, string $unicid): array
    {
        unset($unicid);
        $idShop = (int) ($this->context->shop->id ?? 0);
        if ($idShop <= 0) {
            throw new ModuleApiException('Контекстът на магазина е невалиден.', 400);
        }

        $orderId = $this->requiredString($payload, 'order_id', 64);
        $statusId = $this->requiredString($payload, 'status_id', 255);
        $status = $payload['status'] ?? '';
        if (!is_string($status) || strlen($status) > 255) {
            throw new ModuleApiException('Полето status е невалидно.', 400);
        }

        $result = (new OrderBankStatusRepository())->updateByOrderIdentifier(
            $idShop,
            $orderId,
            $statusId,
            trim($status)
        );
        if ($result === null) {
            throw new ModuleApiException('Поръчката не е намерена в магазина.', 404);
        }

        $result['ps_order_state_changed'] = false;

        return [
            'success' => true,
            'message' => 'Банковият статус е обновен успешно.',
            'data' => $result,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function requiredString(array $payload, string $key, int $maxLength): string
    {
        $value = $payload[$key] ?? null;
        if (!is_string($value) && !is_int($value)) {
            throw new ModuleApiException(sprintf('Полето %s е задължително.', $key), 400);
        }

        $value = trim((string) $value);
        if ($value === '' || strlen($value) > $maxLength) {
            throw new ModuleApiException(sprintf('Полето %s е невалидно.', $key), 400);
        }

        return $value;
    }
}
