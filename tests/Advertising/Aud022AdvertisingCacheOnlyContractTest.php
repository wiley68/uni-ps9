<?php

declare(strict_types=1);

/**
 * AUD-022 contracts: FO advertising is cache-only (no get→refresh→provider).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertAud022Contract(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$module = (string) file_get_contents($root . '/unipayment.php');
$service = (string) file_get_contents($root . '/src/Configuration/ShopConfigurationService.php');
$setMediaStart = strpos($module, 'function hookActionFrontControllerSetMedia');
assertAud022Contract($setMediaStart !== false, 'setMedia hook exists');
$setMedia = substr($module, $setMediaStart, 900);

// E: homepageAdvertisingContext uses getCachedOnly only
assertAud022Contract(
    strpos($module, 'createShopConfigurationService()->getCachedOnly()') !== false,
    'E: homepageAdvertisingContext uses getCachedOnly'
);
$contextStart = strpos($module, 'function homepageAdvertisingContext');
assertAud022Contract($contextStart !== false, 'homepageAdvertisingContext exists');
$contextBody = substr($module, $contextStart, 1200);
assertAud022Contract(strpos($contextBody, 'getCachedOnly()') !== false, 'E: context body calls getCachedOnly');
assertAud022Contract(
    !preg_match('/createShopConfigurationService\(\)->get\s*\(/', $contextBody),
    'E: context must not call get('
);
assertAud022Contract(strpos($contextBody, 'getShop(') === false, 'E: context must not call getShop');

// F: setMedia goes through homepageAdvertisingContext (cache-only), not refresh
assertAud022Contract(strpos($setMedia, 'homepageAdvertisingContext()') !== false, 'F: setMedia uses advertising context');
assertAud022Contract(strpos($setMedia, 'ShopConfigurationService') === false, 'F: setMedia does not construct service directly');
assertAud022Contract(strpos($setMedia, '->get(true)') === false, 'F: setMedia must not force refresh');
assertAud022Contract(strpos($setMedia, '->refresh(') === false, 'F: setMedia must not call refresh');

// Architectural boundary: getCachedOnly never calls refresh/provider
assertAud022Contract(strpos($service, 'function getCachedOnly') !== false, 'getCachedOnly API exists');
$cachedOnlyStart = strpos($service, 'function getCachedOnly');
$cachedOnlyEnd = strpos($service, 'function replaceSnapshot', $cachedOnlyStart);
assertAud022Contract($cachedOnlyStart !== false && $cachedOnlyEnd !== false, 'getCachedOnly method bounds');
$cachedOnlyBody = substr($service, $cachedOnlyStart, $cachedOnlyEnd - $cachedOnlyStart);
assertAud022Contract(strpos($cachedOnlyBody, 'refresh(') === false, 'getCachedOnly must not call refresh');
assertAud022Contract(strpos($cachedOnlyBody, 'provider') === false, 'getCachedOnly must not touch provider');
assertAud022Contract(strpos($cachedOnlyBody, 'getFresh(') !== false, 'getCachedOnly reads local cache');
assertAud022Contract(strpos($cachedOnlyBody, 'login') === false, 'getCachedOnly must not login');

// G / H: explicit refresh + replaceSnapshot preserved
assertAud022Contract(strpos($service, 'private function refresh') !== false, 'G: refresh preserved');
assertAud022Contract(
    (bool) preg_match('/function get\(bool \$forceRefresh/', $service),
    'G: get(forceRefresh) preserved'
);
assertAud022Contract(strpos($service, 'function replaceSnapshot') !== false, 'H: replaceSnapshot preserved');

// Regression: advertising must not regress to get()
assertAud022Contract(
    strpos($module, 'createShopConfigurationService()->get()') === false
        || !preg_match(
            '/homepageAdvertisingContext[\s\S]{0,1200}createShopConfigurationService\(\)->get\s*\(/',
            $module
        ),
    'regression: advertising must not use get()'
);

fwrite(STDOUT, "OK (AUD-022 advertising cache-only contracts)\n");
