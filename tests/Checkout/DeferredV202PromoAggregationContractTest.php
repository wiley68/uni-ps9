<?php

declare(strict_types=1);

/**
 * v2.0.2 documentation: Checkout continues to use CartSchemeResolver::unifiedSchemes
 * (now with presentation sort + ambiguity exclusion). Do not invent ad-hoc merges.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertDeferred(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$presenter = (string) file_get_contents($root . '/src/Checkout/CheckoutPaymentPresenter.php');
$resolver = (string) file_get_contents($root . '/src/Cart/CartSchemeResolver.php');

assertDeferred(strpos($presenter, 'unifiedSchemes') !== false, 'checkout still uses unifiedSchemes from cart resolver');
assertDeferred(
    !preg_match('/standardSchemes\s*=\s*array_merge\s*\(\s*\$resolution->standardSchemes\s*,\s*\$resolution->promoSchemes/', $presenter),
    'must not merge promo into standard lists ad-hoc in presenter'
);
assertDeferred(
    strpos($resolver, 'SchemePresentationCategory::sort') !== false,
    'v2.0.2 unified/intersect schemes use presentation sort'
);
assertDeferred(
    strpos($resolver, 'firstInstallmentAmbiguous') !== false,
    'v2.0.2 excludes ambiguous first-installment schemes from calculable membership'
);

fwrite(STDOUT, "OK (v2.0.2 unifiedSchemes presentation contract)\n");
