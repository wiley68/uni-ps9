<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Security;

use PrestaShop\Module\Unipayment\Api\Exception\ModuleApiException;
use PrestaShop\Module\Unipayment\Api\ModuleApiError;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;

final class ModuleRequestAuthenticator
{
    /** @var ConfigurationRepository */
    private $configuration;

    /** @var ModuleRequestSignatureVerifier */
    private $signatureVerifier;

    /** @var ApiNonceRepository */
    private $nonceRepository;

    /** @var ClockInterface */
    private $clock;

    public function __construct(
        ConfigurationRepository $configuration,
        ?ModuleRequestSignatureVerifier $signatureVerifier = null,
        ?ApiNonceRepository $nonceRepository = null,
        ?ClockInterface $clock = null
    ) {
        $this->configuration = $configuration;
        $this->clock = $clock ?? new SystemClock();
        $this->signatureVerifier = $signatureVerifier ?? new ModuleRequestSignatureVerifier($this->clock);
        $this->nonceRepository = $nonceRepository ?? new ApiNonceRepository();
    }

    /**
     * Authenticate a signed inbound CP→module request.
     *
     * Processing order (after POST + body-size checks in the controller):
     * headers → timestamp → nonce format → signature format → HMAC over exact raw body
     * → JSON decode → UNICID binding → atomic nonce claim.
     *
     * @param array<string, string> $headers
     * @return array{0: array<string, mixed>, 1: string} decoded payload and authenticated unicid
     */
    public function authenticate(string $rawBody, array $headers): array
    {
        if (!$this->configuration->isEnabled()) {
            throw new ModuleApiException(
                'The module is disabled.',
                403,
                ModuleApiError::MODULE_DISABLED
            );
        }

        $storedUnicid = $this->configuration->getUnicid();
        $storedSecret = $this->configuration->getSecret();
        if ($storedUnicid === '' || $storedSecret === null) {
            throw new ModuleApiException(
                'The module is not configured.',
                401,
                ModuleApiError::AUTHENTICATION_FAILED
            );
        }

        // HMAC over exact raw body before JSON decode / UNICID binding.
        $this->signatureVerifier->verify($storedSecret, $rawBody, $headers);

        $payload = $this->decodeJsonObject($rawBody);

        $unicid = $payload['unicid'] ?? null;
        if (!is_string($unicid) || $unicid === '') {
            throw $this->authFailure();
        }

        if (!hash_equals($storedUnicid, $unicid)) {
            throw $this->authFailure();
        }

        $nonce = $this->signatureVerifier->extractNonce($headers);
        if (!$this->nonceRepository->claimNonce($unicid, $nonce, $this->clock->now())) {
            if (class_exists('\PrestaShopLogger', false)) {
                \PrestaShopLogger::addLog('UniPayment module API replay detected.', 2);
            }
            throw $this->authFailure();
        }

        return [$payload, $unicid];
    }

    /** @return array<string, mixed> */
    private function decodeJsonObject(string $rawBody): array
    {
        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ModuleApiException(
                'The JSON request body is invalid.',
                400,
                ModuleApiError::MALFORMED_JSON
            );
        }

        if (!is_array($payload) || !$this->isJsonObject($payload)) {
            throw new ModuleApiException(
                'The JSON request body must be an object.',
                400,
                ModuleApiError::MALFORMED_JSON
            );
        }

        return $payload;
    }

    /** @param array<mixed> $value */
    private function isJsonObject(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }

    private function authFailure(): ModuleApiException
    {
        return new ModuleApiException(
            ModuleRequestSignatureProtocol::AUTH_FAILURE_MESSAGE,
            401,
            ModuleApiError::INVALID_SIGNATURE
        );
    }
}
