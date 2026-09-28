<?php

declare(strict_types=1);

/**
 * REM-PS9-CACHE-001 contracts: FO advertising uses the shared presentation resolver.
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

// Homepage uses the credential-free view of the common presentation resolver.
assertAud022Contract(
    strpos($module, 'createShopConfigurationService()->getForPresentationWithoutCredentials()') !== false,
    'homepageAdvertisingContext uses shared presentation resolution'
);
$contextStart = strpos($module, 'function homepageAdvertisingContext');
assertAud022Contract($contextStart !== false, 'homepageAdvertisingContext exists');
$contextBody = substr($module, $contextStart, 1200);
assertAud022Contract(strpos($contextBody, 'getForPresentationWithoutCredentials()') !== false, 'context calls presentation resolver');
assertAud022Contract(strpos($contextBody, 'getShop(') === false, 'E: context must not call getShop');

// setMedia goes through the same memoized homepage presentation context.
assertAud022Contract(strpos($setMedia, 'homepageAdvertisingContext()') !== false, 'F: setMedia uses advertising context');
assertAud022Contract(strpos($setMedia, 'ShopConfigurationService') === false, 'F: setMedia does not construct service directly');
assertAud022Contract(strpos($setMedia, '->get(true)') === false, 'F: setMedia must not force refresh');
assertAud022Contract(strpos($setMedia, '->refresh(') === false, 'F: setMedia must not call refresh');

assertAud022Contract(strpos($service, 'function getForPresentationWithoutCredentials') !== false, 'presentation API exists');
assertAud022Contract(strpos($service, 'function getForSubmission') !== false, 'submission API exists');

// G / H: explicit refresh + replaceSnapshot preserved
assertAud022Contract(strpos($service, 'private function refresh') !== false, 'G: refresh preserved');
assertAud022Contract(
    (bool) preg_match('/function get\(bool \$forceRefresh/', $service),
    'G: get(forceRefresh) preserved'
);
assertAud022Contract(strpos($service, 'function replaceSnapshot') !== false, 'H: replaceSnapshot preserved');

fwrite(STDOUT, "OK (REM-PS9 advertising shared resolver contracts)\n");
