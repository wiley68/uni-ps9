<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\SmartUcf\Certificate;

use PrestaShop\Module\Unipayment\Api\ControlPanelClient;
use PrestaShop\Module\Unipayment\Api\Exception\ConnectionException;
use PrestaShop\Module\Unipayment\Api\Exception\ControlPanelException;
use PrestaShop\Module\Unipayment\Api\Exception\HttpException;
use PrestaShop\Module\Unipayment\Api\Exception\TimeoutException;

/**
 * Ensures local SmartUCF client certificates match Control Panel before createSession.
 */
final class CertificateSynchronizer
{
    /** @var ControlPanelClient */
    private $client;
    /** @var CertificateLocalStore */
    private $store;
    /** @var CertificatePairValidator */
    private $validator;

    public function __construct(
        ControlPanelClient $client,
        ?CertificateLocalStore $store = null,
        ?CertificatePairValidator $validator = null
    ) {
        $this->client = $client;
        $this->validator = $validator ?? new CertificatePairValidator();
        $this->store = $store ?? new CertificateLocalStore(null, $this->validator);
    }

    /**
     * Synchronize if needed and return an immutable lease for one SmartUCF HTTP call.
     *
     * @throws CertificateSyncException
     */
    public function ensureCurrent(): CertificateConsumerLease
    {
        $this->store->ensureProtectionFiles();

        try {
            $metadata = $this->client->getSslCertificateMetadata();
        } catch (HttpException $exception) {
            if ($this->isExplicitUnavailable($exception)) {
                throw new CertificateSyncException(
                    'Control Panel reports no SSL certificate available.',
                    CertificateSyncException::REASON_CP_UNAVAILABLE
                );
            }

            return $this->failOpenOrThrow($exception);
        } catch (ConnectionException | TimeoutException $exception) {
            return $this->failOpenOrThrow($exception);
        } catch (ControlPanelException $exception) {
            return $this->failOpenOrThrow($exception);
        }

        if (empty($metadata['available'])) {
            throw new CertificateSyncException(
                'Control Panel SSL metadata is unavailable.',
                CertificateSyncException::REASON_CP_UNAVAILABLE
            );
        }

        $local = $this->store->validateLocalPair();
        if (
            $local !== null
            && hash_equals((string) $metadata['certificate_sha256'], $local['certificate_sha256'])
            && hash_equals((string) $metadata['private_key_sha256'], $local['private_key_sha256'])
        ) {
            \PrestaShopLogger::addLog(
                'UniPayment SSL cert sync: metadata match, using local pair'
                    . ' rev=' . substr((string) ($metadata['ssl_revision'] ?? ''), 0, 32)
                    . ' cert=' . substr((string) $metadata['certificate_sha256'], 0, 12),
                1
            );

            return $this->store->withSharedLock(function () {
                return $this->store->createConsumerPairLease();
            });
        }

        return $this->store->withExclusiveLock(function () use ($metadata) {
            // Recheck under lock — another request may have refreshed already.
            $local = $this->store->validateLocalPair();
            if (
                $local !== null
                && hash_equals((string) $metadata['certificate_sha256'], $local['certificate_sha256'])
                && hash_equals((string) $metadata['private_key_sha256'], $local['private_key_sha256'])
            ) {
                return $this->store->createConsumerPairLease();
            }

            try {
                $bundle = $this->client->downloadSslCertificateBundle();
            } catch (ControlPanelException $exception) {
                throw new CertificateSyncException(
                    'SSL certificate bundle download failed.',
                    CertificateSyncException::REASON_REFRESH_FAILED
                );
            }

            $this->assertBundleIntegrity($bundle, $metadata);
            try {
                $this->validator->validate(
                    (string) $bundle['certificate_pem'],
                    (string) $bundle['private_key_pem']
                );
            } catch (\Throwable $exception) {
                throw new CertificateSyncException(
                    'Downloaded SSL certificate bundle failed validation: ' . $exception->getMessage(),
                    CertificateSyncException::REASON_INVALID_BUNDLE
                );
            }

            $this->store->replacePair(
                (string) $bundle['certificate_pem'],
                (string) $bundle['private_key_pem'],
                [
                    'ssl_revision' => (string) ($bundle['ssl_revision'] ?? $metadata['ssl_revision'] ?? ''),
                    'certificate_sha256' => (string) $bundle['certificate_sha256'],
                    'private_key_sha256' => (string) $bundle['private_key_sha256'],
                ]
            );

            \PrestaShopLogger::addLog(
                'UniPayment SSL cert sync: refreshed from CP'
                    . ' rev=' . substr((string) ($bundle['ssl_revision'] ?? ''), 0, 32)
                    . ' cert=' . substr((string) $bundle['certificate_sha256'], 0, 12),
                1
            );

            return $this->store->createConsumerPairLease();
        });
    }

    private function failOpenOrThrow(\Throwable $exception): CertificateConsumerLease
    {
        $local = $this->store->validateLocalPair();
        if ($local !== null) {
            \PrestaShopLogger::addLog(
                'UniPayment SSL cert sync: CP metadata unavailable, fail-open with valid local pair'
                    . ' (' . get_class($exception) . ')',
                2
            );

            return $this->store->withSharedLock(function () {
                return $this->store->createConsumerPairLease();
            });
        }

        throw new CertificateSyncException(
            'Control Panel SSL metadata unavailable and local certificate pair is not usable.',
            CertificateSyncException::REASON_CP_TRANSPORT
        );
    }

    private function isExplicitUnavailable(HttpException $exception): bool
    {
        if ($exception->getStatusCode() !== 404) {
            return false;
        }
        $response = $exception->getResponse();

        return (($response['error'] ?? '') === 'ssl_certificate_unavailable')
            || (isset($response['data']['available']) && $response['data']['available'] === false);
    }

    /**
     * @param array<string, mixed> $bundle
     * @param array<string, mixed> $metadata
     */
    private function assertBundleIntegrity(array $bundle, array $metadata): void
    {
        foreach (['certificate_pem', 'private_key_pem', 'certificate_sha256', 'private_key_sha256'] as $key) {
            if (!isset($bundle[$key]) || !is_string($bundle[$key]) || $bundle[$key] === '') {
                throw new CertificateSyncException(
                    'SSL certificate bundle is missing required fields.',
                    CertificateSyncException::REASON_INVALID_BUNDLE
                );
            }
        }

        $certHash = strtolower((string) $bundle['certificate_sha256']);
        $keyHash = strtolower((string) $bundle['private_key_sha256']);
        if (!$this->validator->isSha256Hex($certHash) || !$this->validator->isSha256Hex($keyHash)) {
            throw new CertificateSyncException(
                'SSL certificate bundle hashes are malformed.',
                CertificateSyncException::REASON_INVALID_BUNDLE
            );
        }

        $computedCert = $this->validator->sha256((string) $bundle['certificate_pem']);
        $computedKey = $this->validator->sha256((string) $bundle['private_key_pem']);
        if (!hash_equals($certHash, $computedCert) || !hash_equals($keyHash, $computedKey)) {
            throw new CertificateSyncException(
                'Downloaded SSL PEM digests do not match declared hashes.',
                CertificateSyncException::REASON_INVALID_BUNDLE
            );
        }

        // Prefer consistency with the metadata that triggered refresh when both present.
        if (
            isset($metadata['certificate_sha256'], $metadata['private_key_sha256'])
            && $this->validator->isSha256Hex((string) $metadata['certificate_sha256'])
            && (
                !hash_equals((string) $metadata['certificate_sha256'], $certHash)
                || !hash_equals((string) $metadata['private_key_sha256'], $keyHash)
            )
        ) {
            throw new CertificateSyncException(
                'Downloaded SSL bundle does not match metadata that triggered refresh.',
                CertificateSyncException::REASON_INVALID_BUNDLE
            );
        }
    }
}
