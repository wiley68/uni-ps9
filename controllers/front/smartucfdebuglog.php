<?php

declare(strict_types=1);

use PrestaShop\Module\Unipayment\Api\Exception\ModuleApiException;
use PrestaShop\Module\Unipayment\Api\ModuleApiError;
use PrestaShop\Module\Unipayment\Api\ModuleApiOperation;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Controller\ModuleApiController;
use PrestaShop\Module\Unipayment\Order\BankStatus;
use PrestaShop\Module\Unipayment\Order\OrderBankStatusAmbiguousException;
use PrestaShop\Module\Unipayment\Order\OrderBankStatusRepository;
use PrestaShop\Module\Unipayment\Security\ModuleRequestSignatureProtocol;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDebugLogRepository;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDiagnosticJournal;

final class UnipaymentSmartucfdebuglogModuleFrontController extends ModuleApiController
{
    protected function expectedOperation(): string
    {
        return ModuleApiOperation::SMARTUCF_DEBUG_LOG;
    }

    protected function handleAuthenticatedRequest(array $payload, string $unicid): array
    {
        unset($unicid);
        $idShop = (int) ($this->context->shop->id ?? 0);
        if ($idShop <= 0) {
            throw new ModuleApiException(
                'The shop context is invalid.',
                400,
                ModuleApiError::INVALID_PAYLOAD
            );
        }

        $orderId = $payload['order_id'] ?? null;
        if (!is_string($orderId)) {
            throw new ModuleApiException(
                'The order_id field is required.',
                400,
                ModuleApiError::INVALID_PAYLOAD
            );
        }

        $orderId = trim($orderId);
        if ($orderId === '' || strlen($orderId) > ModuleRequestSignatureProtocol::ORDER_ID_MAX) {
            throw new ModuleApiException(
                'The order_id field is invalid.',
                400,
                ModuleApiError::INVALID_PAYLOAD
            );
        }

        $bankStatuses = new OrderBankStatusRepository();

        try {
            $authorized = $bankStatuses->resolveAuthorizedFinancingOrder($idShop, $orderId);
        } catch (OrderBankStatusAmbiguousException $exception) {
            throw $this->opaqueOrderNotFound();
        }

        if ($authorized === null) {
            throw $this->opaqueOrderNotFound();
        }

        // Non-P1 / Process 2 targets must not disclose journal existence.
        if (!$this->isAuthorizedProcess1Target($bankStatuses, $authorized)) {
            throw $this->opaqueOrderNotFound();
        }

        $log = (new SmartUcfDiagnosticJournal(
            new ConfigurationRepository(),
            new SmartUcfDebugLogRepository()
        ))->findLatestForAuthorizedOrder(
            (string) $authorized['order_reference'],
            (int) $authorized['id_order'],
            $idShop
        );
        if ($log === null) {
            throw $this->opaqueOrderNotFound();
        }

        return [
            'success' => true,
            'message' => 'The SmartUCF diagnostic log was retrieved successfully.',
            'data' => [
                'order_id' => (string) $authorized['order_reference'],
                'ps_order_id' => (int) $authorized['id_order'],
                'log' => $log,
            ],
        ];
    }

    /**
     * @param array{id_order: int, id_shop: int, order_reference: string, smartucf_state?: string} $authorized
     */
    private function isAuthorizedProcess1Target(OrderBankStatusRepository $bankStatuses, array $authorized): bool
    {
        $local = $bankStatuses->findByOrderId((int) $authorized['id_order']);
        if (is_array($local) && (string) ($local['status_id'] ?? '') === BankStatus::SENT_PROCESS2) {
            return false;
        }

        $smartucfState = (string) ($authorized['smartucf_state'] ?? 'not_started');

        // P1 ownership: SmartUCF lifecycle has started (or completed / failed / unknown).
        return $smartucfState !== '' && $smartucfState !== 'not_started';
    }

    private function opaqueOrderNotFound(): ModuleApiException
    {
        return new ModuleApiException(
            'The order was not found in the shop.',
            404,
            ModuleApiError::ORDER_NOT_FOUND
        );
    }
}
