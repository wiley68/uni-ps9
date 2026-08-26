<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

use PrestaShop\Module\Unipayment\Api\Exception\ConnectionException;
use PrestaShop\Module\Unipayment\Api\Exception\ControlPanelException;
use PrestaShop\Module\Unipayment\Api\Exception\HttpException;
use PrestaShop\Module\Unipayment\Checkout\ValidatedPaymentRequest;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationFlags;

final class OrderOrchestrator
{
    public const RESERVED = 'reserved';
    public const PS_ORDER_CREATED = 'ps_order_created';
    public const CP_SUBMITTING = 'cp_submitting';
    public const CP_CREATED = 'cp_created';
    public const CP_FAILED_RETRYABLE = 'cp_failed_retryable';
    public const CP_OUTCOME_UNKNOWN = 'cp_outcome_unknown';
    public const TERMINAL_FAILED = 'terminal_failed';

    /** @var OrderAttemptStoreInterface */
    private $attempts;
    /** @var FinancingSnapshotStoreInterface */
    private $snapshots;
    /** @var PrestaShopOrderGatewayInterface */
    private $orders;
    /** @var ControlPanelOrderClientInterface */
    private $cp;
    /** @var FinancingSnapshotFactory */
    private $snapshotFactory;
    /** @var ControlPanelOrderPayloadBuilder */
    private $payloads;

    /** @var BankStatusPersistencePort|null */
    private $bankStatus;

    public function __construct(
        OrderAttemptStoreInterface $attempts,
        FinancingSnapshotStoreInterface $snapshots,
        PrestaShopOrderGatewayInterface $orders,
        ControlPanelOrderClientInterface $cp,
        FinancingSnapshotFactory $snapshotFactory,
        ControlPanelOrderPayloadBuilder $payloads,
        ?BankStatusPersistencePort $bankStatus = null
    ) {
        $this->attempts = $attempts;
        $this->snapshots = $snapshots;
        $this->orders = $orders;
        $this->cp = $cp;
        $this->snapshotFactory = $snapshotFactory;
        $this->payloads = $payloads;
        $this->bankStatus = $bankStatus;
    }

    /** @param array<string, mixed> $shop */
    public function orchestrate(int $idShop, int $idCart, ValidatedPaymentRequest $request, array $shop, string $submissionSource = 'checkout'): OrderOrchestrationResult
    {
        $attempt = $this->attempts->reserve($idShop, $idCart, $request->cartFingerprint);
        $attemptId = (int) $attempt['id_attempt'];
        if ((string) $attempt['state'] === self::CP_CREATED) {
            return $this->result($attempt, true);
        }
        if ((string) $attempt['state'] === self::TERMINAL_FAILED) {
            throw new OrderOrchestrationException(
                'The financing attempt cannot be retried.',
                false,
                null,
                (int) ($attempt['id_order'] ?? 0),
                $attemptId,
                self::TERMINAL_FAILED,
                false,
                (string) ($attempt['order_reference'] ?? '')
            );
        }
        // reserved + no id_order is recoverable for the CheckoutSubmitLock owner
        // (crash after validateOrder before attempt attach). Non-reserved mid-states
        // without an order remain blocked. Live concurrency is enforced by the lock.
        if (
            (int) ($attempt['id_order'] ?? 0) <= 0
            && (string) $attempt['state'] !== self::RESERVED
        ) {
            throw new OrderOrchestrationException('The financing attempt is already being processed.', true);
        }

        /** @var CreatedOrder|null $order */
        $order = null;

        try {
            $snapshot = $this->snapshots->findByAttempt($attemptId);
            if ((int) ($attempt['id_order'] ?? 0) > 0) {
                $order = $this->orders->load((int) $attempt['id_order']);
                if ($snapshot === null) {
                    if ($order->lines === []) {
                        $this->attempts->update($attemptId, [
                            'state' => self::TERMINAL_FAILED,
                            'last_error_class' => 'EmptyOrderLines',
                        ]);
                        DeferredOrderMailQueue::discard();
                        throw new OrderOrchestrationException(
                            'The created financing order has no order lines.',
                            false,
                            null,
                            $order->idOrder,
                            $attemptId,
                            self::TERMINAL_FAILED,
                            false,
                            $order->reference
                        );
                    }
                    if (abs($order->total - $request->calculation->price) > 0.01) {
                        $this->attempts->update($attemptId, ['state' => self::TERMINAL_FAILED, 'last_error_class' => 'OrderTotalMismatch']);
                        DeferredOrderMailQueue::discard();
                        throw new OrderOrchestrationException(
                            'The created order total does not match the validated cart total.',
                            false,
                            null,
                            $order->idOrder,
                            $attemptId,
                            self::TERMINAL_FAILED,
                            false,
                            $order->reference
                        );
                    }
                    $snapshot = $this->snapshotFactory->create($request, $order, $submissionSource);
                    $this->persistSnapshot($attemptId, $snapshot, $order);
                }
            } else {
                // Idempotent: gateway recovers via Order::getIdByCartId() when PS order exists.
                // create() only throws when no native order exists (true pre-order failure).
                $order = $this->orders->create($request, $shop);
                $attempt = $this->attachNativeOrder($attemptId, $order);
                if ($order->lines === []) {
                    $this->attempts->update($attemptId, [
                        'state' => self::TERMINAL_FAILED,
                        'last_error_class' => 'EmptyOrderLines',
                    ]);
                    DeferredOrderMailQueue::discard();
                    throw new OrderOrchestrationException(
                        'The created financing order has no order lines.',
                        false,
                        null,
                        $order->idOrder,
                        $attemptId,
                        self::TERMINAL_FAILED,
                        false,
                        $order->reference
                    );
                }
                if (abs($order->total - $request->calculation->price) > 0.01) {
                    $this->attempts->update($attemptId, ['state' => self::TERMINAL_FAILED, 'last_error_class' => 'OrderTotalMismatch']);
                    DeferredOrderMailQueue::discard();
                    throw new OrderOrchestrationException(
                        'The created order total does not match the validated cart total.',
                        false,
                        null,
                        $order->idOrder,
                        $attemptId,
                        self::TERMINAL_FAILED,
                        false,
                        $order->reference
                    );
                }
                $snapshot = $this->snapshotFactory->create($request, $order, $submissionSource);
                $this->persistSnapshot($attemptId, $snapshot, $order);
            }

            if ($order->lines === []) {
                $this->attempts->update($attemptId, [
                    'state' => self::TERMINAL_FAILED,
                    'last_error_class' => 'EmptyOrderLines',
                ]);
                DeferredOrderMailQueue::discard();
                throw new OrderOrchestrationException(
                    'The financing order has no order lines.',
                    false,
                    null,
                    $order->idOrder,
                    $attemptId,
                    self::TERMINAL_FAILED,
                    false,
                    $order->reference
                );
            }

            return $this->submitToControlPanel($attempt, $attemptId, $order, $snapshot, $idShop, $shop);
        } catch (OrderOrchestrationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            if ($order instanceof CreatedOrder || (int) ($attempt['id_order'] ?? 0) > 0) {
                throw $this->normalizeEscapedThrowable($exception, $order, $attemptId, $attempt);
            }

            // True pre-order failure: no native order identity is known — preserve original.
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $attempt
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $shop
     */
    private function submitToControlPanel(
        array $attempt,
        int $attemptId,
        CreatedOrder $order,
        array $snapshot,
        int $idShop,
        array $shop
    ): OrderOrchestrationResult {
        try {
            $payload = isset($attempt['cp_payload']) && is_string($attempt['cp_payload']) && $attempt['cp_payload'] !== ''
                ? json_decode($attempt['cp_payload'], true)
                : null;
            if (!is_array($payload)) {
                $payload = $this->payloads->build($snapshot, $shop);
                $attempt = $this->attempts->update($attemptId, ['cp_payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
            }
            $this->attempts->update($attemptId, ['state' => self::CP_SUBMITTING, 'last_error_class' => null]);

            $response = $this->cp->createOrder($payload);
            $cpId = (int) ($response['data']['id'] ?? 0);
            if ($cpId <= 0) {
                $this->recordControlPanelFailure(
                    $attemptId,
                    $order,
                    $idShop,
                    $shop,
                    self::TERMINAL_FAILED,
                    'MissingControlPanelOrderId'
                );
                throw new OrderOrchestrationException(
                    'The Control Panel did not return an order identifier.',
                    false,
                    null,
                    $order->idOrder,
                    $attemptId,
                    self::TERMINAL_FAILED,
                    false,
                    $order->reference
                );
            }
            $attempt = $this->attempts->update($attemptId, ['state' => self::CP_CREATED, 'control_panel_order_id' => $cpId]);
            $this->snapshots->update($attemptId, ['control_panel_order_id' => $cpId, 'lifecycle_status' => self::CP_CREATED]);

            return $this->result($attempt);
        } catch (OrderOrchestrationException $exception) {
            throw $exception;
        } catch (ConnectionException $exception) {
            $this->recordControlPanelFailure(
                $attemptId,
                $order,
                $idShop,
                $shop,
                self::CP_OUTCOME_UNKNOWN,
                get_class($exception)
            );
            throw new OrderOrchestrationException(
                'The Control Panel result is unknown and can be retried safely.',
                true,
                $exception,
                $order->idOrder,
                $attemptId,
                self::CP_OUTCOME_UNKNOWN,
                true,
                $order->reference
            );
        } catch (HttpException $exception) {
            $retryable = $exception->getStatusCode() >= 500;
            $state = $retryable ? self::CP_FAILED_RETRYABLE : self::TERMINAL_FAILED;
            $this->recordControlPanelFailure(
                $attemptId,
                $order,
                $idShop,
                $shop,
                $state,
                get_class($exception)
            );
            throw new OrderOrchestrationException(
                'The Control Panel rejected the financing order.',
                $retryable,
                $exception,
                $order->idOrder,
                $attemptId,
                $state,
                false,
                $order->reference
            );
        } catch (ControlPanelException $exception) {
            // InvalidPayload / MalformedJson: remote create may have occurred — outcome unknown.
            $this->recordControlPanelFailure(
                $attemptId,
                $order,
                $idShop,
                $shop,
                self::CP_OUTCOME_UNKNOWN,
                get_class($exception)
            );
            throw new OrderOrchestrationException(
                'The Control Panel result is unknown and can be retried safely.',
                true,
                $exception,
                $order->idOrder,
                $attemptId,
                self::CP_OUTCOME_UNKNOWN,
                true,
                $order->reference
            );
        } catch (\Throwable $exception) {
            $this->recordControlPanelFailure(
                $attemptId,
                $order,
                $idShop,
                $shop,
                self::CP_OUTCOME_UNKNOWN,
                get_class($exception)
            );
            throw new OrderOrchestrationException(
                'The Control Panel result is unknown and can be retried safely.',
                true,
                $exception,
                $order->idOrder,
                $attemptId,
                self::CP_OUTCOME_UNKNOWN,
                true,
                $order->reference
            );
        }
    }

    /** @return array<string, mixed> */
    private function attachNativeOrder(int $attemptId, CreatedOrder $order): array
    {
        try {
            return $this->attempts->attachOrderIfReserved($attemptId, $order->idOrder, $order->reference);
        } catch (\Throwable $exception) {
            $this->logPostOrderBoundary($order->idOrder, $attemptId, $exception, 'attach');
            throw new OrderOrchestrationException(
                'The financing order was created but could not be attached to the attempt.',
                true,
                $exception,
                $order->idOrder,
                $attemptId,
                self::PS_ORDER_CREATED,
                false,
                $order->reference
            );
        }
    }

    /** @param array<string, mixed> $snapshot */
    private function persistSnapshot(int $attemptId, array $snapshot, CreatedOrder $order): void
    {
        try {
            $this->saveSnapshot($attemptId, $snapshot);
        } catch (\Throwable $exception) {
            $this->logPostOrderBoundary($order->idOrder, $attemptId, $exception, 'snapshot');
            throw new OrderOrchestrationException(
                'The financing order was created but the snapshot could not be saved.',
                true,
                $exception,
                $order->idOrder,
                $attemptId,
                self::PS_ORDER_CREATED,
                false,
                $order->reference
            );
        }
    }

    /**
     * @param array<string, mixed> $attempt
     */
    private function normalizeEscapedThrowable(
        \Throwable $exception,
        ?CreatedOrder $order,
        int $attemptId,
        array $attempt
    ): OrderOrchestrationException {
        if ($order instanceof CreatedOrder) {
            $this->logPostOrderBoundary($order->idOrder, $attemptId, $exception, 'post-order');

            return new OrderOrchestrationException(
                'The financing order was created but a later step failed.',
                true,
                $exception,
                $order->idOrder,
                $attemptId,
                self::PS_ORDER_CREATED,
                false,
                $order->reference
            );
        }

        $idOrder = (int) ($attempt['id_order'] ?? 0);
        $this->logPostOrderBoundary($idOrder, $attemptId, $exception, 'attempt-order');

        return new OrderOrchestrationException(
            'The financing order was created but a later step failed.',
            true,
            $exception,
            $idOrder,
            $attemptId,
            (string) ($attempt['state'] ?? self::PS_ORDER_CREATED),
            false,
            (string) ($attempt['order_reference'] ?? '')
        );
    }

    private function logPostOrderBoundary(int $idOrder, int $attemptId, \Throwable $exception, string $phase): void
    {
        if (!class_exists(\PrestaShopLogger::class, false) && !class_exists('PrestaShopLogger', false)) {
            return;
        }
        try {
            \PrestaShopLogger::addLog(
                'UniPayment post-order boundary'
                    . ' phase=' . $phase
                    . ' id_order=' . $idOrder
                    . ' id_attempt=' . $attemptId
                    . ' exception=' . get_class($exception),
                2
            );
        } catch (\Throwable $ignored) {
            unset($ignored);
        }
    }

    /** @param array<string, mixed> $attempt */
    private function result(array $attempt, bool $recovered = false): OrderOrchestrationResult
    {
        return new OrderOrchestrationResult(
            (int) $attempt['id_attempt'],
            (string) $attempt['state'],
            (int) $attempt['id_order'],
            (string) $attempt['order_reference'],
            (int) ($attempt['control_panel_order_id'] ?? 0),
            $recovered
        );
    }

    /**
     * Persist attempt/snapshot/admin UniCredit status after PS order exists and CP create failed.
     *
     * @param array<string, mixed> $shop
     */
    private function recordControlPanelFailure(
        int $attemptId,
        CreatedOrder $order,
        int $idShop,
        array $shop,
        string $state,
        string $errorClass
    ): void {
        $this->attempts->update($attemptId, ['state' => $state, 'last_error_class' => $errorClass]);
        $this->snapshots->update($attemptId, ['lifecycle_status' => $state]);
        DeferredOrderMailQueue::discard();
        if ($this->bankStatus === null || $order->reference === '') {
            return;
        }

        $status = BankStatus::controlPanelFailure(ShopConfigurationFlags::isProcess2($shop));
        try {
            $this->bankStatus->updateByOrderIdentifier(
                $idShop,
                $order->reference,
                $status['status_id'],
                $status['status_label']
            );
        } catch (\Throwable $exception) {
            \PrestaShopLogger::addLog(
                'UniPayment local CP-failure status update failed: ' . get_class($exception),
                2
            );
        }
    }

    /** @param array<string, mixed> $snapshot */
    private function saveSnapshot(int $attemptId, array $snapshot): void
    {
        $this->snapshots->save($attemptId, $snapshot);
        try {
            (new FinancingSnapshotRetentionService())->maybeRun();
        } catch (\Throwable $exception) {
            // Opportunistic privacy cleanup must not block financing submission.
        }
    }
}
