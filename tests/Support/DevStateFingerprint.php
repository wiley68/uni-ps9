<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Tests\Support;

use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Security\TokenRepository;

/**
 * Non-sensitive persistent module-state fingerprint for dev preservation checks.
 */
final class DevStateFingerprint
{
    /** @return array<string, mixed> */
    public static function capture(\Db $db): array
    {
        $repository = new ConfigurationRepository();
        $unicid = $repository->getUnicid();

        return [
            'module_enabled' => $repository->isEnabled(),
            'unicid_present' => $unicid !== '',
            'unicid_fingerprint' => $unicid === '' ? 'absent' : hash('sha256', $unicid),
            'secret_present' => $repository->hasSecret(),
            'token_present' => (new TokenRepository())->hasToken(),
            'tables' => ModuleSchemaInventory::tablePresence($db),
        ];
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public static function assertPreserved(array $before, array $after): void
    {
        $keys = [
            'module_enabled',
            'unicid_present',
            'unicid_fingerprint',
            'secret_present',
            'token_present',
        ];

        foreach ($keys as $key) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                fwrite(
                    STDERR,
                    'FAIL: dev module fingerprint changed for "' . $key . '"' . PHP_EOL
                );
                exit(1);
            }
        }

        $beforeTables = $before['tables'] ?? [];
        $afterTables = $after['tables'] ?? [];
        if (!is_array($beforeTables) || !is_array($afterTables)) {
            fwrite(STDERR, 'FAIL: dev module table fingerprint missing' . PHP_EOL);
            exit(1);
        }

        foreach (ModuleSchemaInventory::tableNames() as $table) {
            $beforeExists = (bool) ($beforeTables[$table] ?? false);
            $afterExists = (bool) ($afterTables[$table] ?? false);
            if ($beforeExists !== $afterExists) {
                fwrite(
                    STDERR,
                    'FAIL: module table presence changed for "' . $table . '"' . PHP_EOL
                );
                exit(1);
            }
        }
    }
}
