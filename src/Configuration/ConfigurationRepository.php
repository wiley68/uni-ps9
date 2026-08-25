<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Configuration;

/**
 * Local UniPayment configuration stored in PrestaShop Configuration.
 *
 * Ported from uni-ps8 ConfigurationRepository. Control Panel token keys are
 * cleaned on uninstall by name (see TokenRepository constants).
 */
final class ConfigurationRepository
{
    public const ENABLED = 'UNIPAYMENT_ENABLED';
    public const UNICID = 'UNIPAYMENT_UNICID';
    public const SECRET = 'UNIPAYMENT_SECRET';
    public const ADVERTISING_ENABLED = 'UNIPAYMENT_ADVERTISING_ENABLED';
    public const DEBUG_ENABLED = 'UNIPAYMENT_DEBUG_ENABLED';
    public const PRODUCT_BUTTON_ACTION = 'UNIPAYMENT_PRODUCT_BUTTON_ACTION';
    public const BUTTON_TOP_SPACING = 'UNIPAYMENT_BUTTON_TOP_SPACING';
    public const SYNC_BANK_REJECTION_STATE = 'UNIPAYMENT_SYNC_BANK_REJECTION_STATE';

    public const BUTTON_ACTION_ADD_TO_CART = 'add_to_cart';
    public const BUTTON_ACTION_BUY = 'buy';
    public const DEFAULT_ADVERTISING_ENABLED = false;
    public const DEFAULT_DEBUG_ENABLED = false;
    public const DEFAULT_PRODUCT_BUTTON_ACTION = self::BUTTON_ACTION_ADD_TO_CART;
    public const DEFAULT_BUTTON_TOP_SPACING = 0;
    public const DEFAULT_SYNC_BANK_REJECTION_STATE = false;
    public const MAX_BUTTON_TOP_SPACING = 200;

    private const ENCRYPTED_PREFIX = 'enc:v1:';

    /** Control Panel token keys (cleaned on uninstall; owned by TokenRepository). */
    private const CP_ACCESS_TOKEN = 'UNIPAYMENT_CP_ACCESS_TOKEN';
    private const CP_TOKEN_TYPE = 'UNIPAYMENT_CP_TOKEN_TYPE';
    private const CP_TOKEN_EXPIRES_AT = 'UNIPAYMENT_CP_TOKEN_EXPIRES_AT';

    /** Future privacy cleanup marker (string cleanup only). */
    private const LAST_PRIVACY_CLEANUP = 'UNIPAYMENT_LAST_PRIVACY_CLEANUP';

    public function install(): bool
    {
        return \Configuration::updateValue(self::ENABLED, true)
            && \Configuration::updateValue(self::UNICID, '')
            && \Configuration::updateValue(self::ADVERTISING_ENABLED, self::DEFAULT_ADVERTISING_ENABLED)
            && \Configuration::updateValue(self::DEBUG_ENABLED, self::DEFAULT_DEBUG_ENABLED)
            && \Configuration::updateValue(self::PRODUCT_BUTTON_ACTION, self::DEFAULT_PRODUCT_BUTTON_ACTION)
            && \Configuration::updateValue(self::BUTTON_TOP_SPACING, self::DEFAULT_BUTTON_TOP_SPACING)
            && \Configuration::updateValue(self::SYNC_BANK_REJECTION_STATE, self::DEFAULT_SYNC_BANK_REJECTION_STATE);
    }

    public function uninstall(): bool
    {
        $result = true;

        foreach (
            [
                self::ENABLED,
                self::UNICID,
                self::SECRET,
                self::ADVERTISING_ENABLED,
                self::DEBUG_ENABLED,
                self::PRODUCT_BUTTON_ACTION,
                self::BUTTON_TOP_SPACING,
                self::SYNC_BANK_REJECTION_STATE,
                self::CP_ACCESS_TOKEN,
                self::CP_TOKEN_TYPE,
                self::CP_TOKEN_EXPIRES_AT,
                self::LAST_PRIVACY_CLEANUP,
            ] as $key
        ) {
            $result = \Configuration::deleteByName($key) && $result;
        }

        return $result;
    }

    public function save(
        bool $enabled,
        string $unicid,
        ?string $secret,
        bool $advertisingEnabled = self::DEFAULT_ADVERTISING_ENABLED,
        bool $debugEnabled = self::DEFAULT_DEBUG_ENABLED,
        string $productButtonAction = self::DEFAULT_PRODUCT_BUTTON_ACTION,
        int $buttonTopSpacing = self::DEFAULT_BUTTON_TOP_SPACING,
        bool $syncBankRejectionState = self::DEFAULT_SYNC_BANK_REJECTION_STATE
    ): bool {
        $result = \Configuration::updateValue(self::ENABLED, $enabled)
            && \Configuration::updateValue(self::UNICID, $unicid)
            && \Configuration::updateValue(self::ADVERTISING_ENABLED, $advertisingEnabled)
            && \Configuration::updateValue(self::DEBUG_ENABLED, $debugEnabled)
            && \Configuration::updateValue(self::PRODUCT_BUTTON_ACTION, $this->normalizeProductButtonAction($productButtonAction))
            && \Configuration::updateValue(self::BUTTON_TOP_SPACING, $this->normalizeButtonTopSpacing($buttonTopSpacing))
            && \Configuration::updateValue(self::SYNC_BANK_REJECTION_STATE, $syncBankRejectionState);

        if ($secret === null) {
            return $result;
        }

        return \Configuration::updateValue(self::SECRET, $this->encrypt($secret)) && $result;
    }

    public function isEnabled(): bool
    {
        return (bool) \Configuration::get(self::ENABLED, null, null, null, true);
    }

    public function getUnicid(): string
    {
        return trim((string) \Configuration::get(self::UNICID));
    }

    public function isAdvertisingEnabled(): bool
    {
        return (bool) \Configuration::get(self::ADVERTISING_ENABLED, null, null, null, self::DEFAULT_ADVERTISING_ENABLED);
    }

    public function isDebugEnabled(): bool
    {
        return (bool) \Configuration::get(self::DEBUG_ENABLED, null, null, null, self::DEFAULT_DEBUG_ENABLED);
    }

    public function isSyncBankRejectionStateEnabled(): bool
    {
        return (bool) \Configuration::get(
            self::SYNC_BANK_REJECTION_STATE,
            null,
            null,
            null,
            self::DEFAULT_SYNC_BANK_REJECTION_STATE
        );
    }

    public function getProductButtonAction(): string
    {
        return $this->normalizeProductButtonAction((string) \Configuration::get(
            self::PRODUCT_BUTTON_ACTION,
            null,
            null,
            null,
            self::DEFAULT_PRODUCT_BUTTON_ACTION
        ));
    }

    public function getButtonTopSpacing(): int
    {
        return $this->normalizeButtonTopSpacing((int) \Configuration::get(
            self::BUTTON_TOP_SPACING,
            null,
            null,
            null,
            self::DEFAULT_BUTTON_TOP_SPACING
        ));
    }

    public function getSecret(): ?string
    {
        $storedSecret = (string) \Configuration::get(self::SECRET);
        if ($storedSecret === '') {
            return null;
        }

        if (strpos($storedSecret, self::ENCRYPTED_PREFIX) !== 0) {
            return null;
        }

        try {
            $decrypted = $this->getCipher()->decrypt(substr($storedSecret, strlen(self::ENCRYPTED_PREFIX)));
        } catch (\Throwable $exception) {
            return null;
        }

        return is_string($decrypted) && $decrypted !== '' ? $decrypted : null;
    }

    public function hasSecret(): bool
    {
        return $this->getSecret() !== null;
    }

    public function isSecretReadable(): bool
    {
        $storedSecret = (string) \Configuration::get(self::SECRET);

        return $storedSecret === '' || $this->getSecret() !== null;
    }

    private function encrypt(string $secret): string
    {
        return self::ENCRYPTED_PREFIX . $this->getCipher()->encrypt($secret);
    }

    private function normalizeProductButtonAction(string $action): string
    {
        return in_array($action, [self::BUTTON_ACTION_ADD_TO_CART, self::BUTTON_ACTION_BUY], true)
            ? $action
            : self::DEFAULT_PRODUCT_BUTTON_ACTION;
    }

    private function normalizeButtonTopSpacing(int $spacing): int
    {
        return max(0, min(self::MAX_BUTTON_TOP_SPACING, $spacing));
    }

    private function getCipher(): \PhpEncryption
    {
        return new \PhpEncryption(_NEW_COOKIE_KEY_);
    }
}
