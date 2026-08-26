<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Advertising\HomepageAdvertisingPresenter;

function assertAdvertisingPresenter(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$presenter = new HomepageAdvertisingPresenter();
$logo = '/modules/unipayment/views/img/product/uni_logo.svg';
$shop = [
    'uni_status' => 1,
    'uni_container_status' => 1,
    'uni_backurl' => 'https://cdn.example.test/info',
    'uni_picture' => 'https://r2.example.test/desktop.jpg',
    'uni_picturem' => 'https://r2.example.test/mobile.jpg',
    'uni_container_txt1' => ' <b>Заглавие</b> ',
    'uni_container_txt2' => 'Текст от КП',
];

$desktop = $presenter->present($shop, false, $logo);
assertAdvertisingPresenter(is_array($desktop), 'desktop advertising must render when CP flags allow it');
assertAdvertisingPresenter($desktop['is_mobile'] === false, 'desktop payload must not be marked mobile');
assertAdvertisingPresenter($desktop['float_image_url'] === $logo, 'desktop float button must use the local UniCredit logo, not R2');
assertAdvertisingPresenter($desktop['picture_url'] === 'https://r2.example.test/mobile.jpg', 'panel image must use CP uni_picturem (R2) as in Woo');
assertAdvertisingPresenter($desktop['backurl'] === 'https://cdn.example.test/info', 'panel link must use CP uni_backurl');
assertAdvertisingPresenter($desktop['txt1'] === 'Заглавие', 'CP txt1 must be text-only');
assertAdvertisingPresenter($desktop['txt2'] === 'Текст от КП', 'CP txt2 must be preserved');

$mobile = $presenter->present($shop, true, $logo);
assertAdvertisingPresenter($mobile['is_mobile'] === true, 'mobile payload flag missing');
assertAdvertisingPresenter($mobile['float_image_url'] === 'https://r2.example.test/mobile.jpg', 'mobile float button must use CP uni_picturem from R2');
assertAdvertisingPresenter($mobile['picture_url'] === 'https://r2.example.test/mobile.jpg', 'mobile still exposes the CP mobile picture URL');

$noMobilePicture = $presenter->present(
    ['uni_status' => 1, 'uni_container_status' => 1, 'uni_picturem' => ''],
    true,
    $logo
);
assertAdvertisingPresenter($noMobilePicture['float_image_url'] === $logo, 'empty uni_picturem must fall back to the local logo');

assertAdvertisingPresenter(
    $presenter->present(['uni_status' => 0, 'uni_container_status' => 1, 'uni_picturem' => 'https://r2.example.test/m.jpg'], false, $logo) === null,
    'inactive shop must not build advertising payload'
);

$unsafe = $presenter->present(
    [
        'uni_status' => 1,
        'uni_container_status' => 1,
        'uni_backurl' => 'javascript:alert(1)',
        'uni_picturem' => '/relative.jpg',
        'uni_picture' => 'https://r2.example.test/desktop.jpg',
    ],
    true,
    $logo
);
assertAdvertisingPresenter($unsafe['backurl'] === '', 'non-http backurl must be discarded');
assertAdvertisingPresenter($unsafe['picture_url'] === '', 'relative picture paths must not be used as R2 URLs');
assertAdvertisingPresenter($unsafe['float_image_url'] === $logo, 'invalid mobile picture must fall back to the local logo');

fwrite(STDOUT, "OK (Homepage advertising presenter / R2 URL contract)\n");
