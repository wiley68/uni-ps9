<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationFlags;

function assertFlags(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

assertFlags(ShopConfigurationFlags::isProcess2(['uni_proces' => 1]) === true, 'Process 2 when uni_proces=1');
assertFlags(ShopConfigurationFlags::isProcess2(['uni_proces' => 0]) === false, 'Process 1 when uni_proces=0');
assertFlags(ShopConfigurationFlags::isProcess2([]) === false, 'Process 1 default when missing');

assertFlags(ShopConfigurationFlags::isTestEnvironment(['uni_env' => 0]) === true, 'test env when uni_env=0');
assertFlags(ShopConfigurationFlags::isTestEnvironment(['uni_env' => 1]) === false, 'prod env when uni_env=1');
assertFlags(ShopConfigurationFlags::isTestEnvironment([]) === false, 'prod default when uni_env missing');

assertFlags(ShopConfigurationFlags::isYesFlag(true) === true, 'bool true is yes');
assertFlags(ShopConfigurationFlags::isYesFlag(1) === true, 'numeric 1 is yes');
assertFlags(ShopConfigurationFlags::isYesFlag('Yes') === true, 'Yes string is yes');
assertFlags(ShopConfigurationFlags::isYesFlag('true') === true, 'true string is yes');
assertFlags(ShopConfigurationFlags::isYesFlag(0) === false, '0 is not yes');
assertFlags(ShopConfigurationFlags::isYesFlag('no') === false, 'no is not yes');

assertFlags(
    ShopConfigurationFlags::usesSmartUcfCertificate(['uni_sertificat' => 1]) === true,
    'certificate flag when set'
);
assertFlags(
    ShopConfigurationFlags::usesSmartUcfCertificate([]) === false,
    'certificate flag default off'
);

fwrite(STDOUT, "OK (ShopConfigurationFlags Process 1/2 and helpers)\n");
