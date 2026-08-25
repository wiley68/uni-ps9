<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Configuration;

use PrestaShop\Module\Unipayment\Api\Exception\AuthenticationException;
use PrestaShop\Module\Unipayment\Api\Exception\HttpException;
use PrestaShop\Module\Unipayment\Api\Exception\InvalidPayloadException;
use PrestaShop\Module\Unipayment\Api\ShopConfigurationProviderInterface;
use PrestaShop\Module\Unipayment\Configuration\Exception\ShopConfigurationSnapshotValidationException;
use PrestaShop\Module\Unipayment\Security\TokenRepository;

final class ShopConfigurationService
{
    /** @var ConfigurationRepository */
    private $configuration;

    /** @var ShopConfigurationCacheInterface */
    private $cache;

    /** @var ShopConfigurationProviderInterface */
    private $provider;

    /** @var TokenRepository */
    private $tokens;

    /** @var ShopConfigurationSnapshotValidator */
    private $snapshotValidator;

    public function __construct(
        ConfigurationRepository $configuration,
        ShopConfigurationCacheInterface $cache,
        ShopConfigurationProviderInterface $provider,
        TokenRepository $tokens,
        ?ShopConfigurationSnapshotValidator $snapshotValidator = null
    ) {
        $this->configuration = $configuration;
        $this->cache = $cache;
        $this->provider = $provider;
        $this->tokens = $tokens;
        $this->snapshotValidator = $snapshotValidator ?? new ShopConfigurationSnapshotValidator();
    }

    /** @return array<string, mixed> */
    public function get(bool $forceRefresh = false): array
    {
        $unicid = $this->configuration->getUnicid();
        if ($unicid === '') {
            $this->purgePermanentFailure($unicid);
            throw new AuthenticationException('UNICID is required to load the shop configuration.');
        }

        if (!$forceRefresh) {
            $cached = $this->cache->getFresh($unicid);
            if ($cached !== null) {
                return $cached;
            }
        }

        return $this->refresh($unicid);
    }

    /**
     * Full snapshot replacement entry point for the CP push handler.
     *
     * @param array<string, mixed> $shopData
     */
    public function replaceSnapshot(string $unicid, array $shopData): bool
    {
        if (trim($unicid) === '' || $shopData === []) {
            throw new InvalidPayloadException('The pushed shop configuration snapshot is invalid.');
        }

        $this->snapshotValidator->validate($shopData, trim($unicid));

        return $this->cache->replace(trim($unicid), $shopData);
    }

    /** @return array<string, mixed>|null */
    public function getMetadata(): ?array
    {
        return $this->cache->getMetadata($this->configuration->getUnicid());
    }

    /** @return array<string, mixed> */
    private function refresh(string $unicid): array
    {
        try {
            $response = $this->provider->getShop();
            $shopData = $response['data'] ?? null;
            if (!is_array($shopData) || $shopData === []) {
                throw new InvalidPayloadException('The Control Panel returned no usable shop configuration.');
            }

            try {
                $this->snapshotValidator->validate($shopData, $unicid);
            } catch (ShopConfigurationSnapshotValidationException $exception) {
                \PrestaShopLogger::addLog(
                    'UniPayment shop snapshot validation failed on pull: '
                        . $this->summarizeViolations($exception),
                    3
                );
                throw $exception;
            }

            if (!$this->cache->replace($unicid, $shopData)) {
                throw new InvalidPayloadException('The shop configuration cache could not be stored.');
            }

            return $shopData;
        } catch (ShopConfigurationSnapshotValidationException $exception) {
            // Keep known-good cache. Do not purge tokens.
            throw $exception;
        } catch (AuthenticationException $exception) {
            $this->purgePermanentFailure($unicid);
            throw $exception;
        } catch (HttpException $exception) {
            if (in_array($exception->getStatusCode(), [400, 401, 403, 404], true)) {
                $this->purgePermanentFailure($unicid);
            }

            throw $exception;
        } catch (InvalidPayloadException $exception) {
            $this->purgePermanentFailure($unicid);
            throw $exception;
        }
    }

    private function purgePermanentFailure(string $unicid): void
    {
        if ($unicid !== '') {
            $this->cache->delete($unicid);
        } else {
            $this->cache->clear();
        }
        $this->tokens->invalidate();
    }

    private function summarizeViolations(ShopConfigurationSnapshotValidationException $exception): string
    {
        $parts = [];
        foreach (array_slice($exception->violations(), 0, 10) as $violation) {
            $parts[] = ($violation['path'] !== '' ? $violation['path'] : '(root)') . ':' . $violation['code'];
        }

        return implode(', ', $parts);
    }
}
