<?php

declare(strict_types=1);

/**
 * Operation-level idempotency for silent Product Buy cart mutation.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Product\ProductPopupCheckoutPreselectionException;
use PrestaShop\Module\Unipayment\Product\ProductPopupPreselectOperationGuard;

function assertPreselectGuard(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function operationToken(string $suffix = ''): string
{
    return hash('sha256', 'preselect-guard-test' . $suffix);
}

$guard = new ProductPopupPreselectOperationGuard();

try {
    $guard->validateOperationToken('');
    assertPreselectGuard(false, 'empty token must be rejected');
} catch (ProductPopupCheckoutPreselectionException $exception) {
    assertPreselectGuard(true, 'empty token rejected');
}

try {
    $guard->validateOperationToken('not-hex');
    assertPreselectGuard(false, 'non-hex token must be rejected');
} catch (ProductPopupCheckoutPreselectionException $exception) {
    assertPreselectGuard(true, 'non-hex token rejected');
}

$guard->validateOperationToken(operationToken('valid'));

$appliedT1 = [
    'token' => operationToken('t1'),
    'cart_id' => 100,
    'product_id' => 42,
    'product_attribute_id' => 7,
    'line_qty_after' => 2,
];

assertPreselectGuard(
    !$guard->shouldSkipCartMutation(null, operationToken('t1'), 100, 42, 7, 0),
    'Test A: first operation must mutate cart'
);
assertPreselectGuard(
    $guard->shouldSkipCartMutation($appliedT1, operationToken('t1'), 100, 42, 7, 2),
    'Test B: same operation token retry must skip duplicate mutation'
);
assertPreselectGuard(
    !$guard->shouldSkipCartMutation($appliedT1, operationToken('t1'), 100, 42, 7, 1),
    'Test B: retry must not skip when cart quantity dropped below applied snapshot'
);
assertPreselectGuard(
    !$guard->shouldSkipCartMutation($appliedT1, operationToken('t2'), 100, 42, 7, 0),
    'Test C: new operation token must mutate cart after product removal'
);
assertPreselectGuard(
    !$guard->shouldSkipCartMutation($appliedT1, operationToken('t1'), 101, 42, 7, 0),
    'Test E: applied marker must be scoped to cart id'
);

$appliedCombination = [
    'token' => operationToken('combo-t1'),
    'cart_id' => 100,
    'product_id' => 42,
    'product_attribute_id' => 9,
    'line_qty_after' => 1,
];
assertPreselectGuard(
    !$guard->shouldSkipCartMutation($appliedCombination, operationToken('combo-t1'), 100, 42, 7, 1),
    'Test G: applied marker must be scoped to product attribute id'
);

fwrite(STDOUT, "OK (Product popup preselect operation guard)\n");
