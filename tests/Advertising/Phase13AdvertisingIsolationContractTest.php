<?php

declare(strict_types=1);

/**
 * Phase 13 — advertising isolation from transactional surfaces + cache-only data source.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertPhase13Adv(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$module = (string) file_get_contents($root . '/unipayment.php');
$presenter = (string) file_get_contents($root . '/src/Advertising/HomepageAdvertisingPresenter.php');
$gate = (string) file_get_contents($root . '/src/Advertising/HomepageAdvertisingGate.php');
$validate = (string) file_get_contents($root . '/controllers/front/validatecheckout.php');
$orchestrator = (string) file_get_contents($root . '/src/Order/OrderOrchestrator.php');
$lifecycle = (string) file_get_contents($root . '/src/Order/PostControlPanelLifecycleService.php');
$mail = (string) file_get_contents($root . '/src/Order/LeasingEmailNotifier.php');

assertPhase13Adv(strpos($module, 'HomepageAdvertisingPresenter') !== false, 'module wires advertising presenter');
assertPhase13Adv(strpos($module, 'createShopConfigurationService()->getCachedOnly()') !== false, 'advertising uses cache-only shop config');
assertPhase13Adv(
    !preg_match('/homepageAdvertisingContext[\s\S]{0,1200}->get\s*\(/', $module),
    'advertising context must not call ShopConfigurationService::get()'
);
assertPhase13Adv(
    !preg_match('/homepageAdvertisingContext[\s\S]{0,800}getShop\(/', $module),
    'advertising context must not call CP getShop directly'
);
assertPhase13Adv(strpos($gate, "=== 'index'") !== false, 'advertising page gate is homepage-only');
assertPhase13Adv(strpos($presenter, 'strip_tags') !== false, 'promo text must strip tags');
assertPhase13Adv(strpos($presenter, 'FILTER_VALIDATE_URL') !== false, 'promo URLs must be validated');
assertPhase13Adv(
    strpos($validate, 'HomepageAdvertising') === false
        && strpos($orchestrator, 'HomepageAdvertising') === false
        && strpos($lifecycle, 'HomepageAdvertising') === false
        && strpos($mail, 'HomepageAdvertising') === false,
    'advertising must not couple into transactional lifecycle classes'
);
assertPhase13Adv(
    strpos($module, 'ModuleDataPurger') !== false,
    'uninstall uses ModuleDataPurger'
);
assertPhase13Adv(
    !is_file($root . '/src/Order/Phase11DeferredMailDispatcher.php'),
    'Phase11DeferredMailDispatcher removed as dead superseded code'
);
assertPhase13Adv(
    strpos((string) file_get_contents($root . '/controllers/front/orderbankstatus.php'), 'BankStatusOrderStateMapper') === false,
    'rejection sync remains dormant in callback'
);

fwrite(STDOUT, "OK (Phase 13 advertising isolation + dead-code)\n");
