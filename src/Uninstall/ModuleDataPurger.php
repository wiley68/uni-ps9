<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Uninstall;

use PrestaShop\Module\Unipayment\Api\ControlPanelClient;
use PrestaShop\Module\Unipayment\Checkout\CheckoutSubmitLockRepository;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationCache;
use PrestaShop\Module\Unipayment\Order\FinancingSnapshotRepository;
use PrestaShop\Module\Unipayment\Order\OrderAttemptRepository;
use PrestaShop\Module\Unipayment\Order\OrderBankStatusRepository;
use PrestaShop\Module\Unipayment\Order\OrderStateInstaller;
use PrestaShop\Module\Unipayment\Product\PopupSubmissionRepository;
use PrestaShop\Module\Unipayment\Security\ApiNonceRepository;
use PrestaShop\Module\Unipayment\Security\TokenRepository;
use PrestaShop\Module\Unipayment\SmartUcf\Certificate\CertificateLocalStore;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDebugLogRepository;

/**
 * Deletes module-owned local data for true module uninstall (AUD-006).
 * Does not recreate schema/config and does not delete native PS or remote CP data.
 */
final class ModuleDataPurger
{
    public const CHECKOUT_LOCK_PREFIX = 'UNIPAYMENT_CHECKOUT_LOCK_';

    /** @var \Db */
    private $database;

    /** @var ControlPanelClient|null */
    private $controlPanelClient;

    /** @var CertificateLocalStore */
    private $certificateStore;

    /** @var OrderStateInstaller */
    private $orderStates;

    public function __construct(
        ?\Db $database = null,
        ?ControlPanelClient $controlPanelClient = null,
        ?CertificateLocalStore $certificateStore = null,
        ?OrderStateInstaller $orderStates = null
    ) {
        $this->database = $database ?? \Db::getInstance();
        $this->controlPanelClient = $controlPanelClient;
        $this->certificateStore = $certificateStore ?? new CertificateLocalStore();
        $this->orderStates = $orderStates ?? new OrderStateInstaller();
    }

    public function purge(): ModuleDataPurgeResult
    {
        $completed = [];
        $errors = [];

        $this->bestEffortRemoteLogout($completed);

        $this->runComponent('tokens', function () {
            return (new TokenRepository())->invalidate();
        }, $completed, $errors);

        $this->runComponent('configuration', function () {
            return $this->purgeConfigurationKeys();
        }, $completed, $errors);

        $this->runComponent('order_states', function () {
            return $this->orderStates->purge();
        }, $completed, $errors);

        foreach ($this->tableOwners() as $label => $owner) {
            $this->runComponent($label, function () use ($owner) {
                return (bool) $owner->uninstall();
            }, $completed, $errors);
        }

        $this->runComponent('certificates', function () {
            return $this->certificateStore->purgeRuntimeArtifacts(false);
        }, $completed, $errors);

        $success = $errors === [];
        \PrestaShopLogger::addLog(
            'UniPayment module uninstall data cleanup '
                . ($success ? 'succeeded' : 'failed')
                . '; completed=' . implode(',', $completed)
                . ($errors !== [] ? '; errors=' . implode(',', $errors) : ''),
            $success ? 1 : 3
        );

        return new ModuleDataPurgeResult($success, $completed, $errors);
    }

    /** @return array<string, object> */
    private function tableOwners(): array
    {
        return [
            'popup_submissions' => new PopupSubmissionRepository($this->database),
            'financing_snapshots' => new FinancingSnapshotRepository($this->database),
            'order_attempts' => new OrderAttemptRepository($this->database),
            'order_bank_status' => new OrderBankStatusRepository($this->database),
            'api_nonce' => new ApiNonceRepository($this->database),
            'checkout_lock' => new CheckoutSubmitLockRepository($this->database),
            'smartucf_debug_log' => new SmartUcfDebugLogRepository($this->database),
            'shop_cache' => new ShopConfigurationCache($this->database),
        ];
    }

    private function purgeConfigurationKeys(): bool
    {
        $ok = (new ConfigurationRepository())->uninstall();
        $ok = $this->deleteConfigurationByPrefix(self::CHECKOUT_LOCK_PREFIX) && $ok;

        return $ok;
    }

    private function deleteConfigurationByPrefix(string $prefix): bool
    {
        $sql = 'SELECT `name` FROM `' . _DB_PREFIX_ . "configuration` WHERE `name` LIKE '" . pSQL($prefix) . "%'";
        $rows = \call_user_func([$this->database, 'executeS'], $sql);
        if (!is_array($rows)) {
            return true;
        }
        $ok = true;
        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $ok = \Configuration::deleteByName($name) && $ok;
        }

        return $ok;
    }

    private function bestEffortRemoteLogout(array &$completed): void
    {
        if ($this->controlPanelClient === null) {
            return;
        }
        try {
            $this->controlPanelClient->logout();
            $completed[] = 'cp_logout';
        } catch (\Throwable $exception) {
            $completed[] = 'cp_logout_skipped';
        }
    }

    /**
     * @param callable():bool $action
     * @param list<string> $completed
     * @param list<string> $errors
     */
    private function runComponent(string $label, callable $action, array &$completed, array &$errors): void
    {
        try {
            if ($action()) {
                $completed[] = $label;
            } else {
                $errors[] = $label;
            }
        } catch (\Throwable $exception) {
            $errors[] = $label;
            \PrestaShopLogger::addLog(
                'UniPayment uninstall cleanup component failed: ' . $label . ' (' . get_class($exception) . ')',
                3
            );
        }
    }
}
