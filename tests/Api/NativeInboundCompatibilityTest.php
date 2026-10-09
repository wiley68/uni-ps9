<?php

declare(strict_types=1);

// Optional external core source directory; never bootstrap or modify a shop.
$root = dirname(__DIR__, 2);
$core = $argv[1] ?? dirname($root, 2) . '/classes/controller';
$module = $argv[2] ?? $root;
if (!is_file($core . '/ModuleFrontController.php')) {
    echo "SKIP (native PS controller sources unavailable)\n";
    exit(0);
}
$count = 0;
foreach (['shopcache', 'orderbankstatus', 'smartucfdebuglog'] as $endpoint) {
    foreach (['normal', 'malformed', 'get-malformed', 'fallback', 'logging'] as $case) {
        $process = proc_open([PHP_BINARY, $root . '/tests/Support/InboundApiProcess.php', $endpoint, $case, $module, $core], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Native inheritance process did not start.'); }
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || $errors !== '') { throw new RuntimeException('Native inheritance failed: ' . $errors); }
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $status = $case === 'logging' ? 500 : ($case === 'get-malformed' ? 405 : 401);
        $error = $case === 'logging' ? 'internal_error' : ($case === 'get-malformed' ? 'method_not_allowed' : 'invalid_signature');
        if ($result['status'] !== $status || $result['body']['error'] !== $error || $result['body']['success'] !== false) {
            throw new RuntimeException('Native inbound status/envelope regression.');
        }
        if (str_contains(json_encode($result['body'], JSON_THROW_ON_ERROR), 'sensitive-fixture')) { throw new RuntimeException('Native response exposed private exception data.'); }
        ++$count;
    }
}
echo "OK ($count inbound cases with actual native PS controller inheritance; no shop bootstrap)\n";
