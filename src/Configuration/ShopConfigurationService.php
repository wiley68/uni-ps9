<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Configuration;

use PrestaShop\Module\Unipayment\Api\Exception\AuthenticationException;
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

    /** @var ShopConfigurationRefreshCoordinatorInterface */
    private $refreshCoordinator;

    /** @var ShopConfigurationFailureClassifier */
    private $failureClassifier;

    /** @var callable */
    private $clock;

    public function __construct(
        ConfigurationRepository $configuration,
        ShopConfigurationCacheInterface $cache,
        ShopConfigurationProviderInterface $provider,
        TokenRepository $tokens,
        ?ShopConfigurationSnapshotValidator $snapshotValidator = null,
        ?SmartUcfCredentialRepository $smartUcfCredentials = null,
        ?SmartUcfCredentialPersistence $credentialPersistence = null,
        ?MutationBoundaryInterface $mutationBoundary = null,
        ?ShopConfigurationRefreshCoordinatorInterface $refreshCoordinator = null,
        ?ShopConfigurationFailureClassifier $failureClassifier = null,
        ?callable $clock = null
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
        $this->refreshCoordinator = $refreshCoordinator ?? (class_exists('\\Db')
            ? new DbShopConfigurationRefreshCoordinator()
            : new ImmediateShopConfigurationRefreshCoordinator());
        $this->failureClassifier = $failureClassifier ?? new ShopConfigurationFailureClassifier();
        $this->clock = $clock ?? 'time';
    }

    /** @return array<string, mixed> */
    public function get(bool $forceRefresh = false): array
    {
        return $this->resolve(false, $forceRefresh);
    }

    /** @return array<string, mixed> */
    public function getForSubmission(): array
    {
        return $this->resolve(true, false);
    }

    /**
     * Presentation resolver view which never exposes runtime SmartUCF credentials.
     *
     * @return array<string, mixed>
     */
    public function getForPresentationWithoutCredentials(): array
    {
        return SmartUcfCredentialPairClassifier::stripFromSnapshot($this->resolve(false, false));
    }

    /** @return array<string, mixed> */
    private function resolve(bool $submission, bool $forceRefresh): array
    {
        $unicid = $this->configuration->getUnicid();
        if ($unicid === '') {
            $this->purgePermanentFailure($unicid);
            throw new AuthenticationException('UNICID is required to load the shop configuration.');
        }

        if (!$forceRefresh) {
            $fresh = $this->loadCoherentRuntimeSnapshot($unicid);
            if ($fresh !== null) {
                return $fresh;
            }
        }

        $lkg = (!$submission && !$forceRefresh) ? $this->loadEligibleLkg($unicid) : null;
        $lease = $this->refreshCoordinator->acquire($unicid, $lkg !== null ? 0 : 3);
        if ($lease === null) {
            if ($lkg !== null) {
                return $lkg;
            }
            throw new \RuntimeException('Shop configuration refresh is already in progress.');
        }
        try {
            if (!$forceRefresh) {
                $fresh = $this->loadCoherentRuntimeSnapshot($unicid);
                if ($fresh !== null) {
                    return $fresh;
                }
            }
            try {
                $this->refresh($unicid);
            } catch (\Throwable $exception) {
                if ($lkg !== null
                    && $this->failureClassifier->classify($exception) === ShopConfigurationFailureClassifier::TRANSIENT
                ) {
                    return $lkg;
                }
                throw $exception;
            }
            $fresh = $this->loadCoherentRuntimeSnapshot($unicid);
            if ($fresh !== null) {
                return $fresh;
            }
        } finally {
            $lease->release();
        }

        throw new InvalidPayloadException('The shop configuration cache could not be loaded.');
    }

    /** @return array<string,mixed>|null */
    private function loadEligibleLkg(string $unicid): ?array
    {
        if (!$this->cache instanceof StaleShopConfigurationCacheInterface) {
            return null;
        }
        $retained = $this->cache->getRetained($unicid);
        $now = (int) call_user_func($this->clock);
        if ($retained === null || $retained['expires_at_timestamp'] > $now
            || $now > $retained['expires_at_timestamp'] + ShopConfigurationCache::LKG_SECONDS
        ) {
            return null;
        }

        $hydrated = $this->smartUcfCredentials->hydrateShopSnapshot($retained['data']);
        try {
            $this->snapshotValidator->validate($hydrated, $unicid);
        } catch (\Throwable $exception) {
            return null;
        }

        return $hydrated;
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
            // Class C: preserve the byte-identical known-good row and token.
            throw $exception;
        } catch (\Throwable $exception) {
            if ($this->failureClassifier->classify($exception)
                === ShopConfigurationFailureClassifier::AUTHORITATIVE_NEGATIVE
            ) {
                $this->purgePermanentFailure($unicid);
            }

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
