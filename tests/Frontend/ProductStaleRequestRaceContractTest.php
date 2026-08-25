<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

/**
 * Lightweight race-protection contract for product calculator AJAX refresh.
 * Asserts sequence + AbortController so stale responses cannot overwrite newer state.
 */
function assertRaceContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$js = (string) file_get_contents(dirname(__DIR__, 2) . '/views/js/product-calculator.js');

assertRaceContract(strpos($js, 'var refreshSequence = 0') !== false, 'refreshSequence counter must exist');
assertRaceContract(strpos($js, 'var sequence = ++refreshSequence') !== false, 'each refresh must bump sequence');
assertRaceContract(
    (bool) preg_match('/if\s*\(\s*sequence\s*===\s*refreshSequence(?:\s*&&\s*root\.isConnected)?\s*\)/', $js),
    'response must apply only when sequence is still current'
);
assertRaceContract(strpos($js, 'AbortController') !== false, 'AbortController must abort superseded requests');
assertRaceContract(strpos($js, 'refreshRequest.abort') !== false, 'prior refresh must be aborted before a new one');
assertRaceContract(
    strpos($js, 'error.name !== "AbortError"') !== false,
    'AbortError must not clear calculator as a hard failure'
);

fwrite(STDOUT, "OK (Phase 6 stale AJAX race contract)\n");
