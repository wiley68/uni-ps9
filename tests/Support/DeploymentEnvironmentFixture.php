<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Tests\Support;

/** Test-only isolated copy of the real loader; never edits the live deployment file. */
final class DeploymentEnvironmentFixture
{
    private static ?string $root = null;

    public static function activate(): void
    {
        if (self::$root !== null) {
            return;
        }
        if (class_exists(\PrestaShop\Module\Unipayment\Configuration\ModuleDeploymentEnvironment::class, false)) {
            throw new \RuntimeException('Activate deployment fixture before loading the environment class.');
        }
        self::$root = sys_get_temp_dir() . '/unipayment-deployment-test-' . bin2hex(random_bytes(8));
        mkdir(self::$root . '/src/Configuration', 0700, true);
        mkdir(self::$root . '/config', 0700);
        $source = dirname(__DIR__, 2);
        copy($source . '/src/Configuration/ModuleDeploymentEnvironment.php', self::$root . '/src/Configuration/ModuleDeploymentEnvironment.php');
        copy($source . '/config/environment.php', self::$root . '/config/environment.php');
        require self::$root . '/src/Configuration/ModuleDeploymentEnvironment.php';
        register_shutdown_function(static function (): void {
            @unlink(self::$root . '/config/environment.php');
            @unlink(self::$root . '/src/Configuration/ModuleDeploymentEnvironment.php');
            @rmdir(self::$root . '/src/Configuration');
            @rmdir(self::$root . '/src');
            @rmdir(self::$root . '/config');
            @rmdir(self::$root);
        });
    }

    /** Simulate the next request after editing the sole deployment value. */
    public static function configure(mixed $value): void
    {
        self::activate();
        file_put_contents(self::$root . '/config/environment.php', '<?php return ' . var_export(['control_panel_url' => $value], true) . ';');
        self::newRequest();
    }

    public static function remove(): void
    {
        self::activate();
        unlink(self::$root . '/config/environment.php');
        self::newRequest();
    }

    private static function newRequest(): void
    {
        // Private process cache reset exists only in this non-packaged test helper.
        $property = new \ReflectionProperty(\PrestaShop\Module\Unipayment\Configuration\ModuleDeploymentEnvironment::class, 'origin');
        $property->setValue(null, null);
    }
}
