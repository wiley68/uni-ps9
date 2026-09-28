<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertCacheLifecycleContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$module = (string) file_get_contents($root . '/unipayment.php');
$productPopup = (string) file_get_contents($root . '/controllers/front/productpopup.php');
$cartPopup = (string) file_get_contents($root . '/controllers/front/cartpopup.php');
$checkout = (string) file_get_contents($root . '/controllers/front/validatecheckout.php');
$calculator = (string) file_get_contents($root . '/src/Calculator/Calculator.php');
$advertisingGate = (string) file_get_contents($root . '/src/Advertising/HomepageAdvertisingGate.php');
$cache = (string) file_get_contents($root . '/src/Configuration/ShopConfigurationCache.php');

assertCacheLifecycleContract(
    strpos($module, 'getForPresentationWithoutCredentials()') !== false,
    'homepage must use shared presentation resolution'
);
assertCacheLifecycleContract(
    preg_match('/\$action === \'apply\'[\s\S]{0,160}->getForSubmission\(\)/', $productPopup) === 1,
    'product apply must use submission resolution'
);
assertCacheLifecycleContract(
    preg_match('/\$action === \'apply\'[\s\S]{0,160}->getForSubmission\(\)/', $cartPopup) === 1,
    'cart apply must use submission resolution'
);
assertCacheLifecycleContract(
    strpos($checkout, 'getForSubmission()') !== false,
    'checkout commit must use submission resolution'
);
assertCacheLifecycleContract(
    strpos($cache, 'TTL_SECONDS = 86400') !== false && strpos($cache, 'LKG_SECONDS = 21600') !== false,
    'frozen TTL/LKG constants must remain 86400/21600'
);
assertCacheLifecycleContract(
    strpos($calculator, "['uni_container_status']") === false,
    'container status must not gate financing calculations'
);
assertCacheLifecycleContract(
    strpos($advertisingGate, "['uni_container_status']") !== false,
    'container status must gate homepage advertising'
);

fwrite(STDOUT, "OK (REM-PS9 lifecycle call-site and status contracts)\n");
