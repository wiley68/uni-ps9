<?php

declare(strict_types=1);

/**
 * Deferred v2.0.2 documentation: do NOT broaden standard selection with promo in Phase 9.
 *
 * Current v2.0.1 audited behavior (Woo / PS8 / PS9 cart+checkout) uses CartSchemeResolver
 * intersection and CheckoutPaymentPresenter::unifiedSchemes as-is.
 * Coordinated fix across platforms is deferred to v2.0.2.
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
$docs = (string) file_get_contents($root . '/docs/ARCHITECTURE.md');

assertDeferred(strpos($presenter, 'unifiedSchemes') !== false, 'checkout still uses unifiedSchemes from cart resolver');
assertDeferred(
    !preg_match('/standardSchemes\s*=\s*array_merge\s*\(\s*\$resolution->standardSchemes\s*,\s*\$resolution->promoSchemes/', $presenter),
    'Phase 9 must not merge promo into standard lists ad-hoc'
);
assertDeferred(strpos($docs, 'v2.0.2') !== false, 'deferred v2.0.2 must be documented in ARCHITECTURE');

fwrite(STDOUT, "OK (Phase 9 deferred v2.0.2 standard/promo documentation)\n");
