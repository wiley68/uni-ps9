<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/tools/DistributionPackage.php';

try {
    (new PrestaShop\Module\Unipayment\Build\DistributionPackage(dirname(__DIR__)))->build();
} catch (Throwable $exception) {
    fwrite(STDERR, 'Package build failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
