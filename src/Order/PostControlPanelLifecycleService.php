<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationFlags;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCoordinationResult;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfEndpointPolicy;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfSessionCoordinator;

/**
 * Authoritative post-CP lifecycle: snapshot load, Process 2, SmartUCF, bank status, leasing email.
 */
final class PostControlPanelLifecycleService
{
    /** @var FinancingSnapshotStoreInterface */
    private $snapshots;

    /** @var LeasingMailDispatchPort */
    private $mailDispatcher;

    /** @var BankStatusPersistencePort */
    private $bankStatus;

    /** @var SmartUcfEndpointPolicy */
    private $endpointPolicy;

    public function __construct(
        ?FinancingSnapshotStoreInterface $snapshots = null,
        ?LeasingMailDispatchPort $mailDispatcher = null,
        ?BankStatusPersistencePort $bankStatus = null,
        ?SmartUcfEndpointPolicy $endpointPolicy = null
    ) {
        $this->snapshots = $snapshots ?? new FinancingSnapshotRepository();
        $this->mailDispatcher = $mailDispatcher ?? new Phase11DeferredMailDispatcher();
        $this->bankStatus = $bankStatus ?? new OrderBankStatusRepository();
        $this->endpointPolicy = $endpointPolicy ?? new SmartUcfEndpointPolicy();
    }

    /**
     * @param array<string, mixed> $shop
     */
    public function handle(
        OrderOrchestrationResult $order,
        array $shop,
        PostControlPanelLifecycleContext $context,
        PostControlPanelSmartUcfPort $smartUcfCoordinator
    ): PostControlPanelLifecycleResult {
        try {
            return $this->handleInternal($order, $shop, $context, $smartUcfCoordinator);
        } catch (\Throwable $exception) {
            DeferredOrderMailQueue::flush();
            \PrestaShopLogger::addLog(
                'UniPayment post-CP lifecycle failed: ' . get_class($exception),
                2
            );

            return PostControlPanelLifecycleResult::postOrderFailure(
                'The order was created, but additional processing was not completed.'
            );
        }
    }

    /**
     * @param array<string, mixed> $shop
     */
    private function handleInternal(
        OrderOrchestrationResult $order,
        array $shop,
        PostControlPanelLifecycleContext $context,
        PostControlPanelSmartUcfPort $smartUcfCoordinator
    ): PostControlPanelLifecycleResult {
        $snapshot = $this->snapshots->findByAttempt($order->attemptId);
        if ($snapshot === null) {
            DeferredOrderMailQueue::flush();

            return PostControlPanelLifecycleResult::snapshotMissing();
        }

        $process2 = ShopConfigurationFlags::isProcess2($shop);
        $finalStatus = BankStatus::successfulSend($process2);

        if ($process2) {
            $result = PostControlPanelLifecycleResult::process2($finalStatus);
            if ($context->sendLeasingEmail) {
                $this->persistBankStatus($context->idShop, $order->orderReference, $finalStatus);
                $result = $this->dispatchLeasingEmail($result, $snapshot, $order->attemptId, $shop, $finalStatus);
            }

            return $result;
        }

        $shop['_currency_iso'] = $context->currencyIso;
        $smart = $context->resumeSmartUcf
            ? $smartUcfCoordinator->resume($order->attemptId, $shop, false)
            : $smartUcfCoordinator->run($order->attemptId, $shop, false, $snapshot);

        $result = $this->normalizeSmartUcfResult($smart, $finalStatus);

        if ($result->isFailed()) {
            $failedStatus = $result->finalBankStatus();
            if ($failedStatus !== null) {
                $this->persistBankStatus($context->idShop, $order->orderReference, $failedStatus);
            }
        }

        if ($result->isProcessing() || !$context->sendLeasingEmail) {
            return $result;
        }

        $emailStatus = $result->finalBankStatus() ?? $finalStatus;

        return $this->dispatchLeasingEmail($result, $snapshot, $order->attemptId, $shop, $emailStatus);
    }

    private function normalizeSmartUcfResult(
        SmartUcfCoordinationResult $smart,
        array $defaultSuccessStatus
    ): PostControlPanelLifecycleResult {
        if ($smart->isProcessing()) {
            $message = $smart->customerMessage() !== ''
                ? $smart->customerMessage()
                : SmartUcfSessionCoordinator::CUSTOMER_PROCESSING;

            return PostControlPanelLifecycleResult::smartUcfProcessing($message);
        }

        if ($smart->isCreated()) {
            $redirectUrl = $smart->redirectUrl();
            if (!$this->endpointPolicy->isTrustedApplicationRedirect($redirectUrl)) {
                \PrestaShopLogger::addLog(
                    'UniPayment blocked untrusted SmartUCF redirect after create.',
                    3
                );

                return PostControlPanelLifecycleResult::smartUcfOutcomeUnknown(
                    SmartUcfSessionCoordinator::CUSTOMER_OUTCOME_UNKNOWN,
                    $defaultSuccessStatus
                );
            }

            return PostControlPanelLifecycleResult::smartUcfCreated(
                $redirectUrl,
                $defaultSuccessStatus
            );
        }

        if ($smart->isOutcomeUnknown()) {
            $message = $smart->customerMessage() !== ''
                ? $smart->customerMessage()
                : SmartUcfSessionCoordinator::CUSTOMER_OUTCOME_UNKNOWN;

            return PostControlPanelLifecycleResult::smartUcfOutcomeUnknown(
                $message,
                $defaultSuccessStatus
            );
        }

        if ($smart->isFailed()) {
            $message = $smart->customerMessage() !== ''
                ? $smart->customerMessage()
                : SmartUcfSessionCoordinator::CUSTOMER_FAILED;

            return PostControlPanelLifecycleResult::smartUcfFailed(
                $message,
                BankStatus::smartUcfFailure()
            );
        }

        return PostControlPanelLifecycleResult::smartUcfOutcomeUnknown(
            SmartUcfSessionCoordinator::CUSTOMER_OUTCOME_UNKNOWN,
            $defaultSuccessStatus
        );
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $shop
     * @param array{status_id: string, status_label: string} $status
     */
    private function dispatchLeasingEmail(
        PostControlPanelLifecycleResult $result,
        array $snapshot,
        int $attemptId,
        array $shop,
        array $status
    ): PostControlPanelLifecycleResult {
        try {
            $this->mailDispatcher->send($snapshot, $attemptId, $shop, $status);

            return $result->withEmailSent(true);
        } catch (\Throwable $exception) {
            \PrestaShopLogger::addLog(
                'UniPayment leasing email failed: ' . get_class($exception),
                2
            );

            return $result->withEmailSent(false, 'Leasing email could not be sent.');
        }
    }

    /** @param array{status_id: string, status_label: string} $status */
    private function persistBankStatus(int $idShop, string $orderReference, array $status): void
    {
        try {
            $this->bankStatus->updateByOrderIdentifier(
                $idShop,
                $orderReference,
                $status['status_id'],
                $status['status_label']
            );
        } catch (\Throwable $exception) {
            \PrestaShopLogger::addLog(
                'UniPayment local bank status update failed: ' . get_class($exception),
                2
            );
        }
    }
}
