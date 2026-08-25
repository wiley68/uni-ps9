<?php

declare(strict_types=1);

/**
 * Development-only: compare PS9 Calculator output to PS8 Calculator for the same fixtures.
 * No runtime coupling — loads PS8 sources via a temporary class_alias-free require of files
 * under a unique prefix by shelling out to the PS8 autoload in a subprocess is avoided;
 * instead we instantiate PS8 classes from the read-only tree via Composer-less require
 * after renaming is impractical, so we compare PS9 results to frozen PS8 oracle values
 * AND optionally to a live PS8 Calculator when the PS8 module path is readable.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$ps8Root = '/var/www/presta8.avalonbg.com/modules/unipayment';
$ps8Autoload = $ps8Root . '/vendor/autoload.php';

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/fixtures.php';

use PrestaShop\Module\Unipayment\Calculator\Calculator as Ps9Calculator;
use PrestaShop\Module\Unipayment\Calculator\ProductContext;

function assertPs8Parity(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$shop = calculatorFixture();
$product = new ProductContext(42, [7, 9], 1000.0);
$ps9 = new Ps9Calculator('2026-08-17');
$ps9Offers = $ps9->resolvePreferredOffers($shop, $product);

// Frozen PS8 oracle fields (CalculatorDomainTest / OfferFactory rounding)
assertPs8Parity($ps9Offers['standard'] !== null, 'PS9 standard offer');
assertPs8Parity($ps9Offers['standard']->kopCode === 'STD', 'oracle kop STD');
assertPs8Parity($ps9Offers['standard']->months === 12, 'oracle months 12');
assertPs8Parity($ps9Offers['standard']->monthlyInstallment === 95.0, 'oracle monthly 95.00');
assertPs8Parity($ps9Offers['standard']->glp === 18.0, 'oracle glp 18');
assertPs8Parity($ps9Offers['standard']->financedAmount === 1000.0, 'oracle financed 1000');
assertPs8Parity($ps9Offers['standard']->coefficient === 0.095, 'oracle kimb 0.095');
assertPs8Parity($ps9Offers['promo'] !== null && $ps9Offers['promo']->months === 12, 'oracle promo months');
assertPs8Parity($ps9Offers['promo']->monthlyInstallment === round(1000.0 * 0.083333, 2), 'oracle promo monthly');

if (!is_file($ps8Autoload)) {
    fwrite(STDOUT, "OK (Phase 5 PS8 parity harness — frozen oracle; live PS8 autoload absent)\n");
    exit(0);
}

// Live cross-run: load PS8 Calculator in an isolated process to avoid class redeclaration.
$payload = [
    'shop' => $shop,
    'product' => ['id' => 42, 'categories' => [7, 9], 'price' => 1000.0],
    'today' => '2026-08-17',
];
$script = <<<'PHP'
<?php
require $argv[1];
use PrestaShop\Module\Unipayment\Calculator\Calculator;
use PrestaShop\Module\Unipayment\Calculator\ProductContext;
$payload = json_decode(file_get_contents('php://stdin'), true);
$calc = new Calculator($payload['today']);
$product = new ProductContext($payload['product']['id'], $payload['product']['categories'], $payload['product']['price']);
$offers = $calc->resolvePreferredOffers($payload['shop'], $product);
echo json_encode([
    'standard' => $offers['standard'] ? $offers['standard']->toArray() : null,
    'promo' => $offers['promo'] ? $offers['promo']->toArray() : null,
], JSON_THROW_ON_ERROR);
PHP;

$tmp = tempnam(sys_get_temp_dir(), 'unipayment-ps8-parity-');
file_put_contents($tmp, $script);
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' ' . escapeshellarg($ps8Autoload);
$proc = proc_open($cmd, $descriptors, $pipes);
assertPs8Parity(is_resource($proc), 'PS8 subprocess start');
fwrite($pipes[0], json_encode($payload, JSON_THROW_ON_ERROR));
fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($proc);
@unlink($tmp);
assertPs8Parity($code === 0, 'PS8 subprocess exit: ' . trim((string) $stderr));
$ps8Offers = json_decode((string) $stdout, true);
assertPs8Parity(is_array($ps8Offers), 'PS8 JSON decode');

foreach (['standard', 'promo'] as $type) {
    $a = $ps9Offers[$type]->toArray();
    $b = $ps8Offers[$type];
    assertPs8Parity(is_array($b), "PS8 {$type} present");
    foreach (['type', 'kop_code', 'installment_count', 'monthly_installment', 'glp', 'gpr', 'total_amount', 'kimb', 'filter_id'] as $key) {
        $left = $a[$key];
        $right = $b[$key];
        if (is_numeric($left) && is_numeric($right)) {
            assertPs8Parity((float) $left === (float) $right, "live PS8/PS9 mismatch {$type}.{$key}: ps9={$left} ps8={$right}");
        } else {
            assertPs8Parity($left === $right, "live PS8/PS9 mismatch {$type}.{$key}: ps9={$left} ps8={$right}");
        }
    }
}

fwrite(STDOUT, "OK (Phase 5 PS8 parity harness — frozen + live cross-run)\n");
