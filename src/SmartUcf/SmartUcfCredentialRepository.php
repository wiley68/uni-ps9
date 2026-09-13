<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\SmartUcf;

/**
 * Shop-scoped SmartUCF credential pair — encrypted at rest via PhpEncryption.
 *
 * Distinct from module CP secret (UNICID/secret). Credentials never live in shop_data cache.
 */
final class SmartUcfCredentialRepository
{
    public const USER_KEY = 'UNIPAYMENT_SMARTUCF_USER';
    public const PASSWORD_KEY = 'UNIPAYMENT_SMARTUCF_PASSWORD';

    /** @var SmartUcfCredentialSettingStoreInterface */
    private $settings;

    /** @var SmartUcfCredentialCipher */
    private $cipher;

    /** @var int */
    private $idShop;

    public function __construct(
        ?SmartUcfCredentialSettingStoreInterface $settings = null,
        ?SmartUcfCredentialCipher $cipher = null,
        ?int $idShop = null
    ) {
        $this->settings = $settings ?? new ConfigurationSmartUcfCredentialSettingStore();
        $this->cipher = $cipher ?? new SmartUcfCredentialCipher();
        $this->idShop = $idShop !== null ? max(0, $idShop) : $this->resolveContextShopId();
    }

    public function shopId(): int
    {
        return $this->idShop;
    }

    /**
     * Exact raw stored envelopes (or null when unset) for rollback / diagnostics.
     * Both keys are loaded via one exact-context store read.
     *
     * @return array{user: ?string, password: ?string}
     */
    public function captureRawPair(): array
    {
        $pair = $this->settings->getPair($this->idShop);

        return [
            'user' => $this->normalizeRaw($pair['user'] ?? null),
            'password' => $this->normalizeRaw($pair['password'] ?? null),
        ];
    }

    /**
     * @param array{user: ?string, password: ?string} $prior
     */
    public function restoreRawPair(array $prior): void
    {
        $this->writeRaw(self::USER_KEY, $prior['user'] ?? null);
        $this->writeRaw(self::PASSWORD_KEY, $prior['password'] ?? null);
    }

    public function saveCompletePair(string $username, string $password): void
    {
        $username = trim($username);
        $password = trim($password);
        if ($username === '' || $password === '') {
            throw new \InvalidArgumentException('SmartUCF credential pair must be complete and non-empty.');
        }

        $encryptedUser = $this->cipher->encrypt($username);
        $encryptedPassword = $this->cipher->encrypt($password);
        $this->replaceEncryptedPair($encryptedUser, $encryptedPassword);
    }

    /**
     * Write pre-encrypted envelopes (encrypt-before-write + atomic restore of identical bytes).
     */
    public function replaceEncryptedPair(string $encryptedUser, string $encryptedPassword): void
    {
        if (
            !$this->cipher->isEncryptedEnvelope($encryptedUser)
            || !$this->cipher->isEncryptedEnvelope($encryptedPassword)
        ) {
            throw new \InvalidArgumentException('SmartUCF credential envelopes must use enc:v1:.');
        }

        $this->settings->set($this->idShop, self::USER_KEY, $encryptedUser);
        $this->settings->set($this->idShop, self::PASSWORD_KEY, $encryptedPassword);
    }

    public function getUsername(): ?string
    {
        $pair = $this->decryptPair();

        return $pair['user'];
    }

    public function getPassword(): ?string
    {
        $pair = $this->decryptPair();

        return $pair['password'];
    }

    public function hasCompleteReadablePair(): bool
    {
        $pair = $this->decryptPair();

        return $pair['user'] !== null && $pair['password'] !== null;
    }

    public function deletePair(): void
    {
        $this->settings->delete($this->idShop, self::USER_KEY);
        $this->settings->delete($this->idShop, self::PASSWORD_KEY);
    }

    /**
     * Remove dedicated credential keys for all shops (module uninstall).
     */
    public function uninstallAllShops(): bool
    {
        $this->settings->deleteByNameAllShops(self::USER_KEY);
        $this->settings->deleteByNameAllShops(self::PASSWORD_KEY);

        return true;
    }

    /**
     * Inject decrypted credentials into a runtime shop context. Never hydrates only one side.
     * Never persists the hydrated structure.
     *
     * @param array<string, mixed> $shopData
     *
     * @return array<string, mixed>
     */
    public function hydrateShopSnapshot(array $shopData): array
    {
        $pair = $this->decryptPair();
        $shopData = SmartUcfCredentialPairClassifier::stripFromSnapshot($shopData);

        if ($pair['user'] === null || $pair['password'] === null) {
            return $shopData;
        }

        $shopData['uni_user'] = $pair['user'];
        $shopData['uni_password'] = $pair['password'];

        return $shopData;
    }

    public function encryptPlain(string $plaintext): string
    {
        return $this->cipher->encrypt($plaintext);
    }

    /**
     * Decrypt both credentials from one capture — never exposes a one-sided pair.
     *
     * @return array{user: ?string, password: ?string}
     */
    private function decryptPair(): array
    {
        $raw = $this->captureRawPair();
        $user = $this->decryptRaw($raw['user']);
        $password = $this->decryptRaw($raw['password']);
        if ($user === null || $password === null) {
            return ['user' => null, 'password' => null];
        }

        return ['user' => $user, 'password' => $password];
    }

    private function decryptRaw(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }
        if (!$this->cipher->isEncryptedEnvelope($stored)) {
            return null;
        }
        try {
            $plain = trim($this->cipher->decrypt($stored));
        } catch (\Throwable $exception) {
            return null;
        }

        return $plain !== '' ? $plain : null;
    }

    private function normalizeRaw(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value;
    }

    private function writeRaw(string $key, ?string $value): void
    {
        if ($value === null || $value === '') {
            $this->settings->delete($this->idShop, $key);

            return;
        }

        $this->settings->set($this->idShop, $key, $value);
    }

    private function resolveContextShopId(): int
    {
        if (class_exists('\\Context')) {
            $context = \Context::getContext();
            if (isset($context->shop) && is_object($context->shop) && isset($context->shop->id)) {
                return max(0, (int) $context->shop->id);
            }
        }
        if (class_exists('\\Shop') && method_exists('\\Shop', 'getContextShopID')) {
            return max(0, (int) \Shop::getContextShopID(true));
        }

        return 0;
    }
}
