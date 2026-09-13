<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Configuration;

use PrestaShop\Module\Unipayment\Api\Exception\AuthenticationException;
use PrestaShop\Module\Unipayment\Api\Exception\HttpException;
use PrestaShop\Module\Unipayment\Api\Exception\InvalidPayloadException;
use PrestaShop\Module\Unipayment\Api\ShopConfigurationProviderInterface;
use PrestaShop\Module\Unipayment\Configuration\Exception\ShopConfigurationSnapshotValidationException;
use PrestaShop\Module\Unipayment\Infrastructure\DbMutationBoundary;
use PrestaShop\Module\Unipayment\Infrastructure\MutationBoundaryInterface;
use PrestaShop\Module\Unipayment\Security\TokenRepository;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCredentialPairClassifier;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCredentialPersistence;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCredentialRepository;

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

    /** @var SmartUcfCredentialRepository */
    private $smartUcfCredentials;

    /** @var SmartUcfCredentialPersistence */
    private $credentialPersistence;

    /** @var MutationBoundaryInterface */
    private $mutationBoundary;

    public function __construct(
        ConfigurationRepository $configuration,
        ShopConfigurationCacheInterface $cache,
        ShopConfigurationProviderInterface $provider,
        TokenRepository $tokens,
        ?ShopConfigurationSnapshotValidator $snapshotValidator = null,
        ?SmartUcfCredentialRepository $smartUcfCredentials = null,
        ?SmartUcfCredentialPersistence $credentialPersistence = null,
        ?MutationBoundaryInterface $mutationBoundary = null
    ) {
        $this->configuration = $configuration;
        $this->cache = $cache;
        $this->provider = $provider;
        $this->tokens = $tokens;
        $this->snapshotValidator = $snapshotValidator ?? new ShopConfigurationSnapshotValidator();
        $this->smartUcfCredentials = $smartUcfCredentials ?? new SmartUcfCredentialRepository();
        $this->mutationBoundary = $mutationBoundary ?? new DbMutationBoundary();
        $this->credentialPersistence = $credentialPersistence ?? new SmartUcfCredentialPersistence(
            $this->smartUcfCredentials,
            $cache,
            $this->mutationBoundary
        );
    }

    /** @return array<string, mixed> */
    public function get(bool $forceRefresh = false): array
    {
        $unicid = $this->configuration->getUnicid();
        if ($unicid === '') {
            $this->purgePermanentFailure($unicid);
            throw new AuthenticationException('UNICID is required to load the shop configuration.');
        }

        if ($forceRefresh) {
            $this->refresh($unicid);
        }

        $hydrated = $this->loadCoherentRuntimeSnapshot($unicid);
        if ($hydrated !== null) {
            return $hydrated;
        }

        if (!$forceRefresh) {
            $this->refresh($unicid);
            $hydrated = $this->loadCoherentRuntimeSnapshot($unicid);
            if ($hydrated !== null) {
                return $hydrated;
            }
        }

        throw new InvalidPayloadException('The shop configuration cache could not be loaded.');
    }

    /**
     * Cache-only shop snapshot for FO advertising render paths (AUD-022).
     *
     * Never calls refresh(), the remote provider, login, or token refresh.
     * Never hydrates SmartUCF credentials (FO advertising must stay credential-free).
     * Missing/stale/malformed cache → null (fail closed).
     *
     * @return array<string, mixed>|null
     */
    public function getCachedOnly(): ?array
    {
        $unicid = $this->configuration->getUnicid();
        if ($unicid === '') {
            return null;
        }

        try {
            $cached = $this->cache->getFresh($unicid);
            if ($cached === null) {
                return null;
            }

            // FO advertising must never see runtime credentials.
            return SmartUcfCredentialPairClassifier::stripFromSnapshot($cached);
        } catch (\Throwable $exception) {
            return null;
        }
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

        $shopData = ShopSnapshotSanitizer::sanitize($shopData);
        $this->snapshotValidator->validate($shopData, trim($unicid));
        $this->credentialPersistence->persistValidatedSnapshot(trim($unicid), $shopData);

        return true;
    }

    public function smartUcfCredentials(): SmartUcfCredentialRepository
    {
        return $this->smartUcfCredentials;
    }

    /** @return array<string, mixed>|null */
    public function getMetadata(): ?array
    {
        return $this->cache->getMetadata($this->configuration->getUnicid());
    }

    /**
     * Cache + exact credential pair under the same writer lock scope (no version skew).
     *
     * @return array<string, mixed>|null
     */
    public function loadCoherentRuntimeSnapshot(string $unicid): ?array
    {
        $unicid = trim($unicid);
        if ($unicid === '') {
            return null;
        }

        $lockName = DbMutationBoundary::smartUcfCredentialLockName(
            $this->smartUcfCredentials->shopId(),
            $unicid
        );

        return $this->mutationBoundary->runExclusive($lockName, function () use ($unicid) {
            $cached = $this->cache->getFresh($unicid);
            if ($cached === null) {
                return null;
            }

            return $this->smartUcfCredentials->hydrateShopSnapshot($cached);
        });
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

            $shopData = ShopSnapshotSanitizer::sanitize($shopData);

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

            return $this->credentialPersistence->persistValidatedSnapshot($unicid, $shopData);
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
