<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\SmartUcf;

use PrestaShop\Module\Unipayment\Api\Exception\InvalidPayloadException;
use PrestaShop\Module\Unipayment\Configuration\Exception\ShopConfigurationSnapshotValidationException;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationCacheInterface;
use PrestaShop\Module\Unipayment\Configuration\ShopSnapshotSanitizer;
use PrestaShop\Module\Unipayment\Infrastructure\DbMutationBoundary;
use PrestaShop\Module\Unipayment\Infrastructure\MutationBoundaryInterface;

/**
 * Shared GET/push persistence: classify SmartUCF pair, encrypt, strip cache.
 *
 * Complete pair + sanitized cache mutate under shop/UNICID named lock + DB transaction.
 */
final class SmartUcfCredentialPersistence
{
    public const ERROR_INCOMPLETE_PAIR = 'incomplete_credential_pair';
    public const ERROR_REQUIRED = 'required';

    /** @var SmartUcfCredentialRepository */
    private $credentials;

    /** @var ShopConfigurationCacheInterface */
    private $cache;

    /** @var MutationBoundaryInterface */
    private $boundary;

    public function __construct(
        SmartUcfCredentialRepository $credentials,
        ShopConfigurationCacheInterface $cache,
        ?MutationBoundaryInterface $boundary = null
    ) {
        $this->credentials = $credentials;
        $this->cache = $cache;
        $this->boundary = $boundary ?? new DbMutationBoundary();
    }

    /**
     * Validate pair contract, optionally rotate encrypted credentials, persist credential-free cache.
     *
     * @param array<string, mixed> $shopData full ingress snapshot (may include uni_user/uni_password)
     *
     * @return array<string, mixed> sanitized shop_data written to general cache (no credentials)
     */
    public function persistValidatedSnapshot(string $unicid, array $shopData): array
    {
        $unicid = trim($unicid);
        if ($unicid === '' || $shopData === []) {
            throw new InvalidPayloadException('The shop configuration snapshot is empty or has no UNICID.');
        }

        // Strip unknown secret-like keys while retaining known credential keys for classification.
        $shopData = ShopSnapshotSanitizer::sanitize($shopData);

        $state = SmartUcfCredentialPairClassifier::classify($shopData);
        $process2 = ((int) ($shopData['uni_proces'] ?? 0)) === 1;

        if ($state === SmartUcfCredentialPairClassifier::INVALID) {
            throw new ShopConfigurationSnapshotValidationException([
                ['path' => 'uni_user', 'code' => self::ERROR_INCOMPLETE_PAIR],
                ['path' => 'uni_password', 'code' => self::ERROR_INCOMPLETE_PAIR],
            ]);
        }

        if (!$process2 && $state !== SmartUcfCredentialPairClassifier::COMPLETE) {
            throw new ShopConfigurationSnapshotValidationException([
                ['path' => 'uni_user', 'code' => self::ERROR_REQUIRED],
                ['path' => 'uni_password', 'code' => self::ERROR_REQUIRED],
            ]);
        }

        $sanitized = SmartUcfCredentialPairClassifier::stripFromSnapshot($shopData);
        $rotatePair = $state === SmartUcfCredentialPairClassifier::COMPLETE;

        $encryptedUser = null;
        $encryptedPassword = null;
        if ($rotatePair) {
            // Encrypt outside the transaction so crypto work does not hold the lock longer than needed.
            $encryptedUser = $this->credentials->encryptPlain(trim((string) $shopData['uni_user']));
            $encryptedPassword = $this->credentials->encryptPlain(trim((string) $shopData['uni_password']));
        }

        $lockName = DbMutationBoundary::smartUcfCredentialLockName($this->credentials->shopId(), $unicid);

        return $this->boundary->runExclusive($lockName, function () use (
            $unicid,
            $sanitized,
            $rotatePair,
            $encryptedUser,
            $encryptedPassword
        ) {
            if ($rotatePair) {
                $this->credentials->replaceEncryptedPair(
                    (string) $encryptedUser,
                    (string) $encryptedPassword
                );
            }
            if (!$this->cache->replace($unicid, $sanitized)) {
                throw new \RuntimeException('The shop configuration cache could not be stored.');
            }

            return $sanitized;
        });
    }
}
