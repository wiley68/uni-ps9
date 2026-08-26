<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Tests\Support;

/**
 * Central safety gate for CLI tests against a bootstrapped PrestaShop install.
 *
 * Suite is selected by the runner via UNIPAYMENT_TEST_SUITE:
 *   safe | runtime | destructive | all
 */
final class TestSuiteGuard
{
    public const SUITE_SAFE = 'safe';
    public const SUITE_RUNTIME = 'runtime';
    public const SUITE_DESTRUCTIVE = 'destructive';
    public const SUITE_ALL = 'all';

    public const ENV_SUITE = 'UNIPAYMENT_TEST_SUITE';
    public const ENV_ALLOW_DESTRUCTIVE = 'UNIPAYMENT_ALLOW_DESTRUCTIVE_DB_TESTS';
    public const ENV_TEST_DATABASE = 'UNIPAYMENT_TEST_DATABASE';
    public const ENV_TEST_DB_NAME = 'UNIPAYMENT_TEST_DB_NAME';

    /** @var list<string> */
    private const BLOCKED_DESTRUCTIVE_DATABASES = [
        'presta8',
        'presta9',
    ];

    public static function currentSuite(): string
    {
        $suite = getenv(self::ENV_SUITE);
        if (!is_string($suite) || $suite === '') {
            return self::SUITE_SAFE;
        }

        return $suite;
    }

    public static function allowsRuntimeIntegration(): bool
    {
        $suite = self::currentSuite();

        return $suite === self::SUITE_RUNTIME
            || $suite === self::SUITE_DESTRUCTIVE
            || $suite === self::SUITE_ALL;
    }

    public static function skipUnlessRuntimeIntegration(string $label = 'runtime integration'): void
    {
        if (self::allowsRuntimeIntegration()) {
            return;
        }

        fwrite(STDOUT, 'SKIP (' . $label . '; use composer test:runtime)' . PHP_EOL);
        exit(0);
    }

    public static function requireDestructiveOptIn(): void
    {
        if (self::destructiveOptInGranted()) {
            return;
        }

        if (self::currentSuite() !== self::SUITE_DESTRUCTIVE && self::currentSuite() !== self::SUITE_ALL) {
            fwrite(STDOUT, 'SKIP (destructive DB test; use composer test:destructive)' . PHP_EOL);
            exit(0);
        }

        if (getenv(self::ENV_ALLOW_DESTRUCTIVE) !== '1') {
            fwrite(STDOUT, 'SKIP (destructive DB test not enabled; set ' . self::ENV_ALLOW_DESTRUCTIVE . '=1)' . PHP_EOL);
            exit(0);
        }

        fwrite(STDOUT, 'SKIP (destructive DB test requires ' . self::ENV_TEST_DATABASE . '=1)' . PHP_EOL);
        exit(0);
    }

    public static function destructiveOptInGranted(): bool
    {
        if (self::currentSuite() !== self::SUITE_DESTRUCTIVE && self::currentSuite() !== self::SUITE_ALL) {
            return false;
        }

        return getenv(self::ENV_ALLOW_DESTRUCTIVE) === '1'
            && getenv(self::ENV_TEST_DATABASE) === '1';
    }

    public static function assertSafeDestructiveDatabase(): void
    {
        if (self::destructiveDatabaseAllowed()) {
            return;
        }

        $databaseName = defined('_DB_NAME_') ? (string) _DB_NAME_ : '(unknown)';

        fwrite(
            STDERR,
            'FAIL: refusing destructive DB test against "' . $databaseName . '". '
            . 'Use an isolated test database (name contains _test/_testing) or set '
            . self::ENV_TEST_DB_NAME . ' to the exact database name.' . PHP_EOL
        );
        exit(1);
    }

    public static function destructiveDatabaseAllowed(): bool
    {
        if (!defined('_DB_NAME_')) {
            return false;
        }

        return self::isDestructiveDatabaseNameAllowed((string) _DB_NAME_);
    }

    public static function isDestructiveDatabaseNameAllowed(string $databaseName): bool
    {
        return self::isExplicitTestDatabaseName($databaseName)
            || self::looksLikeTestDatabaseName($databaseName);
    }

    public static function guardDestructiveDatabase(string $label = 'destructive DB test'): void
    {
        self::requireDestructiveOptIn();

        if (!is_file(self::prestashopConfigPath())) {
            fwrite(STDOUT, 'SKIP (' . $label . '; PS config missing)' . PHP_EOL);
            exit(0);
        }

        require_once self::prestashopConfigPath();
        self::assertSafeDestructiveDatabase();
    }

    public static function prestashopConfigPath(): string
    {
        return dirname(__DIR__, 4) . '/config/config.inc.php';
    }

    public static function prestashopConfigAvailable(): bool
    {
        return is_file(self::prestashopConfigPath());
    }

    private static function isExplicitTestDatabaseName(string $databaseName): bool
    {
        $expected = getenv(self::ENV_TEST_DB_NAME);

        return is_string($expected)
            && $expected !== ''
            && hash_equals($expected, $databaseName);
    }

    private static function looksLikeTestDatabaseName(string $databaseName): bool
    {
        $normalized = strtolower($databaseName);
        if (str_contains($normalized, '_test') || str_contains($normalized, '_testing')) {
            return true;
        }

        foreach (self::BLOCKED_DESTRUCTIVE_DATABASES as $blocked) {
            if (hash_equals(strtolower($blocked), $normalized)) {
                return false;
            }
        }

        return false;
    }
}
