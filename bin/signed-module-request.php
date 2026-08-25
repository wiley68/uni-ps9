#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * CLI helper: build and optionally POST a signed UniPayment module request.
 *
 * Never prints secrets. Does not bypass authentication.
 *
 * Usage examples:
 *
 *   php bin/signed-module-request.php --print-headers \
 *     --secret-env=UNIPAYMENT_LIVE_SECRET \
 *     --body='{"unicid":"...","data":{...}}'
 *
 *   php bin/signed-module-request.php --post \
 *     --url=https://presta9.avalonbg.com/module/unipayment/shopcache \
 *     --secret-env=UNIPAYMENT_LIVE_SECRET \
 *     --body-file=/path/to/body.json
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "FAIL: run composer dump-autoload first\n");
    exit(1);
}
require $autoload;

use PrestaShop\Module\Unipayment\Security\ModuleRequestSignatureProtocol;

$opts = getopt('', [
    'secret:',
    'secret-env:',
    'body:',
    'body-file:',
    'timestamp:',
    'nonce:',
    'url:',
    'print-headers',
    'post',
    'help',
]);

if (isset($opts['help']) || $opts === false) {
    fwrite(STDOUT, file_get_contents(__FILE__) !== false
        ? "See file header for usage.\n"
        : "signed-module-request helper\n");
    exit(isset($opts['help']) ? 0 : 1);
}

$secret = '';
if (isset($opts['secret-env']) && is_string($opts['secret-env'])) {
    $secret = (string) (getenv($opts['secret-env']) ?: '');
} elseif (isset($opts['secret']) && is_string($opts['secret'])) {
    $secret = $opts['secret'];
}
if ($secret === '') {
    fwrite(STDERR, "FAIL: provide --secret-env=VAR or --secret=...\n");
    exit(1);
}

$rawBody = '';
if (isset($opts['body-file']) && is_string($opts['body-file'])) {
    $rawBody = (string) file_get_contents($opts['body-file']);
} elseif (isset($opts['body']) && is_string($opts['body'])) {
    $rawBody = $opts['body'];
}
if ($rawBody === '') {
    fwrite(STDERR, "FAIL: provide --body or --body-file\n");
    exit(1);
}

$timestamp = isset($opts['timestamp']) && is_string($opts['timestamp'])
    ? $opts['timestamp']
    : (string) time();
$nonce = isset($opts['nonce']) && is_string($opts['nonce'])
    ? $opts['nonce']
    : bin2hex(random_bytes(32));

if (!preg_match('/\A[0-9a-fA-F]{' . ModuleRequestSignatureProtocol::NONCE_HEX_LENGTH . '}\z/', $nonce)) {
    fwrite(STDERR, "FAIL: nonce must be 64 hex chars\n");
    exit(1);
}

$signature = ModuleRequestSignatureProtocol::computeSignature($secret, $timestamp, $nonce, $rawBody);

$headers = [
    ModuleRequestSignatureProtocol::HEADER_TIMESTAMP . ': ' . $timestamp,
    ModuleRequestSignatureProtocol::HEADER_NONCE . ': ' . $nonce,
    ModuleRequestSignatureProtocol::HEADER_SIGNATURE . ': ' . $signature,
    'Content-Type: application/json',
    'Accept: application/json',
];

if (isset($opts['print-headers'])) {
    fwrite(STDOUT, "timestamp_length=" . strlen($timestamp) . "\n");
    fwrite(STDOUT, "nonce_length=" . strlen($nonce) . "\n");
    fwrite(STDOUT, "body_length=" . strlen($rawBody) . "\n");
    fwrite(STDOUT, "signature_length=" . strlen($signature) . "\n");
    fwrite(STDOUT, "signature_prefix=" . substr($signature, 0, 12) . "\n");
    foreach ($headers as $header) {
        if (stripos($header, 'Signature:') !== false) {
            fwrite(STDOUT, ModuleRequestSignatureProtocol::HEADER_SIGNATURE . ": <hex len=" . strlen($signature) . ">\n");
            continue;
        }
        fwrite(STDOUT, $header . "\n");
    }
}

if (!isset($opts['post'])) {
    exit(0);
}

if (!isset($opts['url']) || !is_string($opts['url']) || $opts['url'] === '') {
    fwrite(STDERR, "FAIL: --post requires --url=\n");
    exit(1);
}

$ch = curl_init($opts['url']);
if ($ch === false) {
    fwrite(STDERR, "FAIL: curl_init\n");
    exit(1);
}
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $rawBody,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$responseBody = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

fwrite(STDOUT, "http_status={$status}\n");
fwrite(STDOUT, "response_length=" . (is_string($responseBody) ? strlen($responseBody) : 0) . "\n");
if ($error !== '') {
    fwrite(STDERR, "curl_error_present=1\n");
    exit(1);
}
if (is_string($responseBody)) {
    $decoded = json_decode($responseBody, true);
    if (is_array($decoded)) {
        fwrite(STDOUT, 'success=' . (isset($decoded['success']) && $decoded['success'] ? '1' : '0') . "\n");
        if (isset($decoded['message']) && is_string($decoded['message'])) {
            fwrite(STDOUT, 'message=' . $decoded['message'] . "\n");
        }
        if (isset($decoded['error']) && is_string($decoded['error'])) {
            fwrite(STDOUT, 'error=' . $decoded['error'] . "\n");
        }
    } else {
        fwrite(STDOUT, "response_json=0\n");
    }
}

exit($status >= 200 && $status < 300 ? 0 : 1);
