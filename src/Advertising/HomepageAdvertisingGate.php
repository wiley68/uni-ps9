<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Advertising;

use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationFlags;

/**
 * Woo mtuc_get_reklama_context() permission split:
 * local settings (assets) vs CP shop flags (markup).
 */
final class HomepageAdvertisingGate
{
    public function allowsPage(string $phpSelf): bool
    {
        return $phpSelf === 'index';
    }

    public function allowsLocalSettings(
        bool $moduleActive,
        bool $moduleEnabled,
        bool $advertisingEnabled,
        string $unicid
    ): bool {
        return $moduleActive
            && $moduleEnabled
            && $advertisingEnabled
            && trim($unicid) !== '';
    }

    /**
     * Cheap homepage check used before shop-cache lookup / asset enqueue.
     */
    public function allowsAssets(
        string $phpSelf,
        bool $moduleActive,
        bool $moduleEnabled,
        bool $advertisingEnabled,
        string $unicid
    ): bool {
        return $this->allowsPage($phpSelf)
            && $this->allowsLocalSettings($moduleActive, $moduleEnabled, $advertisingEnabled, $unicid);
    }

    /**
     * CP shop flags required to render the floating button (Woo uni_status + uni_container_status).
     *
     * @param array<string, mixed> $shop
     */
    public function allowsShop(array $shop): bool
    {
        return ShopConfigurationFlags::isYesFlag($shop['uni_status'] ?? 0)
            && ShopConfigurationFlags::isYesFlag($shop['uni_container_status'] ?? 0);
    }
}
