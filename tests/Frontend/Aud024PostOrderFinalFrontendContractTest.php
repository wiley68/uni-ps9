<?php

declare(strict_types=1);

/**
 * AUD-024: Cart post-order final UI must not reset on updatedCart / null calculator.
 *
 * Frontend state/lifecycle only — no backend durability changes.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertAud024(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$jsProduct = (string) file_get_contents($root . '/views/js/product-calculator.js');
$jsCart = (string) file_get_contents($root . '/views/js/cart-calculator.js');

// Explicit final-result lock (prefer state over DOM inference).
assertAud024(strpos($jsProduct, 'postOrderFinal') !== false, 'postOrderFinal state variable');
assertAud024(strpos($jsProduct, 'function markPostOrderFinal') !== false, 'markPostOrderFinal helper');
assertAud024(strpos($jsProduct, 'function clearPostOrderFinal') !== false, 'clearPostOrderFinal helper');
assertAud024(strpos($jsProduct, 'root.unipaymentPostOrderFinal') !== false, 'expose lock on root for cart refresh');

// Lock on post-order failure / confirmation envelopes.
assertAud024(
    (bool) preg_match(
        '/cp_error[\s\S]{0,400}markPostOrderFinal\(\)/',
        $jsProduct
    ),
    'mark lock after cp_error / smartucf_error Step 3'
);
assertAud024(
    (bool) preg_match(
        '/showOrderConfirmation\([\s\S]{0,200}setStep\(3\);\s*markPostOrderFinal\(\)/',
        $jsProduct
    ),
    'mark lock after Process 2 confirmation Step 3'
);
assertAud024(
    (bool) preg_match(
        '/step === "outcome_unknown"[\s\S]{0,900}markPostOrderFinal\(\)/',
        $jsProduct
    ),
    'mark lock on outcome_unknown final when applicable'
);

// unipaymentUpdate must not close/reset while locked (A, B, F).
assertAud024(
    (bool) preg_match(
        '/unipaymentUpdate\s*=\s*function\s*\(\s*next\s*\)\s*\{[\s\S]{0,200}postOrderFinal\s*\|\|\s*redirectPending/',
        $jsProduct
    ),
    'unipaymentUpdate early-return when postOrderFinal or redirectPending'
);
assertAud024(
    (bool) preg_match(
        '/postOrderFinal\s*\|\|\s*redirectPending[\s\S]{0,80}return;/',
        $jsProduct
    ),
    'locked update returns before close()/reset'
);

// Invalidate must not erase final result (A, B).
assertAud024(
    (bool) preg_match(
        '/unipaymentInvalidatePopup\s*=\s*function[\s\S]{0,120}postOrderFinal\s*\|\|\s*redirectPending/',
        $jsProduct
    ),
    'invalidate skipped while postOrderFinal or redirectPending'
);

// Cart updatedCart refresh respects lock (A, B) — pre-order path still invalidates (C, D).
assertAud024(strpos($jsCart, 'unipaymentPostOrderFinal') !== false, 'cart refresh checks unipaymentPostOrderFinal');
assertAud024(
    (bool) preg_match(
        '/function\s+refresh\s*\(\s*\)\s*\{[\s\S]{0,350}unipaymentPostOrderFinal[\s\S]{0,80}return;/',
        $jsCart
    ),
    'refresh returns early when post-order final locked'
);
assertAud024(strpos($jsCart, 'unipaymentInvalidatePopup') !== false, 'pre-order invalidate path retained');
assertAud024(strpos($jsCart, 'updatedCart') !== false, 'updatedCart listener retained');

// Manual close clears lock via resetPopup (E).
assertAud024(
    (bool) preg_match(
        '/function\s+resetPopup\s*\(\s*\)\s*\{[\s\S]{0,800}clearPostOrderFinal\(\)/',
        $jsProduct
    ),
    'resetPopup clears postOrderFinal (manual close / reopen)'
);
assertAud024(
    strpos($jsProduct, 'function close()') !== false
        && (bool) preg_match(
            '/function\s+close\s*\(\s*\)\s*\{[\s\S]{0,500}resetPopup\(\)/',
            $jsProduct
        ),
    'close() still calls resetPopup for explicit customer close'
);

// Success redirect path preserved (F).
assertAud024(strpos($jsProduct, 'redirectPending') !== false, 'redirectPending retained');
assertAud024(strpos($jsProduct, 'redirect_url') !== false, 'Process 1 redirect_url assign retained');

// Accessibility / modal semantics not removed (AUD-017).
assertAud024(strpos($jsProduct, 'aria-modal') !== false || strpos($jsProduct, 'role="dialog"') !== false
    || strpos($jsProduct, "role='dialog'") !== false
    || (bool) preg_match('/setAttribute\(\s*["\']role["\']\s*,\s*["\']dialog["\']/', $jsProduct),
    'dialog semantics retained');
assertAud024(strpos($jsProduct, 'inert') !== false || strpos($jsProduct, 'aria-hidden') !== false, 'inert/aria-hidden retained');

fwrite(STDOUT, "OK (AUD-024 post-order final frontend contract)\n");
