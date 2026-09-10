<?php

declare(strict_types=1);

use PrestaShop\Module\Unipayment\Api\ControlPanelClient;
use PrestaShop\Module\Unipayment\Api\CurlHttpTransport;
use PrestaShop\Module\Unipayment\Api\Exception\ModuleApiException;
use PrestaShop\Module\Unipayment\Api\ModuleApiError;
use PrestaShop\Module\Unipayment\Api\ModuleApiOperation;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Configuration\Exception\ShopConfigurationSnapshotValidationException;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationCache;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationService;
use PrestaShop\Module\Unipayment\Controller\ModuleApiController;
use PrestaShop\Module\Unipayment\Security\TokenRepository;

final class UnipaymentShopcacheModuleFrontController extends ModuleApiController
{
    protected function expectedOperation(): string
    {
        return ModuleApiOperation::SHOP_CACHE;
    }

    protected function handleAuthenticatedRequest(array $payload, string $unicid): array
    {
        $data = $payload['data'] ?? null;
        if (!is_array($data) || $data === [] || !$this->isJsonObject($data)) {
            throw new ModuleApiException(
                'The data field must contain a complete shop configuration.',
                400,
                ModuleApiError::INVALID_PAYLOAD
            );
        }

        if (isset($data['unicid']) && (!is_string($data['unicid']) || !hash_equals($unicid, $data['unicid']))) {
            throw new ModuleApiException(
                'The configuration UNICID does not match the authenticated shop.',
                400,
                ModuleApiError::INVALID_PAYLOAD
            );
        }

        $service = $this->createShopConfigurationService();
        try {
            if (!$service->replaceSnapshot($unicid, $data)) {
                throw new ModuleApiException(
                    'The shop configuration cache could not be replaced.',
                    500,
                    ModuleApiError::INTERNAL_ERROR
                );
            }
        } catch (ShopConfigurationSnapshotValidationException $exception) {
            throw new ModuleApiException(
                'The shop configuration snapshot is invalid.',
                422,
                ModuleApiError::SHOP_SNAPSHOT_INVALID,
                $exception->responseData()
            );
        }

        return [
            'success' => true,
            'message' => 'The shop configuration cache was updated successfully.',
            'data' => $service->getMetadata(),
        ];
    }

    /** @param array<mixed> $value */
    private function isJsonObject(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }

    private function createShopConfigurationService(): ShopConfigurationService
    {
        $configuration = new ConfigurationRepository();
        $tokens = new TokenRepository();
        $shopUrl = rtrim(\Tools::getShopDomainSsl(true) . __PS_BASE_URI__, '/');
        $client = new ControlPanelClient(
            $configuration,
            $tokens,
            new CurlHttpTransport(),
            $shopUrl
        );

        return new ShopConfigurationService(
            $configuration,
            new ShopConfigurationCache(),
            $client,
            $tokens
        );
    }
}
