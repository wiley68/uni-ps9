<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\SmartUcf;

/**
 * PrestaShop Configuration-backed store for encrypted SmartUCF credentials.
 *
 * Reads never use Configuration::get() fallback (shop → group → global).
 * Pair reads use one exact-context SQL query and fail closed unless the exact
 * context contains precisely one USER and one PASSWORD row.
 *
 * Writes use exact-context SQL only. Configuration::updateValue() is never used:
 * when Shop::isFeatureActive() is false it discards explicit shop/group IDs.
 *
 * Global NULL/NULL rows are never adopted by runtime lookup.
 */
final class ConfigurationSmartUcfCredentialSettingStore implements SmartUcfCredentialSettingStoreInterface
{
    /** @var \Db|object */
    private $database;

    /**
     * @param \Db|object|null $database
     */
    public function __construct($database = null)
    {
        $this->database = $database ?? \Db::getInstance();
    }

    /**
     * @return array{user: ?string, password: ?string}
     */
    public function getPair(int $idShop): array
    {
        $idShop = max(0, $idShop);
        $idShopGroup = $this->resolveShopGroupId($idShop);

        return $this->readExactPair($idShop, $idShopGroup);
    }

    public function get(int $idShop, string $key): ?string
    {
        $pair = $this->getPair($idShop);
        if ($key === SmartUcfCredentialRepository::USER_KEY) {
            return $pair['user'];
        }
        if ($key === SmartUcfCredentialRepository::PASSWORD_KEY) {
            return $pair['password'];
        }

        return null;
    }

    public function set(int $idShop, string $key, string $value): void
    {
        $idShop = max(0, $idShop);
        $idShopGroup = $idShop > 0 ? $this->resolveShopGroupId($idShop) : 0;
        $now = date('Y-m-d H:i:s');

        // Replace all exact-context rows for this key with exactly one row.
        // Never pick an arbitrary duplicate; never leave stale duplicates behind.
        $this->deleteExactRows($key, $idShop, $idShopGroup);

        $ok = (bool) $this->database->insert(
            'configuration',
            [
                'id_shop_group' => $idShop > 0 ? (int) $idShopGroup : null,
                'id_shop' => $idShop > 0 ? (int) $idShop : null,
                'name' => pSQL($key),
                'value' => pSQL($value),
                'date_add' => $now,
                'date_upd' => $now,
            ],
            true
        );

        if (!$ok) {
            throw new \RuntimeException('Failed to persist encrypted SmartUCF credential.');
        }

        $count = $this->countExactRows($key, $idShop, $idShopGroup);
        if ($count !== 1) {
            throw new \RuntimeException(
                'SmartUCF credential write postcondition failed: expected exactly one exact-context row.'
            );
        }
    }

    public function delete(int $idShop, string $key): void
    {
        $idShop = max(0, $idShop);
        $idShopGroup = $idShop > 0 ? $this->resolveShopGroupId($idShop) : 0;
        $this->deleteExactRows($key, $idShop, $idShopGroup);
    }

    public function deleteByNameAllShops(string $key): void
    {
        \Configuration::deleteByName($key);
    }

    /**
     * Exact-context pair read.
     *
     * Physical row counts are enforced before value filtering:
     * - 0 USER + 0 PASSWORD → absent
     * - 1 USER + 1 PASSWORD with both non-empty values → complete candidate
     * - any other physical row count → invalid/ambiguous (fail closed)
     *
     * @return array{user: ?string, password: ?string}
     */
    private function readExactPair(int $idShop, int $idShopGroup): array
    {
        $userKey = SmartUcfCredentialRepository::USER_KEY;
        $passwordKey = SmartUcfCredentialRepository::PASSWORD_KEY;
        $absent = ['user' => null, 'password' => null];

        $sql = 'SELECT `name`, `value` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` IN (\''
            . pSQL($userKey) . '\', \'' . pSQL($passwordKey) . '\')'
            . $this->exactContextRestriction($idShop, $idShopGroup);

        $rows = $this->database->executeS($sql);
        if (!is_array($rows) || $rows === []) {
            return $absent;
        }

        $userValues = [];
        $passwordValues = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = (string) ($row['name'] ?? '');
            if ($name === $userKey) {
                $userValues[] = $row['value'] ?? null;
            } elseif ($name === $passwordKey) {
                $passwordValues[] = $row['value'] ?? null;
            }
        }

        $userPhysicalCount = count($userValues);
        $passwordPhysicalCount = count($passwordValues);

        if ($userPhysicalCount === 0 && $passwordPhysicalCount === 0) {
            return $absent;
        }

        if ($userPhysicalCount !== 1 || $passwordPhysicalCount !== 1) {
            \PrestaShopLogger::addLog(
                'UniPayment SmartUCF exact credential context is ambiguous'
                . ' (user_rows=' . $userPhysicalCount
                . ', password_rows=' . $passwordPhysicalCount
                . ', id_shop=' . (int) $idShop
                . ', id_shop_group=' . (int) $idShopGroup . ').',
                3
            );

            return $absent;
        }

        $user = $userValues[0];
        $password = $passwordValues[0];
        if ($user === null || $user === '' || $user === false
            || $password === null || $password === '' || $password === false
        ) {
            return $absent;
        }

        return [
            'user' => (string) $user,
            'password' => (string) $password,
        ];
    }

    private function deleteExactRows(string $key, int $idShop, int $idShopGroup): void
    {
        $this->database->delete(
            'configuration',
            '`name` = \'' . pSQL($key) . '\'' . $this->exactContextRestriction($idShop, $idShopGroup)
        );
    }

    private function countExactRows(string $key, int $idShop, int $idShopGroup): int
    {
        $sql = 'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \''
            . pSQL($key) . '\'' . $this->exactContextRestriction($idShop, $idShopGroup);

        return max(0, (int) $this->database->getValue($sql));
    }

    private function resolveShopGroupId(int $idShop): int
    {
        if ($idShop <= 0) {
            return 0;
        }
        if (class_exists('\\Shop') && method_exists('\\Shop', 'getGroupFromShop')) {
            $group = \Shop::getGroupFromShop($idShop, true);
            if ($group !== false && $group !== null) {
                return max(0, (int) $group);
            }
        }
        if (class_exists('\\Shop')) {
            try {
                $shop = new \Shop($idShop);
                if (isset($shop->id_shop_group)) {
                    return max(0, (int) $shop->id_shop_group);
                }
            } catch (\Throwable $exception) {
                // Fall through.
            }
        }

        return 0;
    }

    private function exactContextRestriction(int $idShop, int $idShopGroup): string
    {
        if ($idShop > 0) {
            return ' AND `id_shop` = ' . (int) $idShop
                . ' AND `id_shop_group` = ' . (int) $idShopGroup;
        }

        return ' AND (`id_shop` IS NULL OR `id_shop` = 0)'
            . ' AND (`id_shop_group` IS NULL OR `id_shop_group` = 0)';
    }
}
