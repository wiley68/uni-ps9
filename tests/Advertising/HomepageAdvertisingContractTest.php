<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertAdvertisingContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$module = (string) file_get_contents($root . '/unipayment.php');
$template = (string) file_get_contents($root . '/views/templates/hook/homepage_advertising.tpl');
$javascript = (string) file_get_contents($root . '/views/js/homepage-advertising.js');
$css = (string) file_get_contents($root . '/views/css/homepage-advertising.css');
$gate = (string) file_get_contents($root . '/src/Advertising/HomepageAdvertisingGate.php');
$presenter = (string) file_get_contents($root . '/src/Advertising/HomepageAdvertisingPresenter.php');

assertAdvertisingContract(strpos($module, 'hookDisplayFooter') !== false, 'homepage advertising must hook footer like Woo wp_footer');
assertAdvertisingContract(strpos($module, "'displayFooter'") !== false, 'displayFooter must be registered');
assertAdvertisingContract(strpos($module, 'HomepageAdvertisingGate') !== false, 'homepage media/footer must consult the permission gate');
assertAdvertisingContract(strpos($module, "php_self === 'index'") !== false, 'assets must be scoped to the homepage');
assertAdvertisingContract(strpos($module, 'homepage-advertising.css') !== false, 'homepage advertising CSS missing');
assertAdvertisingContract(strpos($module, 'homepage-advertising.js') !== false, 'homepage advertising JS missing');
assertAdvertisingContract(strpos($module, 'product-calculator.js') !== false, 'product calculator JS must remain product-scoped');
assertAdvertisingContract(
    strpos($module, "php_self !== 'product'") !== false,
    'product calculator assets must stay gated to the product page'
);

assertAdvertisingContract(strpos($gate, 'allowsAssets') !== false, 'asset gate method missing');
assertAdvertisingContract(strpos($gate, 'uni_container_status') !== false, 'CP container flag missing from shop gate');
assertAdvertisingContract(strpos($presenter, "['uni_picturem']") !== false, 'presenter must consume CP uni_picturem R2 URL');
assertAdvertisingContract(strpos($presenter, "['uni_backurl']") !== false, 'presenter must consume CP uni_backurl');
assertAdvertisingContract(strpos($presenter, 'FILTER_VALIDATE_URL') !== false, 'external URLs must be validated');

assertAdvertisingContract(strpos($template, 'data-unipayment-advertising') !== false, 'root advertising marker missing');
assertAdvertisingContract(strpos($template, 'unipayment_advertising.is_mobile') !== false, 'mobile/desktop split missing');
assertAdvertisingContract(strpos($template, 'float_image_url') !== false, 'float image must come from presenter');
assertAdvertisingContract(strpos($template, 'picture_url') !== false, 'panel picture must come from CP');
assertAdvertisingContract(strpos($template, "s='ИНФОРМАЦИЯ ЗА ОНЛАЙН ПАЗАРУВАНЕ НА КРЕДИТ'") !== false, 'Woo panel link wording missing');
assertAdvertisingContract(strpos($template, 'target="_blank" rel="noopener noreferrer"') !== false, 'external info link must be safe');
assertAdvertisingContract(strpos($template, 'onclick=') === false, 'template must not use inline onclick');

assertAdvertisingContract(strpos($javascript, 'data-unipayment-advertising-toggle') !== false, 'desktop toggle behavior missing');
assertAdvertisingContract(strpos($javascript, 'window.open') !== false, 'mobile must open the CP back URL');
assertAdvertisingContract(strpos($javascript, 'noopener') !== false, 'window.open must use noopener');

assertAdvertisingContract(strpos($css, '.unipayment-advertising') !== false, 'CSS must be module-scoped');
assertAdvertisingContract(strpos($css, 'position: fixed') !== false, 'float button must stay Woo-fixed on the left');

fwrite(STDOUT, "OK (Homepage advertising Woo/PS contract)\n");
