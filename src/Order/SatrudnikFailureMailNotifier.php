<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/**
 * Best-effort operational mail to CP "Сътрудник" after canonical financing send failure.
 *
 * Must only run from the leasing mail once-guard path. Never classifies status or mutates lifecycle.
 */
final class SatrudnikFailureMailNotifier
{
    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $shop
     * @param array{status_id?: string, status_label?: string} $status
     */
    public function notify(array $snapshot, array $shop, array $status): void
    {
        $statusId = (string) ($status['status_id'] ?? '');
        if (
            $statusId !== BankStatus::SEND_FAILED_CP
            && $statusId !== BankStatus::SEND_FAILED_SMARTUCF
        ) {
            return;
        }

        $to = trim((string) ($shop['satrudnik_email'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $orderReference = trim((string) ($snapshot['order_reference'] ?? ''));
        $idOrder = (int) ($snapshot['id_order'] ?? 0);
        $orderLabel = $orderReference !== '' ? $orderReference : (string) $idOrder;
        if ($orderLabel === '' || $orderLabel === '0') {
            $orderLabel = '—';
        }

        $statusLabel = trim((string) ($status['status_label'] ?? ''));
        if ($statusLabel === '') {
            $statusLabel = $statusId;
        }

        $lines = [
            'Проблем при изпращане на заявка за финансиране',
            '',
            'Магазин поръчка: ' . $orderLabel,
        ];

        $orderDate = $this->resolveOrderDate($idOrder);
        if ($orderDate !== '') {
            $lines[] = 'Дата на поръчката: ' . $orderDate;
        }

        $cpOrderId = (int) ($snapshot['control_panel_order_id'] ?? 0);
        if ($cpOrderId > 0) {
            $lines[] = 'КП поръчка: ' . $cpOrderId;
        }

        $lines[] = 'Статус: ' . $statusLabel . ' (' . $statusId . ')';

        $textBody = implode("\n", $lines);
        $htmlBody = '<p>' . implode('<br />', array_map(
            static function (string $line): string {
                return $line === '' ? '' : htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            },
            $lines
        )) . '</p>';

        $subject = sprintf(
            'Проблем при изпращане на заявка за финансиране - поръчка %s',
            $orderLabel
        );

        $languageId = (int) \Configuration::get('PS_LANG_DEFAULT');
        if ($languageId <= 0) {
            $languageId = 1;
        }
        $fromName = (string) \Configuration::get('PS_SHOP_NAME');
        $fromEmail = (string) \Configuration::get('PS_SHOP_EMAIL');
        $moduleMailsDir = _PS_MODULE_DIR_ . 'unipayment/mails';

        try {
            \Mail::Send(
                $languageId,
                'ordersend',
                $subject,
                [
                    '{message}' => $textBody,
                    '{message_html}' => $htmlBody,
                ],
                $to,
                null,
                $fromEmail,
                $fromName,
                null,
                null,
                $moduleMailsDir
            );
        } catch (\Throwable $exception) {
            if (class_exists('\\PrestaShopLogger', false)) {
                \PrestaShopLogger::addLog(
                    'UniPayment Satrudnik failure email could not be sent.',
                    2
                );
            }
        }
    }

    private function resolveOrderDate(int $idOrder): string
    {
        if ($idOrder <= 0 || !class_exists('\\Order')) {
            return '';
        }

        try {
            $order = new \Order($idOrder);
            if (class_exists('\\Validate') && method_exists('\\Validate', 'isLoadedObject')) {
                if (!\Validate::isLoadedObject($order)) {
                    return '';
                }
            } elseif (empty($order->id)) {
                return '';
            }

            return trim((string) ($order->date_add ?? ''));
        } catch (\Throwable $exception) {
            return '';
        }
    }
}
