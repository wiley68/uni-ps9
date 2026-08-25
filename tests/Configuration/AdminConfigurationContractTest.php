<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertAdminConfiguration(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$templatePath = $root . '/views/templates/admin/configuration.tpl';
$modulePath = $root . '/unipayment.php';
$handlerPath = $root . '/src/Configuration/CredentialChangeSideEffectHandler.php';

assertAdminConfiguration(is_file($templatePath), 'configuration.tpl must exist');
assertAdminConfiguration(is_file($modulePath), 'unipayment.php must exist');

$template = (string) file_get_contents($templatePath);
$module = (string) file_get_contents($modulePath);
$handler = (string) file_get_contents($handlerPath);

assertAdminConfiguration(
    strpos($template, 'UniPayment PrestaShop 9 module foundation is installed') === false
        && strpos($module, 'UniPayment PrestaShop 9 module foundation is installed') === false,
    'Phase 0 placeholder must be removed'
);

assertAdminConfiguration(
    strpos($module, "display(__FILE__, 'views/templates/admin/configuration.tpl')") !== false,
    'getContent must render the admin configuration template'
);

foreach (['UNIPAYMENT_ENABLED', 'UNIPAYMENT_UNICID', 'UNIPAYMENT_SECRET', 'UNIPAYMENT_ADVERTISING_ENABLED', 'UNIPAYMENT_DEBUG_ENABLED', 'UNIPAYMENT_PRODUCT_BUTTON_ACTION', 'UNIPAYMENT_BUTTON_TOP_SPACING'] as $field) {
    assertAdminConfiguration(strpos($template, 'name="' . $field . '"') !== false, "admin setting {$field} is missing");
}

assertAdminConfiguration(
    strpos($template, 'name="UNIPAYMENT_SECRET" id="UNIPAYMENT_SECRET" value=""') !== false,
    'secret input must not render a stored secret value'
);
assertAdminConfiguration(
    strpos($module, "'unipayment_secret'") === false
        && strpos($module, '"unipayment_secret"') === false
        && strpos($module, 'getAccessToken()') === false
        && strpos($template, 'access_token') === false
        && strpos($template, 'UNIPAYMENT_CP_ACCESS_TOKEN') === false,
    'module/template must not expose secret or access token'
);
assertAdminConfiguration(strpos($template, 'unipayment_has_secret') !== false, 'existing-secret indication must be present');

assertAdminConfiguration(strpos($template, 'name="submitUnipaymentConfiguration"') !== false, 'save action is missing');
assertAdminConfiguration(
    strpos($template, 'name="submitUnipaymentRefresh"') !== false
        && strpos($template, 'disabled="disabled"') !== false
        && strpos($template, 'Phase 3') !== false,
    'bank-data refresh must remain disabled until Phase 3 shop cache'
);
assertAdminConfiguration(strpos($module, 'unipayment_cp_actions_available') !== false, 'CP action availability flag missing');
assertAdminConfiguration(
    (bool) preg_match('/unipayment_cp_actions_available[\'"]\s*=>\s*false/', $module),
    'CP-dependent BO actions must stay unavailable in Phase 2'
);

assertAdminConfiguration(strpos($module, 'createControlPanelClient') !== false, 'ControlPanelClient factory must exist');
assertAdminConfiguration(strpos($module, 'getControlPanelClient') !== false, 'getControlPanelClient must exist');
assertAdminConfiguration(strpos($module, 'ShopConfigurationService') === false, 'ShopConfigurationService must not be introduced yet');
assertAdminConfiguration(strpos($module, 'ShopConfigurationCache') === false, 'ShopConfigurationCache must not be introduced yet');
assertAdminConfiguration(strpos($module, 'SmartUcfDiagnosticJournal') === false, 'SmartUCF journal must not be introduced yet');

assertAdminConfiguration(strpos($module, '$credentialsChanged =') !== false, 'credential-change detection was removed');
assertAdminConfiguration(
    strpos($module, 'CredentialChangeSideEffectHandler') !== false,
    'credential-change invalidation boundary is missing'
);
assertAdminConfiguration(
    strpos($handler, 'TokenRepository') !== false
        && strpos($handler, 'invalidate()') !== false,
    'credential change must invalidate tokens in Phase 2'
);
assertAdminConfiguration(
    strpos($handler, 'ShopConfigurationCache') === false
        && strpos($handler, 'invalidate()') !== false,
    'credential change must invalidate tokens and must not clear shop cache yet'
);

assertAdminConfiguration(!preg_match('/\bregisterHook\s*\(/', $module), 'no functional hooks may be registered in Phase 2');
assertAdminConfiguration(!preg_match('/\bfunction\s+hook[A-Z]\w*/', $module), 'no functional hook handlers may exist in Phase 2');
assertAdminConfiguration(!preg_match('/\bCREATE\s+TABLE\b/i', $module), 'no module table installation in Phase 2');
assertAdminConfiguration(!preg_match('/\bnew\s+OrderState\b|\bOrderStateInstaller\b/', $module), 'no custom order states in Phase 2');
assertAdminConfiguration(
    !is_dir($root . '/controllers/front') || count(array_diff(scandir($root . '/controllers/front') ?: [], ['.', '..', 'index.php'])) === 0,
    'no inbound front controllers in Phase 2'
);

fwrite(STDOUT, "OK (admin configuration Phase 2 contract)\n");
