<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Order\LeasingOrderEmailPresenter;

function assertAdminOrderCreditBox(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$template = (string) file_get_contents($root . '/views/templates/hook/admin_order_financing_details.tpl');

assertAdminOrderCreditBox(
    strpos($template, "{l s='УниКредит — кредитна заявка' d='Modules.Unipayment.Admin'}") !== false,
    'admin order box title must match Woo UniCredit — credit application'
);
assertAdminOrderCreditBox(strpos($template, 'Статус към банката') === false, 'admin order box must not have a separate Bank status section');
assertAdminOrderCreditBox(strpos($template, 'Leasing terms') === false, 'admin order box must not use a Leasing terms heading');
assertAdminOrderCreditBox(strpos($template, 'unipayment_leasing_rows') !== false, 'admin order box must render the leasing rows table');

$presenter = (new \ReflectionClass(LeasingOrderEmailPresenter::class))->newInstanceWithoutConstructor();
$rows = $presenter->applyBankStatusLabel(
    [
        'Статус към банката' => 'Изпратен Банка - Процес 1',
        'KOP' => 'ABC',
    ],
    'Одобрен'
);
$labels = array_keys($rows);

assertAdminOrderCreditBox($labels[0] === 'Статус към банката', 'bank status must remain the first leasing row');
assertAdminOrderCreditBox($rows['Статус към банката'] === 'Одобрен', 'live bank status must replace the snapshot fallback');
assertAdminOrderCreditBox($rows['KOP'] === 'ABC', 'leasing terms must stay after the bank status row');

fwrite(STDOUT, "OK (Admin order credit box Woo parity)\n");
