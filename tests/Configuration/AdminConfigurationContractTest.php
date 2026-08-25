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
    strpos($template, 'name="UNIPAYMENT_SECRET" id="UNIPAYMENT_SECRET" value=""') !== false,
    'secret input must not render a stored secret value'
);
assertAdminConfiguration(
    strpos($module, "'unipayment_secret'") === false
        && strpos($template, 'access_token') === false
        && strpos($template, 'shop_data') === false,
    'module/template must not expose secret, token, or raw snapshot'
);

assertAdminConfiguration(strpos($template, 'name="submitUnipaymentConfiguration"') !== false, 'save action missing');
assertAdminConfiguration(
    strpos($module, 'handleBankDataRefresh') !== false
        && strpos($template, 'name="submitUnipaymentRefresh"') !== false
        && strpos($template, 'unipayment_bank_refresh_available') !== false,
    'bank-data refresh must be wired in Phase 3'
);
assertAdminConfiguration(
    (bool) preg_match('/unipayment_bank_refresh_available[\'"]\s*=>\s*true/', $module),
    'bank refresh must be available'
);
assertAdminConfiguration(
    strpos($template, 'disabled="disabled"') !== false
        && strpos($template, 'submitUnipaymentDownloadJournal') !== false,
    'journal download remains deferred'
);

assertAdminConfiguration(strpos($module, 'ShopConfigurationService') !== false, 'ShopConfigurationService required');
assertAdminConfiguration(strpos($module, 'ShopConfigurationCache') !== false, 'ShopConfigurationCache required');
assertAdminConfiguration(strpos($module, 'createShopConfigurationService') !== false, 'service factory missing');
assertAdminConfiguration(
    strpos($module, 'SmartUcfDiagnosticJournal') === false
        && strpos($template, 'unipayment_journal_available') !== false
        && (bool) preg_match('/unipayment_journal_available[\'"]\s*=>\s*false/', $module),
    'BO journal download remains deferred in Phase 4'
);
$frontFiles = array_values(array_diff(scandir($root . '/controllers/front') ?: [], ['.', '..', 'index.php']));
sort($frontFiles);
assertAdminConfiguration(
    $frontFiles === ['orderbankstatus.php', 'productcalculator.php', 'productpopup.php', 'shopcache.php', 'smartucfdebuglog.php'],
    'Phase 6 front controllers must include inbound API + product calculator/popup'
);

assertAdminConfiguration(strpos($module, '$credentialsChanged =') !== false, 'credential-change detection missing');
assertAdminConfiguration(
    strpos($module, 'AdminConfigurationRequestReader') !== false
        && strpos($module, 'isBankRefreshSubmit') !== false
        && strpos($module, '!$configurationSubmitted') !== false,
    'Save must not also run bank refresh; refresh is POST-only and gated'
);
assertAdminConfiguration(
    (bool) preg_match(
        '/<form id="unipayment-settings-form"[\s\S]*name="UNIPAYMENT_SECRET"[\s\S]*name="submitUnipaymentConfiguration"[\s\S]*<\/form>/',
        $template
    ),
    'SECRET and Save must share the same settings form (DOM ownership contract)'
);
assertAdminConfiguration(
    strpos($template, 'autocomplete="off"') !== false
        || strpos($template, 'autocomplete="new-password"') !== false,
    'SECRET input must set autocomplete to reduce manager interference'
);

assertAdminConfiguration(
    strpos($handler, 'invalidate()') !== false
        && strpos($handler, 'clear()') !== false
        && strpos($handler, 'return $tokensInvalidated && $cacheCleared') !== false,
    'credential change must invalidate tokens, clear cache, and return combined success'
);
assertAdminConfiguration(
    strpos($module, 'sideEffectsApplied') !== false
        || (bool) preg_match('/onCredentialsChanged\(\);\s*\n\s*if\s*\(!\$/', $module),
    'BO save must not ignore credential side-effect failure'
);

assertAdminConfiguration(
    (bool) preg_match('/function\s+hookDisplayProductAdditionalInfo\b/', $module),
    'Phase 6 product hook handler must exist'
);
assertAdminConfiguration(
    !preg_match('/\bfunction\s+hookPaymentOptions\b/', $module)
        && !preg_match('/\bfunction\s+hookDisplayShoppingCart\b/', $module),
    'cart/checkout hooks remain Phase 8+'
);
assertAdminConfiguration(!preg_match('/\bnew\s+OrderState\b|\bOrderStateInstaller\b/', $module), 'no custom order states');
assertAdminConfiguration(
    is_dir($root . '/src/Calculator')
        && is_file($root . '/src/Calculator/Calculator.php'),
    'Phase 5 calculator domain must exist'
);
assertAdminConfiguration(
    is_file($root . '/src/Product/ProductContextFactory.php'),
    'Phase 6 ProductContextFactory must exist'
);

fwrite(STDOUT, "OK (admin configuration Phase 6 contract)\n");
