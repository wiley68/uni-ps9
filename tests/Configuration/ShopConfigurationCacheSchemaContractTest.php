<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function assertCacheSchema(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$cachePath = $root . '/src/Configuration/ShopConfigurationCache.php';
$modulePath = $root . '/unipayment.php';
assertCacheSchema(is_file($cachePath), 'ShopConfigurationCache.php missing');
assertCacheSchema(is_file($modulePath), 'unipayment.php missing');

$cache = (string) file_get_contents($cachePath);
$module = (string) file_get_contents($modulePath);

assertCacheSchema(strpos($cache, "TABLE = 'unipayment_shop_cache'") !== false, 'table constant missing');
assertCacheSchema(strpos($cache, 'TTL_SECONDS = 86400') !== false, 'TTL must be 86400');
assertCacheSchema(strpos($cache, 'CREATE TABLE IF NOT EXISTS') !== false, 'install SQL missing');
assertCacheSchema(strpos($cache, '`unicid` VARCHAR(36) NOT NULL') !== false, 'unicid column missing');
assertCacheSchema(strpos($cache, '`shop_data` LONGTEXT NOT NULL') !== false, 'shop_data column missing');
assertCacheSchema(strpos($cache, '`fetched_at` DATETIME NOT NULL') !== false, 'fetched_at missing');
assertCacheSchema(strpos($cache, '`expires_at` DATETIME NOT NULL') !== false, 'expires_at missing');
assertCacheSchema(strpos($cache, 'UNIQUE KEY `uniq_unipayment_cache_unicid` (`unicid`)') !== false, 'unicid unique key missing');
assertCacheSchema(strpos($cache, 'ON DUPLICATE KEY UPDATE') !== false, 'full replace SQL missing');
assertCacheSchema(strpos($cache, 'id_shop') === false, 'PS8 cache is unicid-scoped, not id_shop');

preg_match_all("/CREATE TABLE IF NOT EXISTS[^;]+;/s", $cache, $matches);
assertCacheSchema(count($matches[0]) === 1, 'exactly one CREATE TABLE expected');
assertCacheSchema(
    strpos($matches[0][0], '{$table}') !== false || strpos($matches[0][0], 'unipayment_shop_cache') !== false,
    'CREATE TABLE must use tableName()/shop_cache'
);
assertCacheSchema(strpos($cache, "TABLE = 'unipayment_shop_cache'") !== false, 'TABLE constant must be shop_cache');

assertCacheSchema(strpos($module, 'ShopConfigurationCache') !== false, 'module must install cache');
assertCacheSchema(
    (bool) preg_match('/\$cache->install\(\)/', $module)
        && (bool) preg_match('/\$cache->uninstall\(\)/', $module),
    'install/uninstall must manage shop cache table'
);

fwrite(STDOUT, "OK (shop cache schema contract)\n");
