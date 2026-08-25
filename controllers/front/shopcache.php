<?php

declare(strict_types=1);

use PrestaShop\Module\Unipayment\Api\ControlPanelClient;
use PrestaShop\Module\Unipayment\Api\CurlHttpTransport;
use PrestaShop\Module\Unipayment\Api\Exception\ModuleApiException;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Configuration\Exception\ShopConfigurationSnapshotValidationException;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationCache;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationService;
use PrestaShop\Module\Unipayment\Controller\ModuleApiController;
use PrestaShop\Module\Unipayment\Security\TokenRepository;

final class UnipaymentShopcacheModuleFrontController extends ModuleApiController
{
    protected function handleAuthenticatedRequest(array $payload, string $unicid): array
    {
        $data = $payload['data'] ?? null;
        if (!is_array($data) || $data === []) {
            throw new ModuleApiException('Полето data трябва да съдържа пълна конфигурация на магазина.', 400);
        }

        if (isset($data['unicid']) && (!is_string($data['unicid']) || !hash_equals($unicid, $data['unicid']))) {
            throw new ModuleApiException('UNICID в конфигурацията не съвпада с този на магазина.', 400);
        }

        $service = $this->createShopConfigurationService();
        try {
            if (!$service->replaceSnapshot($unicid, $data)) {
                throw new ModuleApiException('Кешът на shop данни не можа да бъде заменен.', 500);
            }
        } catch (ShopConfigurationSnapshotValidationException $exception) {
            throw new ModuleApiException(
                'Конфигурацията на магазина е невалидна.',
                422,
                $exception->errorCode(),
                $exception->responseData()
            );
        }

        return [
            'success' => true,
            'message' => 'Кешът на shop данни е обновен успешно.',
            'data' => $service->getMetadata(),
        ];
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
