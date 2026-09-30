<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Checkout;

use PrestaShop\Module\Unipayment\Order\FinancingSnapshotRepository;
use PrestaShop\Module\Unipayment\Order\OrderAttemptRepository;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrator;
use PrestaShop\Module\Unipayment\Order\OrderCurrencyGuard;
use PrestaShop\Module\Unipayment\Order\ControlPanelSuccessReplayGuard;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfEndpointPolicy;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfLifecycleStates;

/**
 * Observer/recovery for CheckoutSubmitLock losers (AUD-019 double-click follow-up).
 *
 * Must never create PS/CP/SmartUCF work — only reuse durable state.
 */
final class CheckoutLockLoserRecovery
{
    public const KIND_PROCESSING = 'processing';
    public const KIND_CONFIRMATION = 'confirmation';
    public const KIND_SMARTUCF_REDIRECT = 'smartucf_redirect';
    public const KIND_OUTCOME_UNKNOWN = 'outcome_unknown';

    /** @var OrderAttemptRepository|object */
    private $attempts;
    /** @var FinancingSnapshotRepository|object */
    private $snapshots;
    /** @var SmartUcfEndpointPolicy */
    private $endpointPolicy;
    /** @var callable */
    private $orderIdByCartResolver;
    private OrderCurrencyGuard $currencyGuard;

    /**
     * @param OrderAttemptRepository|object|null $attempts
     * @param FinancingSnapshotRepository|object|null $snapshots
     * @param callable|null $orderIdByCartResolver fn(int $idCart): int
     */
    public function __construct(
        $attempts = null,
        $snapshots = null,
        ?SmartUcfEndpointPolicy $endpointPolicy = null,
        ?callable $orderIdByCartResolver = null,
        ?OrderCurrencyGuard $currencyGuard = null
    ) {
        $this->attempts = $attempts ?? new OrderAttemptRepository();
        $this->currencyGuard = $currencyGuard ?? new OrderCurrencyGuard();
        $this->snapshots = $snapshots ?? new FinancingSnapshotRepository();
        $this->endpointPolicy = $endpointPolicy ?? new SmartUcfEndpointPolicy();
        $this->orderIdByCartResolver = $orderIdByCartResolver ?? static function (int $idCart): int {
            return (int) \Order::getIdByCartId($idCart);
        };
    }

    /**
     * @return array{
     *   kind: string,
     *   id_order: int,
     *   order_reference: string,
     *   control_panel_order_id: int,
     *   redirect_url: string,
     *   message: string
     * }
     */
    public function resolve(int $idShop, int $idCart): array
    {
        $idOrder = (int) ($this->orderIdByCartResolver)($idCart);
        $attempt = $this->attempts->findLatestByShopCart($idShop, $idCart);
        if ($idOrder <= 0 && is_array($attempt)) {
            $idOrder = (int) ($attempt['id_order'] ?? 0);
        }

        if ($idOrder <= 0) {
            return [
                'kind' => self::KIND_PROCESSING,
                'id_order' => 0,
                'order_reference' => '',
                'control_panel_order_id' => 0,
                'redirect_url' => '',
                'message' => 'Your financing request is currently being processed.',
            ];
        }

        $reference = '';
        $cpId = 0;
        if (is_array($attempt) && (int) ($attempt['id_order'] ?? 0) === $idOrder) {
            $reference = (string) ($attempt['order_reference'] ?? '');
            $cpId = (int) ($attempt['control_panel_order_id'] ?? 0);
        }

        $snapshot = $this->snapshots->findByOrderId($idOrder);
        if ($snapshot === null && is_array($attempt)) {
            $snapshot = $this->snapshots->findByAttempt((int) ($attempt['id_attempt'] ?? 0));
        }
        $cpSuccessProven = false;
        try {
            if (!is_array($snapshot) || (int) ($snapshot['id_order'] ?? 0) !== $idOrder) {
                throw new \RuntimeException('The financing snapshot is unavailable.');
            }
            $this->currencyGuard->assertNativeSnapshot($snapshot);
            if (is_array($attempt) && (int) ($attempt['id_order'] ?? 0) > 0
                && (int) $attempt['id_order'] !== $idOrder
            ) {
                throw new \RuntimeException('The financing attempt does not match the order.');
            }
            $hasCpResult = (string) ($attempt['state'] ?? '') === OrderOrchestrator::CP_CREATED
                || (int) ($snapshot['control_panel_order_id'] ?? 0) > 0
                || (int) ($attempt['control_panel_order_id'] ?? 0) > 0
                || (string) ($snapshot['smartucf_state'] ?? '') === SmartUcfLifecycleStates::CREATED;
            if ($hasCpResult) {
                if (!is_array($attempt) || (int) ($attempt['id_order'] ?? 0) !== $idOrder) {
                    throw new \RuntimeException('The Control Panel attempt is unavailable.');
                }
                (new ControlPanelSuccessReplayGuard($this->currencyGuard))->assertSuccessful($attempt, $snapshot);
                $cpSuccessProven = true;
            }
        } catch (\Throwable $exception) {
            return [
                'kind' => self::KIND_OUTCOME_UNKNOWN,
                'id_order' => $idOrder,
                'order_reference' => $reference,
                'control_panel_order_id' => $cpId,
                'redirect_url' => '',
                'message' => 'Поръчката е създадена, но финансирането не може да бъде потвърдено.',
            ];
        }
        if (!$cpSuccessProven) {
            $unknown = is_array($attempt)
                && (string) ($attempt['state'] ?? '') === OrderOrchestrator::CP_OUTCOME_UNKNOWN;
            return [
                'kind' => $unknown ? self::KIND_OUTCOME_UNKNOWN : self::KIND_PROCESSING,
                'id_order' => $idOrder,
                'order_reference' => $reference,
                'control_panel_order_id' => 0,
                'redirect_url' => '',
                'message' => $unknown
                    ? 'Поръчката е създадена, но потвърждението от банковата система не беше получено. Не изпращайте заявката повторно.'
                    : 'Заявката за финансиране се обработва. Моля, изчакайте.',
            ];
        }
        if (is_array($snapshot)) {
            if ($reference === '') {
                $reference = (string) ($snapshot['order_reference'] ?? '');
            }
            if ($cpId <= 0) {
                $cpId = (int) ($snapshot['control_panel_order_id'] ?? 0);
            }
            $smartState = (string) ($snapshot['smartucf_state'] ?? '');
            $redirect = trim((string) ($snapshot['smartucf_redirect_url'] ?? ''));
            if (
                $smartState === SmartUcfLifecycleStates::CREATED
                && $redirect !== ''
                && $this->endpointPolicy->isTrustedApplicationRedirect($redirect)
            ) {
                return [
                    'kind' => self::KIND_SMARTUCF_REDIRECT,
                    'id_order' => $idOrder,
                    'order_reference' => $reference,
                    'control_panel_order_id' => $cpId,
                    'redirect_url' => $redirect,
                    'message' => '',
                ];
            }
            if (
                $smartState === SmartUcfLifecycleStates::OUTCOME_UNKNOWN
                || (is_array($attempt) && (string) ($attempt['state'] ?? '') === OrderOrchestrator::CP_OUTCOME_UNKNOWN)
            ) {
                return [
                    'kind' => self::KIND_OUTCOME_UNKNOWN,
                    'id_order' => $idOrder,
                    'order_reference' => $reference,
                    'control_panel_order_id' => $cpId,
                    'redirect_url' => '',
                    'message' => 'Поръчката е създадена, но потвърждението от банковата система не беше получено. Не изпращайте заявката повторно.',
                ];
            }
            if ($smartState === SmartUcfLifecycleStates::SUBMITTING) {
                return [
                    'kind' => self::KIND_PROCESSING,
                    'id_order' => $idOrder,
                    'order_reference' => $reference,
                    'control_panel_order_id' => $cpId,
                    'redirect_url' => '',
                    'message' => 'Заявката към банката се обработва. Моля, изчакайте.',
                ];
            }
        }

        if ($reference === '') {
            $order = new \Order($idOrder);
            if (\Validate::isLoadedObject($order)) {
                $reference = (string) $order->reference;
            }
        }

        return [
            'kind' => self::KIND_CONFIRMATION,
            'id_order' => $idOrder,
            'order_reference' => $reference,
            'control_panel_order_id' => $cpId,
            'redirect_url' => '',
            'message' => '',
        ];
    }
}
