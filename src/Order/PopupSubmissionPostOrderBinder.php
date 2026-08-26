<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/**
 * Best-effort popup submission → order_created after a durable PS order exists.
 * Metadata persistence must never authorize a fresh financing submission.
 */
final class PopupSubmissionPostOrderBinder
{
    public static function bind(
        \PrestaShop\Module\Unipayment\Product\PopupSubmissionRepository $submissions,
        int $submissionId,
        int $attemptId,
        int $idOrder,
        string $orderReference,
        int $controlPanelOrderId = 0
    ): void {
        if ($submissionId <= 0 || $idOrder <= 0) {
            return;
        }

        try {
            $submissions->markOrderCreated(
                $submissionId,
                $attemptId,
                $idOrder,
                $orderReference,
                $controlPanelOrderId
            );
        } catch (\Throwable $exception) {
            if (class_exists(\PrestaShopLogger::class, false) || class_exists('PrestaShopLogger', false)) {
                try {
                    \PrestaShopLogger::addLog(
                        'UniPayment popup markOrderCreated failed after durable order'
                            . ' id_order=' . $idOrder
                            . ' id_attempt=' . $attemptId
                            . ' exception=' . get_class($exception),
                        2
                    );
                } catch (\Throwable $ignored) {
                    unset($ignored);
                }
            }
        }
    }
}
