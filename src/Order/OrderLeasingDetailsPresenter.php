<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationCache;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationFlags;

/**
 * Leasing detail rows for admin, emails, and the Process 2 thank-you page.
 */
final class OrderLeasingDetailsPresenter
{
    /** @var FinancingSnapshotRepository */
    private $snapshots;
    /** @var OrderBankStatusRepository */
    private $bankStatuses;
    /** @var LeasingOrderEmailPresenter */
    private $rows;
    /** @var ConfigurationRepository */
    private $configuration;
    /** @var ShopConfigurationCache */
    private $cache;

    public function __construct(
        ?FinancingSnapshotRepository $snapshots = null,
        ?OrderBankStatusRepository $bankStatuses = null,
        ?LeasingOrderEmailPresenter $rows = null,
        ?ConfigurationRepository $configuration = null,
        ?ShopConfigurationCache $cache = null
    ) {
        $this->snapshots = $snapshots ?? new FinancingSnapshotRepository();
        $this->bankStatuses = $bankStatuses ?? new OrderBankStatusRepository();
        $this->rows = $rows ?? new LeasingOrderEmailPresenter();
        $this->configuration = $configuration ?? new ConfigurationRepository();
        $this->cache = $cache ?? new ShopConfigurationCache();
    }

    /**
     * Back-office financing block (admin audience + safe operational diagnostics).
     *
     * @return array<string, string>
     */
    public function rowsForOrder(int $idOrder): array
    {
        $snapshot = $this->snapshots->findByOrderId($idOrder);
        if ($snapshot === null) {
            return [];
        }

        $shop = $this->shop();
        $leasingRows = $this->rows->adminRowsFromSnapshot($snapshot, $shop);
        if ($leasingRows === []) {
            return [];
        }

        $bankStatus = $this->bankStatuses->findByOrderId($idOrder);
        $leasingRows = $this->rows->applyBankStatusLabel(
            $leasingRows,
            (string) ($bankStatus['status_label'] ?? '')
        );

        return $this->appendOperationalDiagnostics($leasingRows, $snapshot, $bankStatus ?? [], $shop);
    }

    /**
     * Customer thank-you parity: Process 2 orders only; customer audience (no EGN).
     *
     * @return array<string, string>
     */
    public function thankYouRows(int $idOrder): array
    {
        $bankStatus = $this->bankStatuses->findByOrderId($idOrder);
        $statusId = (string) ($bankStatus['status_id'] ?? '');
        if ($statusId !== '' && $statusId !== BankStatus::SENT_PROCESS2) {
            return [];
        }

        $shop = $this->shop();
        if ($statusId === '' && !ShopConfigurationFlags::isProcess2($shop)) {
            return [];
        }

        $snapshot = $this->snapshots->findByOrderId($idOrder);
        if ($snapshot === null) {
            return [];
        }

        $leasingRows = $this->rows->customerRowsFromSnapshot($snapshot, $shop);
        if ($leasingRows === []) {
            return [];
        }

        return $this->rows->applyBankStatusLabel(
            $leasingRows,
            (string) ($bankStatus['status_label'] ?? '')
        );
    }

    /**
     * @param array<string, string> $rows
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $bankStatus
     * @param array<string, mixed> $shop
     *
     * @return array<string, string>
     */
    private function appendOperationalDiagnostics(
        array $rows,
        array $snapshot,
        array $bankStatus,
        array $shop
    ): array {
        $statusId = trim((string) ($bankStatus['status_id'] ?? ''));
        $process2 = ShopConfigurationFlags::isProcess2($shop)
            || $statusId === BankStatus::SENT_PROCESS2;

        $rows['Процес'] = $process2 ? 'Процес 2' : 'Процес 1';

        $cpId = (int) ($snapshot['control_panel_order_id'] ?? 0);
        $rows['Control Panel order ID'] = $cpId > 0 ? (string) $cpId : '—';

        if ($statusId === BankStatus::SEND_FAILED_CP || $statusId === BankStatus::SEND_FAILED) {
            $rows['Диагностика'] = 'PS order exists; Control Panel create failed or outcome unknown.';
        } elseif ($statusId === BankStatus::SEND_FAILED_SMARTUCF) {
            $rows['Диагностика'] = 'Control Panel order exists; SmartUCF processing failed.';
        }

        if ($process2) {
            return $rows;
        }

        $smartState = trim((string) ($snapshot['smartucf_state'] ?? ''));
        if ($smartState !== '' && $smartState !== 'not_started') {
            $rows['SmartUCF state'] = $smartState;
        }

        $sessionId = trim((string) ($snapshot['smartucf_session_id'] ?? ''));
        if ($sessionId !== '') {
            $rows['SmartUCF session'] = $sessionId;
        }

        $httpCode = $snapshot['smartucf_http_code'] ?? null;
        if ($httpCode !== null && $httpCode !== '') {
            $rows['SmartUCF HTTP'] = (string) (int) $httpCode;
        }

        $errorClass = trim((string) ($snapshot['smartucf_error_class'] ?? ''));
        if ($errorClass !== '') {
            $rows['SmartUCF error class'] = $errorClass;
        }

        if (array_key_exists('smartucf_retryable', $snapshot) && $smartState !== '') {
            $rows['SmartUCF retryable'] = !empty($snapshot['smartucf_retryable']) ? 'yes' : 'no';
        }

        $claimedAt = trim((string) ($snapshot['smartucf_claimed_at'] ?? ''));
        if ($claimedAt !== '') {
            $rows['SmartUCF claimed at'] = $claimedAt;
        }

        $completedAt = trim((string) ($snapshot['smartucf_completed_at'] ?? ''));
        if ($completedAt !== '') {
            $rows['SmartUCF completed at'] = $completedAt;
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function shop(): array
    {
        $unicid = $this->configuration->getUnicid();
        if ($unicid === '') {
            return [];
        }

        return $this->cache->getFresh($unicid) ?? [];
    }
}
