<?php

declare(strict_types=1);

/**
 * Optional Woo reference parity.
 *
 * The Woo plugin currently splits helpers across multiple includes; incomplete
 * bootstrap must not fail the Phase 5 safe suite. Prefer Ps8ParityHarnessTest
 * for PrestaShop domain parity.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('ABSPATH', '/tmp/');
define('MTUC_SCHEME_MONTH_MIN', 3);
define('MTUC_SCHEME_MONTH_MAX', 36);

function __(string $text, ?string $domain = null): string
{
    return $text;
}

function current_time(string $format): string
{
    return $format === 'Y-m-d' ? '2026-08-17' : gmdate($format);
}

class WC_Product
{
    /** @var int */
    private $id;

    /** @var list<int> */
    private $categories;

    /** @var string */
    private $type;

    /** @param list<int> $categories */
    public function __construct(int $id, array $categories, string $type = 'simple')
    {
        $this->id = $id;
        $this->categories = $categories;
        $this->type = $type;
    }

    public function get_id(): int
    {
        return $this->id;
    }

    /** @return list<int> */
    public function get_category_ids(): array
    {
        return $this->categories;
    }

    public function get_type(): string
    {
        return $this->type;
    }

    /** @param string|list<string> $type */
    public function is_type($type): bool
    {
        if (is_array($type)) {
            return in_array($this->type, $type, true);
        }

        return $this->type === $type;
    }

    public function get_parent_id(): int
    {
        return 0;
    }
}

$wooRoot = '/var/www/woo.avalonbg.com/wp-content/plugins/mtunicredit/includes';
$required = [
    $wooRoot . '/mtuc-financing-calculator.php',
    $wooRoot . '/mtuc-product-offer-selection.php',
    $wooRoot . '/functions.php',
];
foreach ($required as $file) {
    if (!is_file($file)) {
        fwrite(STDOUT, "SKIP (Woo reference file missing: {$file})\n");
        exit(0);
    }
}

foreach ($required as $file) {
    require_once $file;
}

if (
    !function_exists('mtuc_calculate_gpr')
    || !function_exists('mtuc_build_button_offer')
    || !function_exists('mtuc_resolve_standard_button_offer')
    || !function_exists('mtuc_resolve_promo_button_offer')
) {
    fwrite(STDOUT, "SKIP (Woo calculator helpers incomplete without full plugin bootstrap)\n");
    exit(0);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/fixtures.php';

use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\ProductContext;

function assertParity(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$shop = calculatorFixture();
$price = 1000.0;

$stubSimple = new WC_Product(1, [], 'simple');
assertParity($stubSimple->is_type('simple'), 'WC_Product stub: simple product is_type(simple)');
assertParity(!$stubSimple->is_type('variable'), 'WC_Product stub: simple product is not variable');
assertParity($stubSimple->is_type(['simple', 'variable']), 'WC_Product stub: simple product is_type([simple, variable])');

$wooProduct = new WC_Product(42, [7, 9], 'simple');
$calculator = new Calculator('2026-08-17');
$product = new ProductContext(42, [7, 9], $price);

try {
    $wooStandard = mtuc_resolve_standard_button_offer($shop, $shop['coeff_list'], $price, $wooProduct);
    $wooPromo = mtuc_resolve_promo_button_offer($shop, $shop['coeff_list'], $price, $wooProduct);
} catch (Throwable $exception) {
    fwrite(STDOUT, 'SKIP (Woo helpers threw during resolve: ' . $exception->getMessage() . ")\n");
    exit(0);
}

$domain = $calculator->resolvePreferredOffers($shop, $product);
foreach ([['woo' => $wooStandard, 'domain' => $domain['standard']], ['woo' => $wooPromo, 'domain' => $domain['promo']]] as $pair) {
    assertParity(is_array($pair['woo']) && $pair['domain'] !== null, 'offer pair present');
    $actual = $pair['domain']->toArray();
    foreach (['type', 'kop_code', 'installment_count', 'monthly_installment', 'glp', 'gpr', 'total_amount', 'kimb'] as $key) {
        $left = $pair['woo'][$key];
        $right = $actual[$key];
        if (is_numeric($left) && is_numeric($right)) {
            assertParity((float) $left === (float) $right, "default parity mismatch for {$key}");
        } else {
            assertParity($left === $right, "default parity mismatch for {$key}");
        }
    }
}

$schema = calculatorFixture(['uni_typekop' => 1, 'kop' => ['by_schema' => ['filters' => schemaFiltersFixture()]]]);
try {
    $wooSchema = mtuc_resolve_standard_button_offer($schema, $schema['coeff_list'], $price, $wooProduct);
} catch (Throwable $exception) {
    fwrite(STDOUT, 'SKIP (Woo schema resolve threw: ' . $exception->getMessage() . ")\n");
    exit(0);
}
$domainSchema = $calculator->resolvePreferredOffers($schema, $product)['standard']->toArray();
foreach (['type', 'kop_code', 'installment_count', 'monthly_installment', 'glp', 'gpr', 'total_amount', 'kimb'] as $key) {
    $left = $wooSchema[$key];
    $right = $domainSchema[$key];
    if (is_numeric($left) && is_numeric($right)) {
        assertParity((float) $left === (float) $right, "schema parity mismatch for {$key}");
    } else {
        assertParity($left === $right, "schema parity mismatch for {$key}");
    }
}

fwrite(STDOUT, "OK (Phase 5 parity with Woo reference helpers)\n");
