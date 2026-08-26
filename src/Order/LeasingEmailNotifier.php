<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/**
 * Sends audience-specific leasing emails once per financing attempt.
 *
 * Invariant: `leasing_email_sent = 1` means every required audience send for this
 * invocation completed successfully (Mail::Send returned true and did not throw).
 * Partial failure leaves the marker unset so safe replay can retry.
 *
 * Residual risk (accepted): after partial success, retry may resend the audience
 * that already received mail (single combined marker; no new columns).
 *
 * No recipient / empty rows: notification not required; marker stays unset.
 */
final class LeasingEmailNotifier
{
    /** @var FinancingSnapshotStoreInterface */
    private $snapshots;

    /** @var LeasingOrderEmailPresenter */
    private $presenter;

    /**
     * Optional test seam: (...args) => bool. Null uses \Mail::Send.
     *
     * @var callable|null
     */
    private $mailSender;

    public function __construct(
        ?FinancingSnapshotStoreInterface $snapshots = null,
        ?LeasingOrderEmailPresenter $presenter = null,
        ?callable $mailSender = null
    ) {
        $this->snapshots = $snapshots ?? new FinancingSnapshotRepository();
        $this->presenter = $presenter ?? new LeasingOrderEmailPresenter();
        $this->mailSender = $mailSender;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $shop
     *
     * @throws LeasingEmailDeliveryException When a required Mail::Send fails or throws
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
            // Incomplete configuration / no audience — nothing required; do not mark sent.
            return;
        }

        $customerRows = $this->presenter->customerRowsFromSnapshot($snapshot, $shop);
        $adminRows = $this->presenter->adminRowsFromSnapshot($snapshot, $shop);
        if ($customerRows === [] && $adminRows === []) {
            // No presentable financing rows — nothing required; do not mark sent.
            return;
        }

        $orderReference = (string) ($snapshot['order_reference'] ?? '');
        $subject = sprintf('УниКредит лизинг — %s', $orderReference);
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

        /** @var list<array{audience: string, to: string, rows: array<string, string>}> $required */
        $required = [];

        if ($sameRecipient) {
            if ($adminRows !== []) {
                $required[] = [
                    'audience' => EmailAudience::ADMIN,
                    'to' => $adminEmail,
                    'rows' => $adminRows,
                ];
            }
        } else {
            if ($customerEmail !== '' && $customerRows !== []) {
                $required[] = [
                    'audience' => EmailAudience::CUSTOMER,
                    'to' => $customerEmail,
                    'rows' => $customerRows,
                ];
            }
            if ($adminEmail !== '' && $adminRows !== []) {
                $required[] = [
                    'audience' => EmailAudience::ADMIN,
                    'to' => $adminEmail,
                    'rows' => $adminRows,
                ];
            }
        }

        if ($required === []) {
            return;
        }

        $allSucceeded = true;
        foreach ($required as $send) {
            if (!$this->sendLeasingMail(
                $send['to'],
                $subject,
                $send['rows'],
                $languageId,
                $fromEmail,
                $fromName,
                $moduleMailsDir,
                $send['audience'],
                $orderReference
            )) {
                $allSucceeded = false;
            }
        }

        if (!$allSucceeded) {
            throw new LeasingEmailDeliveryException(
                'One or more required leasing emails could not be sent.'
            );
        }

        try {
            $this->snapshots->update($attemptId, ['leasing_email_sent' => 1]);
        } catch (\Throwable $exception) {
            \PrestaShopLogger::addLog(
                'UniPayment leasing email marker could not be stored.',
                2
            );
            throw new LeasingEmailDeliveryException(
                'Leasing emails were accepted but the sent marker could not be stored.',
                0,
                $exception
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
        string $moduleMailsDir,
        string $audience,
        string $orderReference
    ): bool {
        if ($rows === []) {
            return true;
        }

        $textBody = $this->presenter->renderText($rows);
        $htmlBody = $this->presenter->renderHtml($rows);
        $templateVars = [
            '{message}' => trim($textBody),
            '{message_html}' => $htmlBody,
        ];

        try {
            $result = $this->dispatchMail(
                $languageId,
                'ordersend',
                $subject,
                $templateVars,
                $to,
                $fromEmail,
                $fromName,
                $moduleMailsDir
            );
        } catch (\Throwable $exception) {
            \PrestaShopLogger::addLog(
                sprintf(
                    'UniPayment leasing email failed: order=%s audience=%s exception=%s',
                    $orderReference,
                    $audience,
                    get_class($exception)
                ),
                2
            );

            return false;
        }

        if ($result !== true) {
            \PrestaShopLogger::addLog(
                sprintf(
                    'UniPayment leasing email failed: order=%s audience=%s exception=%s',
                    $orderReference,
                    $audience,
                    'MailSendReturnedFalse'
                ),
                2
            );

            return false;
        }

        return true;
    }

    /**
     * @param array<string, string> $templateVars
     */
    private function dispatchMail(
        int $languageId,
        string $template,
        string $subject,
        array $templateVars,
        string $to,
        string $fromEmail,
        string $fromName,
        string $moduleMailsDir
    ): bool {
        if ($this->mailSender !== null) {
            $result = ($this->mailSender)(
                $languageId,
                $template,
                $subject,
                $templateVars,
                $to,
                $fromEmail,
                $fromName,
                $moduleMailsDir
            );

            return $result === true;
        }

        return \Mail::Send(
            $languageId,
            $template,
            $subject,
            $templateVars,
            $to,
            null,
            $fromEmail,
            $fromName,
            null,
            null,
            $moduleMailsDir
        ) === true;
    }
}
