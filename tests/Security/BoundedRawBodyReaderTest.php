<?php

declare(strict_types=1);

/**
 * F02 — bounded inbound raw-body reader.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Security\BoundedRawBodyReader;
use PrestaShop\Module\Unipayment\Security\ModuleRequestSignatureProtocol;

function assertBounded(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/** @return resource */
function memoryStream(string $contents)
{
    $stream = fopen('php://memory', 'r+b');
    assertBounded(is_resource($stream), 'memory stream open failed');
    fwrite($stream, $contents);
    rewind($stream);

    return $stream;
}

$max = ModuleRequestSignatureProtocol::MAX_REQUEST_BODY_BYTES;
assertBounded($max === 1048576, 'canonical max body is 1 MiB');

$exact = str_repeat('a', $max);
$exactRead = BoundedRawBodyReader::read(memoryStream($exact), $max);
assertBounded($exactRead['oversized'] === false, 'exact 1 MiB must be accepted');
assertBounded($exactRead['body'] === $exact, 'exact 1 MiB body bytes preserved');
assertBounded(strlen($exactRead['body']) === $max, 'exact 1 MiB length');

$over = str_repeat('b', $max + 1);
$overRead = BoundedRawBodyReader::read(memoryStream($over), $max);
assertBounded($overRead['oversized'] === true, '1 MiB + 1 must be oversized');
assertBounded($overRead['body'] === '', 'oversized body must not be returned for processing');

$chunkedLike = str_repeat('c', $max + 50);
$chunkedRead = BoundedRawBodyReader::read(memoryStream($chunkedLike), $max);
assertBounded($chunkedRead['oversized'] === true, 'missing Content-Length oversized stream still rejected');

$misleading = str_repeat('d', $max + 10);
$misleadingRead = BoundedRawBodyReader::read(memoryStream($misleading), $max);
assertBounded($misleadingRead['oversized'] === true, 'misleading Content-Length still protected by stream bound');

$hmacBody = '{"operation":"shop-cache","unicid":"U","data":{"x":1}}';
$hmacRead = BoundedRawBodyReader::read(memoryStream($hmacBody), $max);
assertBounded($hmacRead['body'] === $hmacBody, 'accepted body bytes preserved exactly for HMAC');

$ctrl = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controller/ModuleApiController.php');
assertBounded(strpos($ctrl, 'BoundedRawBodyReader::read') !== false, 'controller uses bounded reader');
assertBounded(strpos($ctrl, "file_get_contents('php://input')") === false, 'controller must not unbounded-read php://input');
assertBounded(strpos($ctrl, 'PAYLOAD_TOO_LARGE') !== false, 'oversized maps to payload_too_large');
assertBounded(strpos($ctrl, '413') !== false, 'oversized uses HTTP 413');

fwrite(STDOUT, "OK (F02 bounded inbound raw-body reader)\n");
