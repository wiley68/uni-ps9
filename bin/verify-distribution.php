<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/tools/DistributionPackage.php';

try {
    if ($argc !== 2) {
        throw new RuntimeException('Usage: php bin/verify-distribution.php dist/ps9_uni_<version>.zip');
    }
    $count = (new PrestaShop\Module\Unipayment\Build\DistributionPackage(dirname(__DIR__)))->verify($argv[1]);
    fwrite(STDOUT, "OK (distribution verified; $count files)\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Package verification failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
