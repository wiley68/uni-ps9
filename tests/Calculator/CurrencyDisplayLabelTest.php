<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Calculator\AmountDisplayFormatter;
use PrestaShop\Module\Unipayment\Calculator\CurrencyDisplayLabel;
use PrestaShop\Module\Unipayment\Calculator\InstallmentLabelFormatter;

$labels = new CurrencyDisplayLabel();
$amount = (new AmountDisplayFormatter($labels))->format(1000.0);
$installment = (new InstallmentLabelFormatter($labels))->format(12, 97.49);
if ($labels->forAmount() !== 'евро' || $amount !== ['primary' => '1000.00 евро'] || $installment !== '12 x 97.49 евро') {
    fwrite(STDERR, "FAIL: single EUR financing display changed\n");
    exit(1);
}
fwrite(STDOUT, "OK (single EUR financing display)\n");
