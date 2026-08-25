<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('_NEW_COOKIE_KEY_', 'test-key');

final class Configuration
{
    /** @var array<string, mixed> */
    public static $values = [];

    /**
     * @param string|array<string, string> $value
     */
    public static function updateValue(string $key, mixed $value): bool
    {
        self::$values[$key] = $value;

        return true;
    }

    public static function get(
        string $key,
        ?int $idLang = null,
        ?int $idShopGroup = null,
        ?int $idShop = null,
        mixed $default = false
    ): mixed {
        return self::$values[$key] ?? $default;
    }

    public static function deleteByName(string $key): bool
    {
        unset(self::$values[$key]);

        return true;
    }
}

final class PhpEncryption
{
    public function __construct(string $key) {}

    public function encrypt(string $plaintext): string
    {
        return base64_encode(strrev($plaintext));
    }

    /**
     * @return string|false
     */
    public function decrypt(string $ciphertext)
    {
        $decoded = base64_decode($ciphertext, true);

        return is_string($decoded) ? strrev($decoded) : false;
    }
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;

$repository = new ConfigurationRepository();

if (!$repository->install() || !$repository->isEnabled()) {
    fwrite(STDERR, "FAIL: configuration defaults were not installed\n");
    exit(1);
}
if (
    $repository->isAdvertisingEnabled()
    || $repository->isDebugEnabled()
    || $repository->isSyncBankRejectionStateEnabled()
    || $repository->getProductButtonAction() !== ConfigurationRepository::BUTTON_ACTION_ADD_TO_CART
    || $repository->getButtonTopSpacing() !== 0
    || $repository->getUnicid() !== ''
    || $repository->hasSecret()
) {
    fwrite(STDERR, "FAIL: local configuration defaults are incorrect\n");
    exit(1);
}

$plainSecret = 'local-test-value';
if (!$repository->save(false, '123e4567-e89b-12d3-a456-426614174000', $plainSecret, true, true, ConfigurationRepository::BUTTON_ACTION_BUY, 24, true)) {
    fwrite(STDERR, "FAIL: configuration was not saved\n");
    exit(1);
}
if (
    $repository->isEnabled()
    || !$repository->isAdvertisingEnabled()
    || !$repository->isDebugEnabled()
    || !$repository->isSyncBankRejectionStateEnabled()
    || $repository->getProductButtonAction() !== ConfigurationRepository::BUTTON_ACTION_BUY
    || $repository->getButtonTopSpacing() !== 24
    || $repository->getUnicid() !== '123e4567-e89b-12d3-a456-426614174000'
) {
    fwrite(STDERR, "FAIL: local configuration values were not saved\n");
    exit(1);
}

if (Configuration::$values[ConfigurationRepository::SECRET] === $plainSecret) {
    fwrite(STDERR, "FAIL: secret was stored in plain text\n");
    exit(1);
}

if ($repository->getSecret() !== $plainSecret || !$repository->hasSecret()) {
    fwrite(STDERR, "FAIL: encrypted secret could not be read\n");
    exit(1);
}

$storedSecret = Configuration::$values[ConfigurationRepository::SECRET];
$repository->save(true, '123e4567-e89b-12d3-a456-426614174000', null);
if (Configuration::$values[ConfigurationRepository::SECRET] !== $storedSecret) {
    fwrite(STDERR, "FAIL: empty secret input did not preserve the stored value\n");
    exit(1);
}
if (!$repository->isEnabled()) {
    fwrite(STDERR, "FAIL: enabled flag was not updated on blank-secret save\n");
    exit(1);
}

$repository->save(true, '123e4567-e89b-12d3-a456-426614174000', null, false, false, 'invalid', -10);
if (
    $repository->getProductButtonAction() !== ConfigurationRepository::BUTTON_ACTION_ADD_TO_CART
    || $repository->getButtonTopSpacing() !== 0
) {
    fwrite(STDERR, "FAIL: repository safety normalization failed\n");
    exit(1);
}

$repository->save(true, '123e4567-e89b-12d3-a456-426614174000', null, false, false, ConfigurationRepository::BUTTON_ACTION_BUY, 500);
if ($repository->getButtonTopSpacing() !== ConfigurationRepository::MAX_BUTTON_TOP_SPACING) {
    fwrite(STDERR, "FAIL: button spacing upper clamp failed\n");
    exit(1);
}

if (!$repository->uninstall()) {
    fwrite(STDERR, "FAIL: module configuration uninstall returned false\n");
    exit(1);
}
foreach (
    [
        ConfigurationRepository::ENABLED,
        ConfigurationRepository::UNICID,
        ConfigurationRepository::SECRET,
        ConfigurationRepository::ADVERTISING_ENABLED,
        ConfigurationRepository::DEBUG_ENABLED,
        ConfigurationRepository::PRODUCT_BUTTON_ACTION,
        ConfigurationRepository::BUTTON_TOP_SPACING,
        ConfigurationRepository::SYNC_BANK_REJECTION_STATE,
    ] as $key
) {
    if (array_key_exists($key, Configuration::$values)) {
        fwrite(STDERR, "FAIL: local configuration key {$key} was not removed\n");
        exit(1);
    }
}

fwrite(STDOUT, "OK (configuration repository lifecycle and secret storage)\n");
