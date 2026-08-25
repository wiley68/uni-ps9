<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Calculator\AmountDisplayFormatter;
use PrestaShop\Module\Unipayment\Calculator\CurrencyDisplayLabel;
use PrestaShop\Module\Unipayment\Calculator\InstallmentLabelFormatter;

function assertCurrencyLabel(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$labels = new CurrencyDisplayLabel();
assertCurrencyLabel($labels->forIso('EUR') === 'евро', 'CLI fallback must keep Bulgarian евро source');
assertCurrencyLabel($labels->forIso('BGN') === 'лв.', 'CLI fallback must keep Bulgarian лв. source');
assertCurrencyLabel($labels->forIso('eur') === 'евро', 'ISO lookup must be case-insensitive');

$amounts = new AmountDisplayFormatter($labels);
$single = $amounts->format(1000.0, ['uni_eur' => 3]);
assertCurrencyLabel($single['primary'] === '1000.00 евро' && $single['dual'] === false, 'EUR-only amount must use translated suffix path');

$installments = new InstallmentLabelFormatter($labels);
assertCurrencyLabel(
    $installments->format(12, 97.49, 3) === '12 x 97.49 евро',
    'installment label must route EUR through CurrencyDisplayLabel'
);
assertCurrencyLabel(
    $installments->format(12, 97.49, 0) === '12 x 97.49 лв.',
    'installment label must route BGN through CurrencyDisplayLabel'
);

$module = (string) file_get_contents(dirname(__DIR__, 2) . '/unipayment.php');
assertCurrencyLabel(strpos($module, "trans('евро', [], 'Modules.Unipayment.Shop')") !== false, 'евро must be registered on the module for the PS catalog');
assertCurrencyLabel(strpos($module, "trans('лв.', [], 'Modules.Unipayment.Shop')") !== false, 'лв. must be registered on the module for the PS catalog');
assertCurrencyLabel(strpos($module, "trans('лева', [], 'Modules.Unipayment.Shop')") !== false, 'лева must be registered for dual button labels');

fwrite(STDOUT, "OK (Currency display labels are translatable)\n");
