<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/fixtures/shop_snapshot.php';

use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationCache;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationSnapshotValidator;
use PrestaShop\Module\Unipayment\Configuration\ShopSnapshotSanitizer;

define('_DB_PREFIX_', 'synthetic_');
function pSQL(string $value, bool $html = false): string { return addslashes($value); }
class Db {}
final class ConsentCacheDb extends Db
{
    public string $json = '';
    public string $sql = '';
    public function execute(string $sql): bool
    {
        $this->sql = $sql;
        if (!preg_match("/VALUES \\('(?:\\\\.|[^'])*',\\s*'((?:\\\\.|[^'])*)'/s", $sql, $match)) {
            throw new RuntimeException('Expected cache INSERT boundary');
        }
        $this->json = stripslashes($match[1]);
        return true;
    }
    public function getRow(string $sql): array { return ['shop_data' => $this->json]; }
}
$db = new ConsentCacheDb();
$cache = new ShopConfigurationCache($db);
foreach ([false, true] as $json) {
    $consents = [
        ['id' => 1, 'name' => "All's terms", 'mandatory' => 1, 'interest_scope' => 'all'],
        ['id' => 2, 'name' => 'Zero', 'mandatory' => 0, 'interest_scope' => 'zero_interest'],
        ['id' => 3, 'name' => 'Nonzero', 'mandatory' => 1, 'interest_scope' => 'nonzero_interest'],
        ['id' => 4, 'name' => 'Legacy', 'mandatory' => 1, 'interest_scope' => ['invalid']],
    ];
    $shop = unipayment_valid_shop_snapshot(['consents' => $json ? json_encode($consents, JSON_THROW_ON_ERROR) : $consents]);
    $shop = ShopSnapshotSanitizer::sanitize($shop);
    (new ShopConfigurationSnapshotValidator())->validate($shop);
    $cache->replace($shop['unicid'], $shop);
    $restored = $cache->getFresh($shop['unicid']);
    if ($restored === null || $restored['consents'] !== $shop['consents']) {
        throw new RuntimeException('Consent scope changed at the real cache JSON/SQL boundary');
    }
}
echo "OK consent cache array/JSON, scope preservation and apostrophe SQL boundary (fake DB)\n";
