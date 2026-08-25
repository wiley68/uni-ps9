<?php

declare(strict_types=1);

/**
 * ApiNonceRepository schema + atomic claim contract.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}
if (!defined('_MYSQL_ENGINE_')) {
    define('_MYSQL_ENGINE_', 'InnoDB');
}

if (!function_exists('pSQL')) {
    function pSQL(string $string, bool $htmlOK = false): string
    {
        unset($htmlOK);

        return str_replace("'", "\\'", $string);
    }
}

final class ApiNonceFakeDb
{
    /** @var list<array{unicid:string,nonce_hash:string}> */
    public array $rows = [];
    /** @var list<string> */
    public array $sql = [];
    public bool $forceDuplicate = false;

    public function execute(string $sql): bool
    {
        $this->sql[] = $sql;

        return true;
    }

    public function insert(string $table, array $data, $nullValues = false, $useCache = true, $type = 1): bool
    {
        unset($nullValues, $useCache, $type);
        if ($table !== 'unipayment_api_nonce') {
            return false;
        }
        if ($this->forceDuplicate) {
            return false;
        }
        foreach ($this->rows as $row) {
            if ($row['unicid'] === $data['unicid'] && $row['nonce_hash'] === $data['nonce_hash']) {
                return false;
            }
        }
        $this->rows[] = [
            'unicid' => (string) $data['unicid'],
            'nonce_hash' => (string) $data['nonce_hash'],
        ];

        return true;
    }

    public function getMsgError(): string
    {
        return 'Duplicate entry';
    }
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Security\ApiNonceRepository;

function assertNonce(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Security/ApiNonceRepository.php');
assertNonce(strpos($src, "TABLE = 'unipayment_api_nonce'") !== false, 'table constant');
assertNonce(strpos($src, 'UNIQUE KEY `uniq_unipayment_api_nonce` (`unicid`, `nonce_hash`)') !== false, 'unique constraint');
assertNonce(strpos($src, 'claimNonce') !== false, 'claimNonce present');

$db = new ApiNonceFakeDb();
$repo = new ApiNonceRepository($db);
assertNonce($repo->install(), 'install');
assertNonce(strpos($db->sql[0], 'CREATE TABLE IF NOT EXISTS') !== false, 'create table sql');
assertNonce($repo->claimNonce('shop-1', str_repeat('a', 64), 1700000000), 'first claim');
assertNonce(!$repo->claimNonce('shop-1', str_repeat('a', 64), 1700000001), 'duplicate claim rejected');
assertNonce($repo->claimNonce('shop-1', str_repeat('b', 64), 1700000002), 'different nonce accepted');
assertNonce($repo->uninstall(), 'uninstall');

fwrite(STDOUT, "OK (ApiNonceRepository schema and claim contract)\n");
