<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Advertising\HomepageAdvertisingGate;
use PrestaShop\Module\Unipayment\Advertising\HomepageAdvertisingPresenter;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;

final class Configuration
{
    public static array $values = [];

    public static function get(string $key, ?int $idLang = null, ?int $idShopGroup = null, ?int $idShop = null, mixed $default = false): mixed
    {
        if ($key === ConfigurationRepository::ADVERTISING_ENABLED) {
            throw new RuntimeException('Advertising eligibility must never read the legacy local switch.');
        }

        return self::$values[$key] ?? $default;
    }
}

function assertAdvertisingGate(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$gate = new HomepageAdvertisingGate();

assertAdvertisingGate($gate->allowsPage('index'), 'PrestaShop homepage php_self=index must be allowed');
assertAdvertisingGate(!$gate->allowsPage('product'), 'product page must not load homepage advertising');
assertAdvertisingGate(!$gate->allowsPage('cart'), 'cart page must not load homepage advertising');
assertAdvertisingGate(!$gate->allowsPage('order'), 'checkout must not load homepage advertising');
assertAdvertisingGate(!$gate->allowsPage('category'), 'category listing must not load homepage advertising');

assertAdvertisingGate(
    $gate->allowsLocalSettings(true, true, 'SHOP-1'),
    'enabled module + UNICID must pass the local settings gate'
);
assertAdvertisingGate(
    !$gate->allowsLocalSettings(false, true, 'SHOP-1'),
    'inactive module must not enqueue homepage advertising'
);
assertAdvertisingGate(
    !$gate->allowsLocalSettings(true, false, 'SHOP-1'),
    'disabled UNIPAYMENT_ENABLED must not enqueue homepage advertising'
);
assertAdvertisingGate(
    !$gate->allowsLocalSettings(true, true, ''),
    'missing UNICID must not enqueue homepage advertising'
);
assertAdvertisingGate(
    !$gate->allowsLocalSettings(true, true, '   '),
    'whitespace UNICID must not enqueue homepage advertising'
);

assertAdvertisingGate(
    $gate->allowsAssets('index', true, true, 'SHOP-1'),
    'homepage + local settings must allow asset enqueue'
);
assertAdvertisingGate(
    !$gate->allowsAssets('product', true, true, 'SHOP-1'),
    'valid settings on a non-home page must still skip advertising assets'
);
assertAdvertisingGate(
    !$gate->allowsAssets('index', true, false, 'SHOP-1'),
    'homepage with module disabled must skip advertising assets'
);

$repository = new ConfigurationRepository();
$presenter = new HomepageAdvertisingPresenter($gate);
foreach ([0, 1, null] as $legacyAdvertising) {
    Configuration::$values = [ConfigurationRepository::ENABLED => 1, ConfigurationRepository::UNICID => 'SHOP-1'];
    if ($legacyAdvertising !== null) {
        Configuration::$values[ConfigurationRepository::ADVERTISING_ENABLED] = $legacyAdvertising;
    }
    foreach ([0, 1] as $cpAdvertising) {
        foreach ([false, true] as $isMobile) {
            $allowed = $gate->allowsAssets('index', true, $repository->isEnabled(), $repository->getUnicid());
            $payload = $allowed ? $presenter->present(
                ['uni_status' => 1, 'uni_container_status' => $cpAdvertising, 'uni_picturem' => 'https://cdn.example.test/ad.jpg'],
                $isMobile,
                '/modules/unipayment/views/img/product/uni_logo.svg'
            ) : null;
            assertAdvertisingGate(($payload !== null) === ($cpAdvertising === 1), 'CP alone controls visibility for legacy 0/1/missing on desktop and mobile');
        }
    }
}

assertAdvertisingGate($gate->allowsShop(['uni_status' => 1, 'uni_container_status' => 1]), 'active CP shop + container must render');
assertAdvertisingGate($gate->allowsShop(['uni_status' => 'Yes', 'uni_container_status' => '1']), 'Woo yes-flag values must be accepted');
assertAdvertisingGate(!$gate->allowsShop(['uni_status' => 0, 'uni_container_status' => 1]), 'inactive CP shop must hide advertising');
assertAdvertisingGate(!$gate->allowsShop(['uni_status' => 1, 'uni_container_status' => 0]), 'disabled CP container must hide advertising');
assertAdvertisingGate(!$gate->allowsShop([]), 'missing CP flags must hide advertising');

fwrite(STDOUT, "OK (Homepage advertising permission gates)\n");
