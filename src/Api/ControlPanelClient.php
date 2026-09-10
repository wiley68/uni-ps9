<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Api;

use PrestaShop\Module\Unipayment\Api\Exception\AuthenticationException;
use PrestaShop\Module\Unipayment\Api\Exception\HttpException;
use PrestaShop\Module\Unipayment\Api\Exception\InvalidPayloadException;
use PrestaShop\Module\Unipayment\Api\Exception\MalformedJsonException;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Configuration\ModuleDeploymentEnvironment;
use PrestaShop\Module\Unipayment\Security\TokenRepository;

final class ControlPanelClient implements ShopConfigurationProviderInterface
{
    private const REFRESH_MARGIN_SECONDS = 60;

    /** @var ConfigurationRepository */
    private $configuration;

    /** @var TokenRepository */
    private $tokens;

    /** @var HttpTransportInterface */
    private $transport;

    /** @var string */
    private $shopName;

    /** @var string */
    private $baseUrl;

    /** @var callable */
    private $clock;

    public function __construct(
        ConfigurationRepository $configuration,
        TokenRepository $tokens,
        HttpTransportInterface $transport,
        string $shopName,
        ?string $baseUrl = null,
        ?callable $clock = null
    ) {
        $this->configuration = $configuration;
        $this->tokens = $tokens;
        $this->transport = $transport;
        $this->shopName = rtrim(trim($shopName), '/');
        $resolved = $baseUrl !== null && trim($baseUrl) !== ''
            ? $baseUrl
            : (new ModuleDeploymentEnvironment())->controlPanelApiBaseUrl();
        $this->baseUrl = rtrim($resolved, '/');
        $this->clock = $clock ?? 'time';
    }

    /** @return array<string, mixed> */
    public function login(): array
    {
        $unicid = $this->configuration->getUnicid();
        $secret = $this->configuration->getSecret();
        if ($unicid === '' || $secret === null || $this->shopName === '') {
            $this->tokens->invalidate();
            throw new AuthenticationException('The Control Panel credentials are incomplete.');
        }

        $response = $this->send('POST', '/auth/login', [
            'unicid' => $unicid,
            'name' => $this->shopName,
            'secret' => $secret,
        ]);
        $this->storeTokenResponse($response, true);

        return $response;
    }

    /** @return array<string, mixed> */
    public function refreshToken(): array
    {
        $token = $this->tokens->getAccessToken();
        if ($token === null) {
            throw new AuthenticationException('There is no Control Panel token to refresh.');
        }

        try {
            $response = $this->send('POST', '/auth/refresh', null, $token);
            $this->storeTokenResponse($response, false);

            return $response;
        } catch (AuthenticationException $exception) {
            $this->tokens->invalidate();
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function logout(): array
    {
        $token = $this->tokens->getAccessToken();
        if ($token === null) {
            return ModuleApiResponse::success('Logged out locally.');
        }

        try {
            return $this->send('POST', '/auth/logout', null, $token);
        } finally {
            $this->tokens->invalidate();
        }
    }

    /** @return array<string, mixed> */
    public function getShop(): array
    {
        $response = $this->authenticatedRequest('GET', '/shop');
        $data = $response['data'] ?? null;
        if (!is_array($data) || !$this->isAssociativeObject($data)) {
            throw new InvalidPayloadException('The Control Panel shop response has no valid data object.');
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function createOrder(array $order): array
    {
        $response = $this->authenticatedRequest('POST', '/orders', $order);
        $this->assertCreateOrderIdentity($response, $order);

        return $response;
    }

    /** @return array<string, mixed> */
    public function updateOrderStatus(string $orderId, string $status, string $statusId): array
    {
        $payload = [
            'order_id' => $orderId,
            'status_id' => $statusId,
            'status' => $status,
        ];

        $response = $this->authenticatedRequest('PATCH', '/orders/status', $payload);
        $this->assertPatchStatusEcho($response, $payload);

        return $response;
    }

    /**
     * Lightweight SSL certificate metadata (no PEM body).
     *
     * @return array{
     *     available: bool,
     *     ssl_revision: string,
     *     certificate_sha256: string,
     *     private_key_sha256: string,
     *     not_before?: string,
     *     not_after?: string
     * }
     */
    public function getSslCertificateMetadata(): array
    {
        $response = $this->authenticatedRequest('GET', '/ssl/certificate');
        $data = $response['data'] ?? null;
        if (!is_array($data)) {
            throw new InvalidPayloadException('The Control Panel SSL metadata response has no data object.');
        }

        return $this->normalizeSslMetadata($data);
    }

    /**
     * Full SSL certificate + private key bundle.
     *
     * @return array{
     *     ssl_revision: string,
     *     certificate_sha256: string,
     *     private_key_sha256: string,
     *     not_before?: string,
     *     not_after?: string,
     *     certificate_pem: string,
     *     private_key_pem: string
     * }
     */
    public function downloadSslCertificateBundle(): array
    {
        $response = $this->authenticatedRequest('GET', '/ssl/certificate/bundle');
        $data = $response['data'] ?? null;
        if (!is_array($data)) {
            throw new InvalidPayloadException('The Control Panel SSL bundle response has no data object.');
        }
        foreach (['certificate_pem', 'private_key_pem', 'certificate_sha256', 'private_key_sha256'] as $required) {
            if (!isset($data[$required]) || !is_string($data[$required]) || $data[$required] === '') {
                throw new InvalidPayloadException('The Control Panel SSL bundle is missing required fields.');
            }
        }
        $meta = $this->normalizeSslMetadata(array_merge($data, ['available' => true]));

        return [
            'ssl_revision' => $meta['ssl_revision'],
            'certificate_sha256' => $meta['certificate_sha256'],
            'private_key_sha256' => $meta['private_key_sha256'],
            'not_before' => $meta['not_before'] ?? '',
            'not_after' => $meta['not_after'] ?? '',
            'certificate_pem' => (string) $data['certificate_pem'],
            'private_key_pem' => (string) $data['private_key_pem'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{
     *     available: bool,
     *     ssl_revision: string,
     *     certificate_sha256: string,
     *     private_key_sha256: string,
     *     not_before?: string,
     *     not_after?: string
     * }
     */
    private function normalizeSslMetadata(array $data): array
    {
        $available = !empty($data['available']);
        $certHash = strtolower(trim((string) ($data['certificate_sha256'] ?? '')));
        $keyHash = strtolower(trim((string) ($data['private_key_sha256'] ?? '')));
        if ($available) {
            if (!preg_match('/^[a-f0-9]{64}$/', $certHash) || !preg_match('/^[a-f0-9]{64}$/', $keyHash)) {
                throw new InvalidPayloadException('The Control Panel SSL metadata hashes are invalid.');
            }
        }

        return [
            'available' => $available,
            'ssl_revision' => (string) ($data['ssl_revision'] ?? ''),
            'certificate_sha256' => $certHash,
            'private_key_sha256' => $keyHash,
            'not_before' => isset($data['not_before']) ? (string) $data['not_before'] : '',
            'not_after' => isset($data['not_after']) ? (string) $data['not_after'] : '',
        ];
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function authenticatedRequest(string $method, string $path, ?array $payload = null): array
    {
        $token = $this->ensureToken();

        try {
            return $this->send($method, $path, $payload, $token);
        } catch (AuthenticationException $exception) {
            $this->tokens->invalidate();
            $this->login();
            $retryToken = $this->tokens->getAccessToken();
            if ($retryToken === null) {
                throw new AuthenticationException('Control Panel re-authentication did not provide a token.');
            }

            try {
                return $this->send($method, $path, $payload, $retryToken);
            } catch (AuthenticationException $retryException) {
                $this->tokens->invalidate();
                throw $retryException;
            }
        }
    }

    private function ensureToken(): string
    {
        $token = $this->tokens->getAccessToken();
        $now = $this->now();
        $expiresAt = $this->tokens->getExpiresAt();

        if ($token === null || $expiresAt <= $now) {
            $this->tokens->invalidate();
            $this->login();

            return (string) $this->tokens->getAccessToken();
        }

        if ($expiresAt <= $now + self::REFRESH_MARGIN_SECONDS) {
            try {
                $this->refreshToken();
            } catch (AuthenticationException $exception) {
                $this->login();
            }

            return (string) $this->tokens->getAccessToken();
        }

        return $token;
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, ?array $payload = null, ?string $token = null): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
        if ($token !== null) {
            $headers['Authorization'] = $this->tokens->getTokenType() . ' ' . $token;
        }

        $response = $this->transport->request(
            $method,
            $this->baseUrl . '/' . ltrim($path, '/'),
            $headers,
            $payload
        );
        if ($response->getStatusCode() === 401) {
            throw new AuthenticationException('The Control Panel rejected the authentication.');
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new HttpException(
                $response->getStatusCode(),
                $this->decodeErrorResponse($response->getBody())
            );
        }

        return $this->decodeSuccessEnvelope($response->getBody());
    }

    /** @return array<string, mixed> */
    private function decodeSuccessEnvelope(string $body): array
    {
        $decodedObject = $this->decodeJsonAsObject($body);
        if ($decodedObject === null) {
            throw new MalformedJsonException('The Control Panel JSON response is not an object.');
        }

        if (!property_exists($decodedObject, 'success')
            || $decodedObject->success !== true
            || !property_exists($decodedObject, 'error')
            || $decodedObject->error !== null
            || !property_exists($decodedObject, 'data')
            || !($decodedObject->data instanceof \stdClass)
        ) {
            throw new InvalidPayloadException('The Control Panel response does not confirm success.');
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(json_encode($decodedObject, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new MalformedJsonException('The Control Panel JSON response is not an object.');
        }

        return $decoded;
    }

    private function decodeJsonAsObject(string $body): ?\stdClass
    {
        try {
            $decoded = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new MalformedJsonException('The Control Panel returned malformed JSON.', 0, $exception);
        }

        return $decoded instanceof \stdClass ? $decoded : null;
    }

    /** @return array<string, mixed> */
    private function decodeErrorResponse(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $response */
    private function storeTokenResponse(array $response, bool $requireShop): void
    {
        $data = $response['data'] ?? null;
        if (!is_array($data)) {
            $this->tokens->invalidate();
            throw new InvalidPayloadException('The Control Panel token response has no valid data object.');
        }

        $accessToken = $data['access_token'] ?? null;
        $tokenType = $data['token_type'] ?? null;
        $expiresIn = $data['expires_in'] ?? null;

        if (
            !is_string($accessToken) || $accessToken === ''
            || !is_string($tokenType) || strcasecmp($tokenType, 'Bearer') !== 0
            || !is_numeric($expiresIn) || (int) $expiresIn <= 0
        ) {
            $this->tokens->invalidate();
            throw new InvalidPayloadException('The Control Panel token response is invalid.');
        }

        if ($requireShop) {
            $shop = $data['shop'] ?? null;
            if (!is_array($shop)) {
                $this->tokens->invalidate();
                throw new InvalidPayloadException('The Control Panel login response has no valid shop data.');
            }

            $responseUnicid = $shop['unicid'] ?? null;
            $configuredUnicid = $this->configuration->getUnicid();
            if (!is_string($responseUnicid) || $responseUnicid === '' || !hash_equals($configuredUnicid, $responseUnicid)) {
                $this->tokens->invalidate();
                throw new InvalidPayloadException('The Control Panel login shop UNICID does not match configuration.');
            }
        }

        if (!$this->tokens->save($accessToken, $tokenType, $this->now() + (int) $expiresIn)) {
            $this->tokens->invalidate();
            throw new InvalidPayloadException('The Control Panel token could not be stored.');
        }
    }

    /**
     * @param array<string, mixed> $response
     * @param array<string, mixed> $order
     */
    private function assertCreateOrderIdentity(array $response, array $order): void
    {
        $data = $response['data'] ?? null;
        if (!is_array($data)) {
            throw new InvalidPayloadException('The Control Panel create-order response has no valid data object.');
        }

        $id = $data['id'] ?? null;
        if (!is_numeric($id) || (int) $id <= 0) {
            throw new InvalidPayloadException('The Control Panel create-order response has no order id.');
        }

        $sentOrderId = (string) ($order['order_id'] ?? '');
        $echoOrderId = isset($data['order_id']) ? (string) $data['order_id'] : '';
        if ($sentOrderId === '' || $echoOrderId !== $sentOrderId) {
            throw new InvalidPayloadException('The Control Panel create-order response order_id does not match the request.');
        }

        $configuredUnicid = $this->configuration->getUnicid();
        $echoUnicid = isset($data['unicid']) ? (string) $data['unicid'] : '';
        if ($configuredUnicid === '' || $echoUnicid === '' || !hash_equals($configuredUnicid, $echoUnicid)) {
            throw new InvalidPayloadException('The Control Panel create-order response unicid does not match configuration.');
        }

        $shopId = $data['shop_id'] ?? null;
        if (!is_numeric($shopId) || (int) $shopId <= 0) {
            throw new InvalidPayloadException('The Control Panel create-order response has no valid shop_id.');
        }

        $createdAt = $data['created_at'] ?? null;
        if (!is_string($createdAt) || trim($createdAt) === '') {
            throw new InvalidPayloadException('The Control Panel create-order response has no valid created_at.');
        }
    }

    /**
     * @param array<string, mixed> $response
     * @param array{order_id: string, status_id: string, status: string} $payload
     */
    private function assertPatchStatusEcho(array $response, array $payload): void
    {
        $data = $response['data'] ?? null;
        if (!is_array($data)) {
            throw new InvalidPayloadException('The Control Panel status response has no valid data object.');
        }

        if ((string) ($data['order_id'] ?? '') !== $payload['order_id']
            || (string) ($data['status_id'] ?? '') !== $payload['status_id']
            || (string) ($data['status'] ?? '') !== $payload['status']
        ) {
            throw new InvalidPayloadException('The Control Panel status response does not echo the request identity.');
        }
    }

    /** @param array<mixed> $value */
    private function isAssociativeObject(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }

    private function now(): int
    {
        return (int) call_user_func($this->clock);
    }
}
