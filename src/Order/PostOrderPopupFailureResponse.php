<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/**
 * Popup JSON for post-order CP create failure.
 *
 * Definitive CP failure (Shop order exists, public bank_send_failed_cp) is a
 * terminal customer-completed outcome: Thank You redirect when a confirmation
 * URL is supplied. Ambiguous CP outcome stays popup/Step-3 messaging.
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
    public static function fromException(OrderOrchestrationException $exception, string $thankYouUrl = ''): array
    {
        return self::build(
            $exception->idOrder(),
            $exception->orderReference(),
            $exception->isOutcomeUnknown()
                || $exception->state() === OrderOrchestrator::CP_OUTCOME_UNKNOWN,
            $thankYouUrl
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromPersistedOrder(
        int $idOrder,
        string $orderReference,
        ?OrderConfirmationFinancingOutcomePresenter $presenter = null,
        string $thankYouUrl = ''
    ): array {
        $outcome = ($presenter ?? new OrderConfirmationFinancingOutcomePresenter())->outcome($idOrder);

        return self::build(
            $idOrder,
            $orderReference,
            $outcome === OrderConfirmationFinancingOutcomePresenter::CP_OUTCOME_UNKNOWN,
            $thankYouUrl
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function build(
        int $idOrder,
        string $orderReference,
        bool $outcomeUnknown,
        string $thankYouUrl = ''
    ): array {
        $order = [
            'id_order' => $idOrder,
            'order_reference' => $orderReference,
            'control_panel_order_id' => 0,
        ];

        if ($outcomeUnknown) {
            $message = self::CUSTOMER_CP_UNKNOWN;

            return [
                'success' => true,
                'step' => 'outcome_unknown',
                'order' => $order,
                'smartucf_error' => $message,
                'cp_error' => $message,
                'final' => true,
            ];
        }

        $thankYouUrl = trim($thankYouUrl);
        if ($thankYouUrl !== '' && $idOrder > 0) {
            return [
                'success' => true,
                'step' => 'order_created',
                'order' => $order,
                'redirect_url' => $thankYouUrl,
                'final' => true,
            ];
        }

        $message = self::CUSTOMER_CP_FAILED;

        return [
            'success' => true,
            'step' => 'order_created',
            'order' => $order,
            'smartucf_error' => $message,
            'cp_error' => $message,
            'final' => true,
        ];
    }
}
