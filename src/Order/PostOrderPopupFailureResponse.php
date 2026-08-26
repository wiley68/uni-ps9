<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/**
 * Popup JSON for post-order CP create failure (SmartUCF Step 3 contract).
 */
final class PostOrderPopupFailureResponse
{
    public const CUSTOMER_CP_FAILED =
    "Поръчката е създадена\n\n"
        . "Поръчката Ви е регистрирана успешно в магазина, но заявката за финансиране не беше регистрирана успешно в системата на УниКредит.\n\n"
        . "Не изпращайте поръчката повторно.\n\n"
        . "При необходимост търговецът ще се свърже с Вас.";

    public const CUSTOMER_CP_UNKNOWN =
    "Поръчката е създадена\n\n"
        . "Поръчката е създадена в магазина, но потвърждението за регистрацията на финансирането не беше получено.\n\n"
        . "Не изпращайте поръчката повторно.\n\n"
        . "Търговецът ще провери статуса на заявката.";

    /**
     * @return array<string, mixed>
     */
    public static function fromException(OrderOrchestrationException $exception): array
    {
        return self::build(
            $exception->idOrder(),
            $exception->orderReference(),
            $exception->isOutcomeUnknown()
                || $exception->state() === OrderOrchestrator::CP_OUTCOME_UNKNOWN
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromPersistedOrder(
        int $idOrder,
        string $orderReference,
        ?OrderConfirmationFinancingOutcomePresenter $presenter = null
    ): array {
        $outcome = ($presenter ?? new OrderConfirmationFinancingOutcomePresenter())->outcome($idOrder);

        return self::build(
            $idOrder,
            $orderReference,
            $outcome === OrderConfirmationFinancingOutcomePresenter::CP_OUTCOME_UNKNOWN
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function build(int $idOrder, string $orderReference, bool $outcomeUnknown): array
    {
        $message = $outcomeUnknown ? self::CUSTOMER_CP_UNKNOWN : self::CUSTOMER_CP_FAILED;

        return [
            'success' => true,
            'step' => $outcomeUnknown ? 'outcome_unknown' : 'order_created',
            'order' => [
                'id_order' => $idOrder,
                'order_reference' => $orderReference,
                'control_panel_order_id' => 0,
            ],
            'smartucf_error' => $message,
            'cp_error' => $message,
            'final' => true,
        ];
    }
}
