<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/fixtures/shop_snapshot.php';

use PrestaShop\Module\Unipayment\Configuration\Exception\ShopConfigurationSnapshotValidationException;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationFlags;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationSnapshotValidator;

function assertSnapshot(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$validator = new ShopConfigurationSnapshotValidator();
$unicid = '123e4567-e89b-12d3-a456-426614174000';

$validator->validate(unipayment_valid_shop_snapshot());
$validator->validate(unipayment_valid_shop_snapshot(['uni_proces' => 1]));

try {
    $validator->validate(unipayment_valid_shop_snapshot(['unicid' => '00000000-0000-0000-0000-000000000099']), $unicid);
    assertSnapshot(false, 'unicid mismatch must fail');
} catch (ShopConfigurationSnapshotValidationException $exception) {
    $matched = false;
    foreach ($exception->violations() as $violation) {
        if ($violation['path'] === 'unicid' && $violation['code'] === 'mismatch') {
            $matched = true;
        }
    }
    assertSnapshot($matched, 'unicid mismatch violation missing');
}

try {
    $bad = unipayment_valid_shop_snapshot();
    unset($bad['kop']);
    $validator->validate($bad);
    assertSnapshot(false, 'missing kop must fail');
} catch (ShopConfigurationSnapshotValidationException $exception) {
    assertSnapshot($exception->errorCode() === 'shop_snapshot_invalid', 'error code mismatch');
}

$process1 = unipayment_valid_shop_snapshot(['uni_proces' => 0, 'uni_env' => 0]);
$process2 = unipayment_valid_shop_snapshot(['uni_proces' => 1, 'uni_env' => 1]);
assertSnapshot(!ShopConfigurationFlags::isProcess2($process1), 'process1 flag');
assertSnapshot(ShopConfigurationFlags::isProcess2($process2), 'process2 flag');
assertSnapshot(ShopConfigurationFlags::isTestEnvironment($process1), 'test env flag');
assertSnapshot(!ShopConfigurationFlags::isTestEnvironment($process2), 'production env flag');
assertSnapshot(ShopConfigurationFlags::isYesFlag(1), 'yes flag numeric');
assertSnapshot(ShopConfigurationFlags::isYesFlag('Yes'), 'yes flag string');
assertSnapshot(!ShopConfigurationFlags::usesSmartUcfCertificate($process1), 'certificate flag off');

fwrite(STDOUT, "OK (shop snapshot validator and flags)\n");
