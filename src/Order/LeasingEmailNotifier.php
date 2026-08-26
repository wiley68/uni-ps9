<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

final class LeasingEmailNotifier
{
    /** @var FinancingSnapshotStoreInterface */
    private $snapshots;

    /** @var LeasingOrderEmailPresenter */
    private $presenter;

    public function __construct(?FinancingSnapshotStoreInterface $snapshots = null, ?LeasingOrderEmailPresenter $presenter = null)
    {
        $this->snapshots = $snapshots ?? new FinancingSnapshotRepository();
        $this->presenter = $presenter ?? new LeasingOrderEmailPresenter();
    }

    /**
     * Sends audience-specific leasing emails — once per attempt.
     *
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $shop
     */
    public function notify(array $snapshot, int $attemptId, array $shop = []): void
    {
        $current = $this->snapshots->findByAttempt($attemptId);
        if ($current !== null && !empty($current['leasing_email_sent'])) {
            return;
        }

        $customer = is_array($snapshot['customer_json'] ?? null) ? $snapshot['customer_json'] : [];
        $customerEmail = trim((string) ($customer['email'] ?? ''));
        $adminEmail = trim((string) \Configuration::get('PS_SHOP_EMAIL'));
        if ($customerEmail === '' && $adminEmail === '') {
            return;
        }

        $customerRows = $this->presenter->customerRowsFromSnapshot($snapshot, $shop);
        $adminRows = $this->presenter->adminRowsFromSnapshot($snapshot, $shop);
        if ($customerRows === [] && $adminRows === []) {
            return;
        }

        $subject = sprintf('УниКредит лизинг — %s', (string) ($snapshot['order_reference'] ?? ''));
        $languageId = (int) \Configuration::get('PS_LANG_DEFAULT');
        if ($languageId <= 0) {
            $languageId = 1;
        }
        $fromName = (string) \Configuration::get('PS_SHOP_NAME');
        $fromEmail = (string) \Configuration::get('PS_SHOP_EMAIL');
        $moduleMailsDir = _PS_MODULE_DIR_ . 'unipayment/mails';

        $sameRecipient = $customerEmail !== ''
            && $adminEmail !== ''
            && strcasecmp($customerEmail, $adminEmail) === 0;

        if ($sameRecipient) {
            $this->sendLeasingMail($adminEmail, $subject, $adminRows, $languageId, $fromEmail, $fromName, $moduleMailsDir);
        } else {
            if ($customerEmail !== '' && $customerRows !== []) {
                $this->sendLeasingMail($customerEmail, $subject, $customerRows, $languageId, $fromEmail, $fromName, $moduleMailsDir);
            }
            if ($adminEmail !== '' && $adminRows !== []) {
                $this->sendLeasingMail($adminEmail, $subject, $adminRows, $languageId, $fromEmail, $fromName, $moduleMailsDir);
            }
        }

        try {
            $this->snapshots->update($attemptId, ['leasing_email_sent' => 1]);
        } catch (\Throwable $exception) {
            \PrestaShopLogger::addLog(
                'UniPayment leasing email marker could not be stored.',
                2
            );
        }
    }

    /**
     * @param array<string, string> $rows
     */
    private function sendLeasingMail(
        string $to,
        string $subject,
        array $rows,
        int $languageId,
        string $fromEmail,
        string $fromName,
        string $moduleMailsDir
    ): void {
        if ($rows === []) {
            return;
        }

        $textBody = $this->presenter->renderText($rows);
        $htmlBody = $this->presenter->renderHtml($rows);

        try {
            \Mail::Send(
                $languageId,
                'ordersend',
                $subject,
                [
                    '{message}' => trim($textBody),
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
            \PrestaShopLogger::addLog(
                'UniPayment leasing email could not be sent.',
                2
            );
        }
    }
}
