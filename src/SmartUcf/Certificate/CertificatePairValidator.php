<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\SmartUcf\Certificate;

/**
 * Validates SmartUCF client certificate + private key PEM pairs (OpenSSL).
 */
final class CertificatePairValidator
{
    public const PASSPHRASE = '1234';

    /**
     * @return array{
     *     certificate_pem: string,
     *     private_key_pem: string,
     *     not_before: string,
     *     not_after: string,
     *     not_before_timestamp: int,
     *     not_after_timestamp: int
     * }
     */
    public function validate(string $certificatePem, string $privateKeyPem): array
    {
        $originalCertificatePem = $certificatePem;
        $originalPrivateKeyPem = $privateKeyPem;
        $certificatePem = $this->normalizePem($certificatePem);
        $privateKeyPem = $this->normalizePem($privateKeyPem);

        if ($certificatePem === '') {
            throw new \InvalidArgumentException('The certificate PEM is empty.');
        }
        if ($privateKeyPem === '') {
            throw new \InvalidArgumentException('The private key PEM is empty.');
        }

        $certificate = $this->parseCertificate($certificatePem);
        $privateKey = $this->parsePrivateKey($privateKeyPem);
        $parsed = $this->parseCertificateMetadata($certificate);
        $this->assertKeyMatchesCertificate($certificate, $privateKey);
        $this->assertValidityWindow($parsed['not_before_timestamp'], $parsed['not_after_timestamp']);

        return [
            // Preserve exact CP/local bytes so SHA-256 digests remain stable.
            'certificate_pem' => $originalCertificatePem,
            'private_key_pem' => $originalPrivateKeyPem,
            'not_before' => $parsed['not_before'],
            'not_after' => $parsed['not_after'],
            'not_before_timestamp' => $parsed['not_before_timestamp'],
            'not_after_timestamp' => $parsed['not_after_timestamp'],
        ];
    }

    public function isValidPair(string $certificatePem, string $privateKeyPem): bool
    {
        try {
            $this->validate($certificatePem, $privateKeyPem);

            return true;
        } catch (\Throwable $exception) {
            return false;
        }
    }

    public function sha256(string $pemBytes): string
    {
        return hash('sha256', $pemBytes);
    }

    public function isSha256Hex(string $value): bool
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/', $value);
    }

    /** @return resource|\OpenSSLCertificate */
    private function parseCertificate(string $certificatePem)
    {
        if (
            strpos($certificatePem, '-----BEGIN CERTIFICATE-----') === false
            || strpos($certificatePem, '-----END CERTIFICATE-----') === false
        ) {
            throw new \InvalidArgumentException('The certificate is not a valid PEM.');
        }

        $certificate = @openssl_x509_read($certificatePem);
        if ($certificate === false) {
            throw new \InvalidArgumentException('The certificate could not be parsed as X.509.');
        }

        return $certificate;
    }

    /** @return resource|\OpenSSLAsymmetricKey */
    private function parsePrivateKey(string $privateKeyPem)
    {
        if (
            strpos($privateKeyPem, '-----BEGIN') === false
            || strpos($privateKeyPem, '-----END') === false
            || strpos($privateKeyPem, 'PRIVATE KEY-----') === false
        ) {
            throw new \InvalidArgumentException('The private key is not a valid PEM.');
        }

        foreach ([self::PASSPHRASE, ''] as $passphrase) {
            $key = @openssl_pkey_get_private($privateKeyPem, $passphrase);
            if ($key !== false) {
                return $key;
            }
        }

        throw new \InvalidArgumentException('The private key could not be parsed.');
    }

    /**
     * @param resource|\OpenSSLCertificate $certificate
     * @return array{not_before: string, not_after: string, not_before_timestamp: int, not_after_timestamp: int}
     */
    private function parseCertificateMetadata($certificate): array
    {
        $parsed = openssl_x509_parse($certificate, false);
        if (
            $parsed === false
            || !isset($parsed['validFrom_time_t'], $parsed['validTo_time_t'])
        ) {
            throw new \InvalidArgumentException('Certificate validity dates could not be read.');
        }

        $notBefore = (int) $parsed['validFrom_time_t'];
        $notAfter = (int) $parsed['validTo_time_t'];

        return [
            'not_before' => gmdate('c', $notBefore),
            'not_after' => gmdate('c', $notAfter),
            'not_before_timestamp' => $notBefore,
            'not_after_timestamp' => $notAfter,
        ];
    }

    /**
     * @param resource|\OpenSSLCertificate $certificate
     * @param resource|\OpenSSLAsymmetricKey $privateKey
     */
    private function assertKeyMatchesCertificate($certificate, $privateKey): void
    {
        $publicFromCert = openssl_pkey_get_public($certificate);
        if ($publicFromCert === false) {
            throw new \InvalidArgumentException('The certificate public key could not be extracted.');
        }

        $certDetails = openssl_pkey_get_details($publicFromCert);
        $keyDetails = openssl_pkey_get_details($privateKey);
        if ($certDetails === false || $keyDetails === false) {
            throw new \InvalidArgumentException('Certificate/key details could not be compared.');
        }

        $certPublic = $certDetails['key'] ?? null;
        $keyPublic = $keyDetails['key'] ?? null;
        if (!is_string($certPublic) || !is_string($keyPublic) || $certPublic === '' || $keyPublic === '') {
            throw new \InvalidArgumentException('Certificate/key details could not be compared.');
        }
        if ($certPublic !== $keyPublic) {
            throw new \InvalidArgumentException('The private key does not match the certificate.');
        }
    }

    private function assertValidityWindow(int $notBefore, int $notAfter): void
    {
        $now = time();
        if ($notBefore > $now) {
            throw new \InvalidArgumentException('The certificate is not yet valid.');
        }
        if ($notAfter < $now) {
            throw new \InvalidArgumentException('The certificate has expired.');
        }
    }

    private function normalizePem(string $pem): string
    {
        return str_replace("\r\n", "\n", trim($pem));
    }
}
