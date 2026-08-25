<?php

declare(strict_types=1);

/**
 * ModuleApiException safe response envelope contract.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Api\Exception\ModuleApiException;

function assertEx(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$exception = new ModuleApiException('safe message', 422, 'shop_snapshot_invalid', ['violations' => []]);
assertEx($exception->getMessage() === 'safe message', 'message');
assertEx($exception->getStatusCode() === 422, 'status');
assertEx($exception->getErrorCode() === 'shop_snapshot_invalid', 'error code');
assertEx(is_array($exception->getResponseData()), 'response data');

$base = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controller/ModuleApiController.php');
assertEx(strpos($base, "'success' => false") !== false, 'failure envelope');
assertEx(strpos($base, '$exception->getMessage()') !== false, 'uses safe message');
assertEx(strpos($base, '$exception->getMessage()') !== false && strpos($base, 'getTrace') === false, 'no stack traces');

fwrite(STDOUT, "OK (ModuleApiException envelope contract)\n");
