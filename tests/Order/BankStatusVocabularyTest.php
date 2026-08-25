<?php

declare(strict_types=1);

/**
 * Bank status vocabulary + Phase 4 controller/repository contracts.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Order\BankStatus;

function assertBank(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$ctrl = (string) file_get_contents($root . '/controllers/front/orderbankstatus.php');
$repo = (string) file_get_contents($root . '/src/Order/OrderBankStatusRepository.php');

assertBank(strpos($ctrl, 'extends ModuleApiController') !== false, 'orderbankstatus extends ModuleApiController');
assertBank(strpos($ctrl, 'ps_order_state_changed') !== false, 'no customer-facing order-state change yet');
assertBank(strpos($ctrl, 'BankStatusOrderStateMapper') === false, 'no order-state mapper in Phase 4');

assertBank(BankStatus::SENT_PROCESS1 === 'bank_sent_process1', 'process1 id');
assertBank(BankStatus::SENT_PROCESS2 === 'bank_sent_process2', 'process2 id');
assertBank(BankStatus::SEND_FAILED === 'bank_send_failed', 'failed id');
assertBank(BankStatus::SEND_FAILED_CP === 'bank_send_failed_cp', 'failed cp id');
assertBank(BankStatus::SEND_FAILED_SMARTUCF === 'bank_send_failed_smartucf', 'failed smartucf id');

$p1 = BankStatus::successfulSend(false);
$p2 = BankStatus::successfulSend(true);
assertBank($p1['status_id'] === BankStatus::SENT_PROCESS1, 'successfulSend process1');
assertBank($p2['status_id'] === BankStatus::SENT_PROCESS2, 'successfulSend process2');

assertBank(strpos($repo, "TABLE = 'unipayment_order_bank_status'") !== false, 'bank status table');
assertBank(strpos($repo, 'uniq_unipayment_bank_id_order') !== false, 'unique id_order');

fwrite(STDOUT, "OK (bank status vocabulary and orderbankstatus contract)\n");
