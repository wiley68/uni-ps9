<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertProductVisualContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$template = (string) file_get_contents($root . '/views/templates/hook/product_calculator.tpl');
$css = (string) file_get_contents($root . '/views/css/product-calculator.css');
$javascript = (string) file_get_contents($root . '/views/js/product-calculator.js');
$controller = (string) file_get_contents($root . '/controllers/front/productcalculator.php');

assertProductVisualContract(strpos($template, 'margin-top: {$unipayment_button_top_spacing|intval}px') !== false, 'local top spacing must be rendered as integer px');
assertProductVisualContract(strpos($template, 'data-unipayment-logo') !== false, 'standard button must render the official logo');
assertProductVisualContract(strpos($template, '>0%</span>') !== false, 'promo button must retain the textual 0% distinction');
assertProductVisualContract(strpos($template, 'data-unipayment-preferred-price') !== false, 'server-presented preferred installment must remain in the button');
assertProductVisualContract(strpos($template, '.installment_label|escape') !== false, 'initial button label must use the server-side Woo format');
assertProductVisualContract(strpos($css, 'RobotoCondensed-Regular-Cyrillic.woff2') !== false, 'local Cyrillic WOFF2 must be registered');
assertProductVisualContract(strpos($css, '[data-unipayment-calculator] button.unipayment-product-calculator__button') !== false, 'button selectors must be module-scoped');
assertProductVisualContract(strpos($css, '@container unipayment-product-buttons') !== false, 'narrow containers must use the Woo responsive behavior');
assertProductVisualContract(strpos($javascript, 'applyVisualConfig(root, next)') !== false, 'AJAX refresh must reapply presenter visual configuration');
assertProductVisualContract(strpos($javascript, 'price.textContent = buttonInstallmentLabel(offer)') !== false, 'AJAX refresh must reuse the presenter-derived Woo label');
assertProductVisualContract(strpos($javascript, "formatAmount(offer.monthly_installment") === false, 'AJAX button label must not use locale currency formatting');
assertProductVisualContract(strpos($controller, "'calculator' => \$calculator") !== false, 'AJAX response must return the complete presenter result');
assertProductVisualContract(is_file($root . '/views/fonts/roboto-condensed/LICENSE-Roboto-Condensed.txt'), 'font license must be included');
assertProductVisualContract(is_file($root . '/views/img/product/uni_logo.svg'), 'standard logo asset must be local');
assertProductVisualContract(is_file($root . '/views/img/product/uni_logo_red.svg'), 'alternative logo asset must be local');

preg_match_all('/([^{}]*button\.unipayment-product-calculator__button:hover)\s*\{([^}]+)\}/', $css, $hoverRules, PREG_SET_ORDER);
assertProductVisualContract(count($hoverRules) === 2, 'standard and alternative button hover rules must exist separately');
foreach ($hoverRules as $hoverRule) {
    assertProductVisualContract(strpos($hoverRule[1], '[data-unipayment-calculator]') !== false, 'hover selector must remain module-scoped');
    assertProductVisualContract(!preg_match('/border(?:-color|-width|-style)?\s*:/', $hoverRule[2]), 'hover must not change the button border');
    assertProductVisualContract(!preg_match('/(?:box-shadow|text-shadow|transform|filter|opacity|outline)\s*:/', $hoverRule[2]), 'hover must not add geometry, shadow, filter, opacity or outline effects');
    preg_match_all('/(?:^|;)\s*([a-z-]+)\s*:/m', trim($hoverRule[2]), $hoverProperties);
    assertProductVisualContract($hoverProperties[1] === ['background'], 'hover may change only the background property');
}
assertProductVisualContract(strpos($css, 'button.unipayment-product-calculator__button:hover,') === false, 'mouse hover must not share a selector with keyboard focus');
assertProductVisualContract((bool) preg_match('/button\.unipayment-product-calculator__button:focus-visible\s*\{[^}]*box-shadow:/s', $css), 'keyboard focus-visible must retain a separate visible indicator');

preg_match('/button\.unipayment-product-calculator__button \.unipayment-product-calculator__button-title\s*\{([^}]+)\}/', $css, $titleRule);
assertProductVisualContract(isset($titleRule[1]), 'main red text rule must exist');
assertProductVisualContract(!preg_match('/(?:text-shadow|filter|opacity|transform|text-stroke|-webkit-text-stroke)\s*:/', $titleRule[1]), 'main red text must not have blur, shadow, opacity, transform or stroke effects');
assertProductVisualContract(strpos($css, 'button.unipayment-product-calculator__button::before') === false && strpos($css, 'button.unipayment-product-calculator__button::after') === false, 'button must not have pseudo-element glow overlays');
assertProductVisualContract(strpos($css, 'background: var(--unipayment-red-hover-bg) none') !== false, 'standard hover must use the Woo background');
assertProductVisualContract(strpos($css, 'background: #d9261f none') !== false, 'alternative hover must use the Woo background');
assertProductVisualContract(strpos($css, 'background: #fff none') !== false, 'standard design color must remain intact');
assertProductVisualContract(strpos($css, 'background: var(--unipayment-red) none') !== false, 'alternative design color must remain intact');

fwrite(STDOUT, "OK (Product button visual contract)\n");
