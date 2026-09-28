<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Configuration;

/** Coordinates remote refresh only; no transaction is held across the network call. */
final class DbShopConfigurationRefreshCoordinator implements ShopConfigurationRefreshCoordinatorInterface
{
    /** @var \Db|object */
    private $database;

    /** @param \Db|object|null $database */
    public function __construct($database = null)
    {
        $this->database = $database ?? \Db::getInstance();
    }

    public function acquire(string $unicid, int $waitSeconds): ?ShopConfigurationRefreshLeaseInterface
    {
        $name = 'unipay_shop_refresh_' . substr(hash('sha256', trim($unicid)), 0, 32);
        $escaped = pSQL($name);
        $acquired = $this->database->getValue(sprintf("SELECT GET_LOCK('%s', %d)", $escaped, max(0, $waitSeconds)));
        if ((int) $acquired !== 1) {
            return null;
        }

        return new DbShopConfigurationRefreshLease($this->database, $escaped);
    }
}

final class DbShopConfigurationRefreshLease implements ShopConfigurationRefreshLeaseInterface
{
    /** @var \Db|object */
    private $database;
    /** @var string */
    private $escapedLockName;
    /** @var bool */
    private $released = false;

    /** @param \Db|object $database */
    public function __construct($database, string $escapedLockName)
    {
        $this->database = $database;
        $this->escapedLockName = $escapedLockName;
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;
        $this->database->getValue("SELECT RELEASE_LOCK('" . $this->escapedLockName . "')");
    }

    public function __destruct()
    {
        try {
            $this->release();
        } catch (\Throwable $exception) {
            // The owning connection also releases MySQL advisory locks on termination.
        }
    }
}
