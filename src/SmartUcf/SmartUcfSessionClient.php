<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\SmartUcf;

use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationFlags;
use PrestaShop\Module\Unipayment\SmartUcf\Certificate\CertificateConsumerLease;
use PrestaShop\Module\Unipayment\SmartUcf\Certificate\CertificatePairValidator;

/**
 * HTTP client for SmartUCF sucfOnlineSessionStart.
 * Outbound destinations are gated by SmartUcfEndpointPolicy (AUD-003).
 */
final class SmartUcfSessionClient implements SmartUcfSessionGatewayInterface
{
    /** HTTP timeout in seconds (used by AUD-008 stale grace calibration). */
    public const HTTP_TIMEOUT_SECONDS = 10;

    /** @var SmartUcfPayloadBuilder */
    private $payloadBuilder;

    /** @var string */
    private $keysDir;

    /** @var SmartUcfEndpointPolicy */
    private $endpointPolicy;

    public function __construct(
        SmartUcfPayloadBuilder $payloadBuilder,
        ?string $keysDir = null,
        ?SmartUcfEndpointPolicy $endpointPolicy = null
    ) {
        $this->payloadBuilder = $payloadBuilder;
        $this->keysDir = $keysDir ?? dirname(__DIR__, 2) . '/keys';
        $this->endpointPolicy = $endpointPolicy ?? new SmartUcfEndpointPolicy();
    }

    /**
     * Creates a SmartUCF session and returns the session ID + redirect URL.
     *
     * @param array<string, mixed> $shop     Cached shop configuration
     * @param array<string, mixed> $snapshot Financing snapshot row
     *
     * @return array{session_id: string, redirect_url: string, http_code: int, raw_request: string, raw_response: string}
     */
    public function createSession(
        array $shop,
        array $snapshot,
        ?CertificateConsumerLease $certificateLease = null
    ): array {
        $serviceBase = $this->serviceUrl($shop);
        $applicationBase = $this->applicationUrl($shop);

        try {
            $url = $this->endpointPolicy->buildSessionStartUrl($serviceBase);
            $this->endpointPolicy->assertTrustedApplicationBase($applicationBase);
        } catch (\InvalidArgumentException $exception) {
            \PrestaShopLogger::addLog(
                'UniPayment SmartUCF URL rejected before send: '
                    . $exception->getMessage()
                    . ' service=' . $this->endpointPolicy->describeUrlForLog($serviceBase)
                    . ' application=' . $this->endpointPolicy->describeUrlForLog($applicationBase),
                3
            );
            throw new SmartUcfSessionException(
                'The SmartUCF endpoint URL is not trusted.',
                true,
                '',
                0,
                SmartUcfSessionException::KIND_PRE_SEND
            );
        }

        $payload = $this->payloadBuilder->build($shop, $snapshot);
        $useCert = ShopConfigurationFlags::usesSmartUcfCertificate($shop);

        $keyPath = null;
        $certPath = null;
        $certPassword = CertificatePairValidator::PASSPHRASE;
        if ($useCert) {
            if ($certificateLease !== null) {
                $keyPath = $certificateLease->privateKeyPath();
                $certPath = $certificateLease->certificatePath();
                $certPassword = $certificateLease->password();
            } else {
                $keyPath = $this->keysDir . '/avalon_private_key.pem';
                $certPath = $this->keysDir . '/avalon_cert.pem';
            }
            if ($keyPath === '' || $certPath === '' || !is_readable($keyPath) || !is_readable($certPath)) {
                throw new SmartUcfSessionException(
                    'SmartUCF SSL key or certificate is missing or unreadable.',
                    true,
                    '',
                    0,
                    SmartUcfSessionException::KIND_PRE_SEND
                );
            }
        }

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_TIMEOUT => self::HTTP_TIMEOUT_SECONDS,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'cache-control: no-cache',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($useCert && $keyPath !== null && $certPath !== null) {
            $options[CURLOPT_SSLKEY] = $keyPath;
            $options[CURLOPT_SSLKEYPASSWD] = $certPassword;
            $options[CURLOPT_SSLCERT] = $certPath;
            $options[CURLOPT_SSLCERTPASSWD] = $certPassword;
            $options[CURLOPT_SSLVERSION] = CURL_SSLVERSION_TLSv1_2;
        }

        $ch = curl_init();
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $rawResponse = is_string($response) ? $response : '';

        if ($error !== '') {
            throw new SmartUcfSessionException(
                'SmartUCF connection failed: ' . $error,
                false,
                $rawResponse !== '' ? $rawResponse : $error,
                $httpCode,
                SmartUcfSessionException::KIND_TRANSPORT
            );
        }

        if ($rawResponse === '') {
            throw new SmartUcfSessionException(
                'SmartUCF returned an empty response.',
                false,
                '',
                $httpCode,
                SmartUcfSessionException::KIND_TRANSPORT
            );
        }

        $decoded = json_decode($rawResponse, false);
        if (!is_object($decoded)) {
            throw new SmartUcfSessionException(
                'SmartUCF returned invalid JSON.',
                false,
                $rawResponse,
                $httpCode,
                SmartUcfSessionException::KIND_TRANSPORT
            );
        }

        $sessionId = isset($decoded->sucfOnlineSessionID) ? trim((string) $decoded->sucfOnlineSessionID) : '';
        if ($sessionId === '') {
            $kind = $this->detectDuplicateKind($rawResponse, $httpCode);
            throw new SmartUcfSessionException(
                'SmartUCF did not return a session identifier.',
                false,
                $rawResponse,
                $httpCode,
                $kind
            );
        }

        try {
            $redirectUrl = $this->endpointPolicy->buildApplicationRedirect($applicationBase, $sessionId);
        } catch (\InvalidArgumentException $exception) {
            // Remote session may already exist — must not be classified as pre-send retryable.
            throw new SmartUcfSessionException(
                'SmartUCF session redirect could not be built safely.',
                false,
                $rawResponse,
                $httpCode,
                SmartUcfSessionException::KIND_TRANSPORT
            );
        }

        return [
            'session_id' => $sessionId,
            'redirect_url' => $redirectUrl,
            'http_code' => $httpCode,
            'raw_request' => $jsonPayload,
            'raw_response' => $rawResponse,
        ];
    }

    private function detectDuplicateKind(string $rawResponse, int $httpCode): string
    {
        $haystack = strtolower($rawResponse);
        $duplicate = (strpos($haystack, 'duplicate') !== false && strpos($haystack, 'order') !== false)
            || strpos($haystack, 'already exists') !== false
            || strpos($haystack, 'order already') !== false
            || strpos($haystack, 'съществува') !== false;
        if ($duplicate) {
            return SmartUcfSessionException::KIND_DUPLICATE;
        }
        if ($httpCode >= 500 || $httpCode === 0) {
            return SmartUcfSessionException::KIND_TRANSPORT;
        }

        return SmartUcfSessionException::KIND_REMOTE;
    }

    /** @param array<string, mixed> $shop */
    private function serviceUrl(array $shop): string
    {
        return ShopConfigurationFlags::isTestEnvironment($shop)
            ? trim((string) ($shop['uni_test_service'] ?? ''))
            : trim((string) ($shop['uni_production_service'] ?? ''));
    }

    /** @param array<string, mixed> $shop */
    private function applicationUrl(array $shop): string
    {
        return ShopConfigurationFlags::isTestEnvironment($shop)
            ? trim((string) ($shop['uni_test_application'] ?? ''))
            : trim((string) ($shop['uni_production_application'] ?? ''));
    }
}
