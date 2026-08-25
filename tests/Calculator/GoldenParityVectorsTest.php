<?php

declare(strict_types=1);

/**
 * Explicit Phase 5 golden vectors (oracle = current PS8 CalculatorDomainTest expectations).
 * Values are not derived from the PS9 implementation under test.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/fixtures.php';

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\FinancialCalculator;
use PrestaShop\Module\Unipayment\Calculator\PreferredOfferSelector;
use PrestaShop\Module\Unipayment\Calculator\Offer;
use PrestaShop\Module\Unipayment\Calculator\ProductContext;

function assertGolden(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$calculator = new Calculator('2026-08-17');
$shop = calculatorFixture();
$product = new ProductContext(42, [7, 9], 1000.0);

// Vector A — default preferred offers
$offers = $calculator->resolvePreferredOffers($shop, $product);
assertGolden($offers['standard'] !== null, 'A: standard present');
assertGolden($offers['standard']->kopCode === 'STD', 'A: standard KOP');
assertGolden($offers['standard']->months === 12, 'A: preferred months 12');
assertGolden($offers['standard']->monthlyInstallment === round(1000.0 * 0.095, 2), 'A: monthly = price * coeff');
assertGolden($offers['promo'] !== null && $offers['promo']->months === 12, 'A: promo preferred 12');
assertGolden($offers['promo']->monthlyInstallment === round(1000.0 * 0.083333, 2), 'A: promo monthly');

// Vector B — schema locked first installment (uni_parva=1, 24 months, filter 11)
$schema = calculatorFixture([
    'uni_typekop' => 1,
    'kop' => ['by_schema' => ['filters' => schemaFiltersFixture()]],
]);
$result = $calculator->calculate($schema, $product, 24, 'standard', 0, 11);
assertGolden($result->firstInstallment->amount === 41.67, 'B: first installment');
assertGolden($result->financedAmount === 958.33, 'B: financed');
assertGolden($result->monthlyInstallment === round(958.33 * 0.05, 2), 'B: monthly');
assertGolden($result->totalPayable === round($result->monthlyInstallment * 24, 2), 'B: total payable');

// Vector C — no match (category mismatch)
$noMatch = $calculator->availableSchemes($schema, new ProductContext(50, [8], 1000.0), 'standard');
assertGolden($noMatch === [], 'C: empty schemes, non-fatal');

// Vector D — multi-offer preferred selector tie-break (lowest monthly among preferred months)
$selector = new PreferredOfferSelector();
$candidates = [
    new Offer('standard', 'A', 12, 95.0, 10.0, 11.0, 1000.0, 0.095, 1),
    new Offer('standard', 'B', 12, 90.0, 10.0, 11.0, 1000.0, 0.09, 2),
    new Offer('standard', 'C', 24, 50.0, 10.0, 11.0, 1000.0, 0.05, 3),
];
$picked = $selector->select($candidates, 12);
assertGolden($picked !== null && $picked->kopCode === 'B', 'D: preferred month + lowest monthly');
$fallback = $selector->select($candidates, 18);
assertGolden($fallback !== null && $fallback->months === 24 && $fallback->kopCode === 'C', 'D: no preferred → highest months');

// Vector E — GPR rounding edge (FinancialCalculator)
$financial = new FinancialCalculator();
$gpr = $financial->calculateGpr(12, 95.0, 1000.0);
assertGolden(is_finite($gpr) && $gpr > 0, 'E: GPR finite positive');
assertGolden($financial->calculateGpr(0, 95.0, 1000.0) === 0.0, 'E: invalid periods → 0');

fwrite(STDOUT, "OK (Phase 5 golden parity vectors)\n");
