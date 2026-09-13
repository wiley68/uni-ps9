<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Tests\Support;

use PrestaShop\Module\Unipayment\Infrastructure\MutationBoundaryInterface;

/**
 * Simulates GET_LOCK + START TRANSACTION semantics over snapshottable in-memory stores.
 */
final class CredentialAtomicFakeBoundary implements MutationBoundaryInterface
{
    /** @var InMemorySmartUcfCredentialSettingStore */
    private $settings;

    /** @var TransactionalMemoryShopConfigurationCache */
    private $cache;

    /** @var int */
    public $openTransactions = 0;

    /** @var bool */
    public $failNextLock = false;

    /** @var bool */
    public $failNextStartTransaction = false;

    /** @var bool */
    public $failNextCommit = false;

    /** @var bool */
    public $failNextRollback = false;

    /** @var bool */
    public $failNextRelease = false;

    /** @var bool */
    public $throwOnRelease = false;

    /** @var array<string, true> */
    public $heldLocks = [];

    /** @var list<string> */
    public $lockAcquireOrder = [];

    /** @var list<string> */
    public $lockReleaseOrder = [];

    /** @var list<string> */
    public $callbackEventLog = [];

    /** @var (callable(string): void)|null */
    public $onBeforeCallback = null;

    /** @var bool */
    public $committedLastRun = false;

    public function __construct(
        InMemorySmartUcfCredentialSettingStore $settings,
        TransactionalMemoryShopConfigurationCache $cache
    ) {
        $this->settings = $settings;
        $this->cache = $cache;
    }

    public function runExclusive(string $lockName, callable $callback)
    {
        if ($this->failNextLock) {
            $this->failNextLock = false;
            throw new \RuntimeException(
                'Unable to acquire exclusive mutation lock for SmartUCF credential persistence.'
            );
        }

        $this->heldLocks[$lockName] = true;
        $this->lockAcquireOrder[] = $lockName;
        $this->committedLastRun = false;

        if ($this->failNextStartTransaction) {
            $this->failNextStartTransaction = false;
            unset($this->heldLocks[$lockName]);
            $this->lockReleaseOrder[] = $lockName;
            throw new \RuntimeException('Unable to start SmartUCF credential persistence transaction.');
        }

        $this->settings->beginWork();
        $this->cache->beginWork();
        ++$this->openTransactions;
        try {
            if ($this->onBeforeCallback !== null) {
                ($this->onBeforeCallback)($lockName);
            }
            $this->callbackEventLog[] = 'callback_enter:' . $lockName;
            $result = $callback();
            $this->callbackEventLog[] = 'callback_exit:' . $lockName;

            if ($this->failNextCommit) {
                $this->failNextCommit = false;
                throw new \RuntimeException('Unable to commit SmartUCF credential persistence transaction.');
            }

            $this->settings->commitWork();
            $this->cache->commitWork();
            $this->committedLastRun = true;
            --$this->openTransactions;
            $this->releaseLock($lockName);

            return $result;
        } catch (\Throwable $exception) {
            if ($this->failNextRollback) {
                $this->failNextRollback = false;
                $this->settings->rollbackWork();
                $this->cache->rollbackWork();
                --$this->openTransactions;
                $this->releaseLock($lockName);
                throw new \RuntimeException(
                    'SmartUCF credential persistence failed and database ROLLBACK also failed.',
                    0,
                    $exception
                );
            }
            $this->settings->rollbackWork();
            $this->cache->rollbackWork();
            --$this->openTransactions;
            $this->releaseLock($lockName);
            throw $exception;
        }
    }

    private function releaseLock(string $lockName): void
    {
        if ($this->throwOnRelease) {
            $this->throwOnRelease = false;
            unset($this->heldLocks[$lockName]);
            $this->lockReleaseOrder[] = $lockName;
            if (!$this->committedLastRun) {
                throw new \RuntimeException('SmartUCF credential advisory lock RELEASE_LOCK failed.');
            }

            return;
        }
        if ($this->failNextRelease) {
            $this->failNextRelease = false;
            unset($this->heldLocks[$lockName]);
            $this->lockReleaseOrder[] = $lockName;
            if (!$this->committedLastRun) {
                throw new \RuntimeException(
                    'SmartUCF credential advisory lock was not released successfully.'
                );
            }

            return;
        }
        unset($this->heldLocks[$lockName]);
        $this->lockReleaseOrder[] = $lockName;
    }
}
