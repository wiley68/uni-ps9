<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Tests\Support;

use PrestaShop\Module\Unipayment\Api\ShopConfigurationProviderInterface;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationCacheInterface;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationService;
use PrestaShop\Module\Unipayment\Infrastructure\ImmediateMutationBoundary;
use PrestaShop\Module\Unipayment\Security\TokenRepository;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCredentialCipher;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCredentialPersistence;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCredentialRepository;

/**
 * Wire ShopConfigurationService with in-memory SmartUCF credential stack (no Db).
 */
final class ShopConfigurationCredentialWiring
{
    /**
     * @return array{0: ShopConfigurationService, 1: SmartUcfCredentialRepository, 2: InMemorySmartUcfCredentialSettingStore}
     */
    public static function service(
        ConfigurationRepository $configuration,
        ShopConfigurationCacheInterface $cache,
        ShopConfigurationProviderInterface $provider,
        TokenRepository $tokens,
        int $shopId = 1
    ): array {
        $settings = new InMemorySmartUcfCredentialSettingStore();
        $repo = new SmartUcfCredentialRepository($settings, new SmartUcfCredentialCipher(), $shopId);
        $boundary = new ImmediateMutationBoundary();
        $persistence = new SmartUcfCredentialPersistence($repo, $cache, $boundary);
        $service = new ShopConfigurationService(
            $configuration,
            $cache,
            $provider,
            $tokens,
            null,
            $repo,
            $persistence,
            $boundary
        );

        return [$service, $repo, $settings];
    }
}
