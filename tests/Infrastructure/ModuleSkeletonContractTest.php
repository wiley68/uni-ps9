<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertModuleSkeleton(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$modulePath = $root . '/unipayment.php';
$composerPath = $root . '/composer.json';

assertModuleSkeleton(is_file($modulePath), 'unipayment.php must exist');
assertModuleSkeleton(is_file($composerPath), 'composer.json must exist');

$module = (string) file_get_contents($modulePath);
$composerJson = (string) file_get_contents($composerPath);
$composer = json_decode($composerJson, true);
assertModuleSkeleton(is_array($composer), 'composer.json must be valid JSON');

assertModuleSkeleton(
    (bool) preg_match('/\bclass\s+Unipayment\s+extends\s+PaymentModule\b/', $module),
    'module class must extend PaymentModule'
);
assertModuleSkeleton(
    (bool) preg_match('/\$this->name\s*=\s*[\'"]unipayment[\'"]/', $module),
    'technical name must be unipayment'
);
assertModuleSkeleton(
    (bool) preg_match('/\$this->version\s*=\s*[\'"][^\'"]+[\'"]/', $module),
    'module version must be present'
);
assertModuleSkeleton(
    (bool) preg_match('/[\'"]min[\'"]\s*=>\s*[\'"]9\.0\.0[\'"]/', $module),
    'PrestaShop minimum version must be 9.0.0'
);

assertModuleSkeleton(
    (bool) preg_match('/\$this->ps_versions_compliancy\s*=\s*\[(.*?)\]/s', $module, $compliancyMatch),
    'ps_versions_compliancy must be declared'
);
$compliancyBlock = $compliancyMatch[1];
assertModuleSkeleton(
    !str_contains($compliancyBlock, '_PS_VERSION_'),
    'ps_versions_compliancy max must not use _PS_VERSION_'
);
assertModuleSkeleton(
    (bool) preg_match('/[\'"]max[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/', $compliancyBlock, $maxMatch),
    'ps_versions_compliancy max must be an explicit version string'
);
assertModuleSkeleton(
    version_compare($maxMatch[1], '10.0.0', '<'),
    'ps_versions_compliancy max must not imply PrestaShop 10'
);

$psr4 = $composer['autoload']['psr-4'] ?? null;
assertModuleSkeleton(is_array($psr4), 'composer autoload psr-4 must be defined');
assertModuleSkeleton(
    ($psr4['PrestaShop\\Module\\Unipayment\\'] ?? null) === 'src/',
    'Composer namespace must map PrestaShop\\Module\\Unipayment\\ to src/'
);

assertModuleSkeleton(!is_dir($root . '/override'), 'override/ directory must not exist');

$frontControllers = glob($root . '/controllers/front/*.php') ?: [];
$allowedFront = [
    'index.php' => true,
    'shopcache.php' => true,
    'orderbankstatus.php' => true,
    'smartucfdebuglog.php' => true,
    'productcalculator.php' => true,
    'productpopup.php' => true,
    'cartcalculator.php' => true,
    'cartpopup.php' => true,
    'checkoutcalculate.php' => true,
    'validatecheckout.php' => true,
];
foreach ($frontControllers as $file) {
    $base = basename($file);
    assertModuleSkeleton(
        isset($allowedFront[$base]),
        'unexpected front controller: ' . $base
    );
}
assertModuleSkeleton(
    is_file($root . '/controllers/front/shopcache.php')
        && is_file($root . '/controllers/front/orderbankstatus.php')
        && is_file($root . '/controllers/front/smartucfdebuglog.php')
        && is_file($root . '/controllers/front/productcalculator.php')
        && is_file($root . '/controllers/front/productpopup.php')
        && is_file($root . '/controllers/front/cartcalculator.php')
        && is_file($root . '/controllers/front/cartpopup.php')
        && is_file($root . '/controllers/front/checkoutcalculate.php')
        && is_file($root . '/controllers/front/validatecheckout.php'),
    'Phase 4 inbound + Phase 6–9 product/cart/checkout controllers must exist'
);

assertModuleSkeleton(
    is_file($root . '/views/js/product-calculator.js')
        && is_file($root . '/views/css/product-calculator.css'),
    'Phase 6 product assets must exist'
);
assertModuleSkeleton(
    is_file($root . '/views/js/cart-calculator.js')
        && is_file($root . '/views/css/cart-calculator.css')
        && is_file($root . '/views/js/checkout-payment.js')
        && is_file($root . '/views/css/checkout-payment.css')
        && is_file($root . '/views/js/homepage-advertising.js')
        && is_file($root . '/views/css/homepage-advertising.css'),
    'Phase 8–9 cart/checkout assets and Phase 13 homepage advertising assets must exist'
);

assertModuleSkeleton(
    !preg_match('/\bCREATE\s+TABLE\b/i', $module),
    'module entry must not embed CREATE TABLE SQL (install via repositories)'
);
assertModuleSkeleton(
    is_file($root . '/src/Configuration/ShopConfigurationCache.php'),
    'Phase 3 ShopConfigurationCache must exist'
);
assertModuleSkeleton(
    is_file($root . '/src/Calculator/Calculator.php'),
    'Phase 5 Calculator domain must exist'
);
assertModuleSkeleton(
    is_file($root . '/src/Product/ProductContextFactory.php'),
    'Phase 6 ProductContextFactory must exist'
);
assertModuleSkeleton(
    !preg_match('/\bnew\s+OrderState\b/', $module),
    'module entry must not instantiate OrderState directly'
);
assertModuleSkeleton(
    (bool) preg_match('/registerHook\s*\(\s*[\'"]displayProductAdditionalInfo[\'"]\s*\)/', $module),
    'Phase 6 must register displayProductAdditionalInfo'
);
assertModuleSkeleton(
    (bool) preg_match('/function\s+hookDisplayProductAdditionalInfo\b/', $module),
    'Phase 6 must define hookDisplayProductAdditionalInfo'
);
assertModuleSkeleton(
    (bool) preg_match('/registerHook\s*\(\s*[\'"]displayShoppingCart[\'"]\s*\)/', $module)
        && (bool) preg_match('/function\s+hookDisplayShoppingCart\b/', $module),
    'Phase 8 must register displayShoppingCart'
);
assertModuleSkeleton(
    (bool) preg_match('/registerHook\s*\(\s*[\'"]paymentOptions[\'"]\s*\)/', $module)
        && (bool) preg_match('/function\s+hookPaymentOptions\b/', $module),
    'Phase 9 must register paymentOptions'
);
assertModuleSkeleton(
    is_file($root . '/src/Product/PopupSubmissionRepository.php')
        && (bool) preg_match('/PopupSubmissionRepository/', $module),
    'Phase 7 popup submission persistence must be installed'
);
assertModuleSkeleton(
    (bool) preg_match('/OrderStateInstaller/', $module),
    'Phase 10 must install custom order states via OrderStateInstaller'
);
assertModuleSkeleton(
    (bool) preg_match('/CheckoutSubmitLockRepository/', $module)
        && (bool) preg_match('/OrderAttemptRepository/', $module)
        && (bool) preg_match('/FinancingSnapshotRepository/', $module),
    'Phase 10 must install checkout lock / order attempt / financing snapshot'
);
assertModuleSkeleton(
    is_file($root . '/src/Order/OrderOrchestrator.php')
        && is_file($root . '/src/Order/NativePrestaShopOrderGateway.php'),
    'Phase 10 order orchestration services must exist'
);
assertModuleSkeleton(
    is_file($root . '/views/templates/front/checkout_validated.tpl'),
    'Phase 10 post-order validated template must exist'
);
assertModuleSkeleton(
    is_file($root . '/src/Order/PostControlPanelLifecycleService.php')
        && is_file($root . '/src/SmartUcf/SmartUcfSessionCoordinator.php'),
    'Phase 11 post-CP lifecycle + SmartUCF coordinator must exist'
);
assertModuleSkeleton(
    (bool) preg_match('/PostControlPanelLifecycleService/', (string) file_get_contents($root . '/controllers/front/validatecheckout.php'))
        && (bool) preg_match('/SmartUcfSessionCoordinator/', (string) file_get_contents($root . '/controllers/front/validatecheckout.php')),
    'validatecheckout must wire Phase 11 lifecycle after CP create'
);
assertModuleSkeleton(
    is_file($root . '/src/Order/FinancingOrderMailDispatcher.php')
        && is_file($root . '/src/Order/LeasingEmailNotifier.php')
        && is_file($root . '/src/Order/OrderLeasingDetailsPresenter.php')
        && is_file($root . '/views/templates/hook/admin_order_financing_details.tpl')
        && is_file($root . '/views/templates/hook/order_confirmation_leasing.tpl'),
    'Phase 12 mail dispatcher + BO/confirmation presenters/templates must exist'
);
assertModuleSkeleton(
    (bool) preg_match('/registerHook\s*\(\s*[\'"]displayPaymentReturn[\'"]\s*\)/', $module)
        && (bool) preg_match('/registerHook\s*\(\s*[\'"]displayAdminOrderMainBottom[\'"]\s*\)/', $module)
        && (bool) preg_match('/registerHook\s*\(\s*[\'"]sendMailAlterTemplateVars[\'"]\s*\)/', $module),
    'Phase 12 must register confirmation + admin order + mail alter hooks'
);
assertModuleSkeleton(
    (bool) preg_match('/registerHook\s*\(\s*[\'"]displayFooter[\'"]\s*\)/', $module)
        && (bool) preg_match('/function\s+hookDisplayFooter\b/', $module)
        && is_file($root . '/src/Advertising/HomepageAdvertisingGate.php')
        && is_file($root . '/src/Advertising/HomepageAdvertisingPresenter.php')
        && is_file($root . '/views/templates/hook/homepage_advertising.tpl'),
    'Phase 13 must register homepage advertising via displayFooter'
);
assertModuleSkeleton(
    is_file($root . '/src/Uninstall/ModuleDataPurger.php')
        && (bool) preg_match('/ModuleDataPurger/', $module),
    'Phase 13 uninstall must use ModuleDataPurger'
);
assertModuleSkeleton(
    !is_file($root . '/src/Order/Phase11DeferredMailDispatcher.php'),
    'superseded Phase11DeferredMailDispatcher must be removed'
);

fwrite(STDOUT, "OK (module skeleton Phase 13 contract)\n");
