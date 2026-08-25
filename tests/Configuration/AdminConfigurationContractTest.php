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

assertAdminConfiguration(is_file($templatePath), 'configuration.tpl must exist');
assertAdminConfiguration(is_file($modulePath), 'unipayment.php must exist');

$template = (string) file_get_contents($templatePath);
$module = (string) file_get_contents($modulePath);

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
    (bool) preg_match('/name="UNIPAYMENT_SECRET"[^>]*value=""/', $template)
        || strpos($template, 'name="UNIPAYMENT_SECRET" id="UNIPAYMENT_SECRET" value=""') !== false,
    'secret input must not render a stored secret value'
);
assertAdminConfiguration(
    !preg_match('/\$this->context->smarty->assign\([^\)]*secret[^\)]*\)/i', $module)
        && strpos($module, "'unipayment_secret'") === false
        && strpos($module, '"unipayment_secret"') === false,
    'module must not assign secret plaintext to Smarty'
);
assertAdminConfiguration(strpos($template, 'unipayment_has_secret') !== false, 'existing-secret indication must be present');

assertAdminConfiguration(strpos($template, 'name="submitUnipaymentConfiguration"') !== false, 'save action is missing');
assertAdminConfiguration(strpos($template, 'name="submitUnipaymentRefresh"') !== false, 'refresh action control is missing');
assertAdminConfiguration(strpos($template, 'name="submitUnipaymentDownloadJournal"') !== false, 'journal action control is missing');
assertAdminConfiguration(
    strpos($template, 'disabled="disabled"') !== false
        && strpos($template, 'unipayment_cp_actions_available') !== false,
    'CP-dependent actions must be explicitly unavailable in Phase 1'
);

foreach (['ControlPanelClient', 'createControlPanelClient', 'ShopConfigurationService', 'TokenRepository', 'SmartUcfDiagnosticJournal'] as $forbidden) {
    assertAdminConfiguration(strpos($module, $forbidden) === false, "Phase 2+ dependency {$forbidden} must not be required");
}

assertAdminConfiguration(strpos($module, '$credentialsChanged =') !== false, 'credential-change detection was removed');
assertAdminConfiguration(
    strpos($module, 'CredentialChangeSideEffectHandler') !== false,
    'credential-change invalidation boundary is missing'
);
assertAdminConfiguration(
    strpos($module, '$tokens->invalidate()') === false
        && strpos($module, 'ShopConfigurationCache())->clear()') === false,
    'Phase 2/3 token/cache invalidation must not be faked'
);

assertAdminConfiguration(!preg_match('/\bregisterHook\s*\(/', $module), 'no functional hooks may be registered in Phase 1');
assertAdminConfiguration(!preg_match('/\bfunction\s+hook[A-Z]\w*/', $module), 'no functional hook handlers may exist in Phase 1');
assertAdminConfiguration(!preg_match('/\bCREATE\s+TABLE\b/i', $module), 'no module table installation in Phase 1');
assertAdminConfiguration(!preg_match('/\bnew\s+OrderState\b|\bOrderStateInstaller\b/', $module), 'no custom order states in Phase 1');

fwrite(STDOUT, "OK (admin configuration Phase 1 contract)\n");
