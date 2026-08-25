<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

final class Product
{
    public $active = true;

    public function __construct(int $productId, bool $full = false, int $languageId = 0)
    {
    }

    public function getCategories(): array
    {
        return ['7', '9'];
    }

    public function getPrice(bool $tax, ?int $attributeId, int $decimals): float
    {
        return $attributeId === 22 ? 125.50 : 100.00;
    }
}

final class Context
{
    public object $language;

    public static function getContext(): self
    {
        $context = new self();
        $context->language = (object) ['id' => 1];

        return $context;
    }
}

final class Validate
{
    public static function isLoadedObject(object $object): bool
    {
        return true;
    }
}

final class Db
{
    public static function getInstance(): self
    {
        return new self();
    }

    public function getValue(string $query): int
    {
        return strpos($query, '`id_product_attribute` = 22') !== false ? 1 : 0;
    }
}

define('_DB_PREFIX_', 'ps_');

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Product\ProductContextFactory;

function assertProductContextFactory(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$factory = new ProductContextFactory();
$simple = $factory->create(42, 0, 3);
assertProductContextFactory($simple->price === 300.0, 'quantity must multiply the server-side unit price');

$combination = $factory->create(42, 22, 2);
assertProductContextFactory($combination->price === 251.0, 'combination price and quantity must both be applied');

try {
    $factory->create(42, 0, 0);
    assertProductContextFactory(false, 'zero quantity must be rejected');
} catch (InvalidArgumentException $exception) {
    assertProductContextFactory(true, 'zero quantity rejected');
}

fwrite(STDOUT, "OK (Phase 6 product context server-side pricing)\n");
