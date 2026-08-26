<?php

declare(strict_types=1);

/**
 * AuthoritativeOrderResolver: never bind financing to an empty twin order.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Order\AuthoritativeOrderResolver;
use PrestaShop\Module\Unipayment\Order\CreatedOrder;

function assertAuthOrder(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function orderStub(int $id, array $lines, string $ref = 'ABCDEFGHIJKLM'): CreatedOrder
{
    return new CreatedOrder($id, $ref, 100.0, 'EUR', 1, [], [], $lines);
}

$resolver = new AuthoritativeOrderResolver();
$line = ['id_product' => 1, 'id_product_attribute' => 0, 'name' => 'X', 'quantity' => 1, 'total' => 10.0];

// A/D: preferred empty + sibling with lines → choose sibling with lines
$resolved = $resolver->resolve([
    orderStub(50, [$line]),
    orderStub(51, []),
], 51);
assertAuthOrder($resolved->idOrder === 50, 'D: must not choose empty preferred twin');

// preferred with lines wins
$resolved = $resolver->resolve([
    orderStub(50, [$line]),
    orderStub(51, []),
], 50);
assertAuthOrder($resolved->idOrder === 50, 'preferred non-empty wins');

// single non-empty
$resolved = $resolver->resolve([orderStub(7, [$line])], 0);
assertAuthOrder($resolved->idOrder === 7, 'A: single non-empty order');

// E: multiple non-empty → fail closed
$threw = false;
try {
    $resolver->resolve([orderStub(1, [$line]), orderStub(2, [$line])], 0);
} catch (RuntimeException $exception) {
    $threw = strpos($exception->getMessage(), 'Multiple non-empty') !== false;
}
assertAuthOrder($threw, 'E: ambiguous non-empty candidates fail closed');

// F: only empty → fail closed
$threw = false;
try {
    $resolver->resolve([orderStub(51, []), orderStub(52, [])], 51);
} catch (RuntimeException $exception) {
    $threw = strpos($exception->getMessage(), 'no order lines') !== false;
}
assertAuthOrder($threw, 'F: empty-only candidates fail closed');

assertAuthOrder($resolver->hasLines(orderStub(1, [$line])), 'hasLines true');
assertAuthOrder(!$resolver->hasLines(orderStub(1, [])), 'hasLines false');

fwrite(STDOUT, "OK (authoritative order resolver)\n");
