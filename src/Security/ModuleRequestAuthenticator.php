<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Security;

use PrestaShop\Module\Unipayment\Api\Exception\ModuleApiException;
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
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public function authenticate(array $payload, string $rawBody, array $headers): string
    {
        if (!$this->configuration->isEnabled()) {
            throw new ModuleApiException('The module is disabled.', 403);
        }

        $storedUnicid = $this->configuration->getUnicid();
        $storedSecret = $this->configuration->getSecret();
        if ($storedUnicid === '' || $storedSecret === null) {
            throw new ModuleApiException('The module is not configured.', 401);
        }

        $unicid = $payload['unicid'] ?? null;
        if (!is_string($unicid) || $unicid === '') {
            throw $this->authFailure();
        }

        if (!hash_equals($storedUnicid, $unicid)) {
            throw $this->authFailure();
        }

        $this->signatureVerifier->verify($storedSecret, $rawBody, $headers);

        $nonce = $this->signatureVerifier->extractNonce($headers);
        if (!$this->nonceRepository->claimNonce($unicid, $nonce, $this->clock->now())) {
            if (class_exists('\PrestaShopLogger', false)) {
                \PrestaShopLogger::addLog('UniPayment module API replay detected.', 2);
            }
            throw $this->authFailure();
        }

        return $unicid;
    }

    private function authFailure(): ModuleApiException
    {
        return new ModuleApiException(ModuleRequestSignatureProtocol::AUTH_FAILURE_MESSAGE, 401);
    }
}
