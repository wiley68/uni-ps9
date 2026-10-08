<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertInboundHeaders(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

foreach (['shopcache', 'smartucfdebuglog', 'orderbankstatus'] as $endpoint) {
    foreach (['normal', 'malformed', 'get-malformed', 'fallback', 'logging'] as $case) {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Support/InboundApiProcess.php', $endpoint, $case],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        assertInboundHeaders(is_resource($process), 'Child process could not start.');
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        assertInboundHeaders(proc_close($process) === 0 && $errors === '', "$endpoint/$case process failed: $errors");
        $result = json_decode((string) $output, true, 512, JSON_THROW_ON_ERROR);
        $status = $case === 'get-malformed' ? 405 : ($case === 'logging' ? 500 : 401);
        $error = $case === 'get-malformed' ? 'method_not_allowed' : ($case === 'logging' ? 'internal_error' : 'invalid_signature');
        assertInboundHeaders($result['status'] === $status, "$endpoint/$case HTTP status");
        assertInboundHeaders($result['body']['error'] === $error && $result['body']['success'] === false, "$endpoint/$case envelope");
        assertInboundHeaders(($result['headers']['X-Audit-Server'] ?? '') === 'sensitive-fixture-server', 'SERVER header preserved');
        foreach (['X-Invalid', 'X-Invalid-Array', 'X-Invalid-Null', 'X-Invalid-Number'] as $invalid) {
            assertInboundHeaders(!array_key_exists($invalid, $result['headers']), 'Invalid values ignored: ' . $invalid);
        }
        assertInboundHeaders(($case === 'fallback') === !isset($result['headers']['X-Audit-Header']), 'getallheaders/fallback routing');
        if ($case !== 'logging') {
            assertInboundHeaders($result['logs'] === [], "$endpoint/$case must stay controlled");
            continue;
        }
        assertInboundHeaders(count($result['logs']) === 1 && $result['logs'][0]['severity'] === 3, 'One operational error log');
        $message = $result['logs'][0]['message'];
        assertInboundHeaders(str_contains($message, $endpoint === 'shopcache' ? 'UnipaymentShopcache' : 'Unipayment' . ucfirst($endpoint)), 'Controller identified');
        assertInboundHeaders(str_contains($message, 'exception=RuntimeException'), 'Exception class logged');
        assertInboundHeaders(str_contains($message, 'file=InboundApiProcess.php'), 'Source file logged');
        assertInboundHeaders(preg_match('/line=[1-9][0-9]*\./', $message) === 1, 'Source line logged');
        assertInboundHeaders(!str_contains($message, 'sensitive-fixture') && !str_contains($message, 'synthetic'), 'No sensitive input logged');
        assertInboundHeaders(!str_contains(json_encode($result['body'], JSON_THROW_ON_ERROR), 'RuntimeException'), 'Public response stays opaque');
    }
}

fwrite(STDOUT, "OK (15 inbound header/authentication/logging cases across three endpoints)\n");
