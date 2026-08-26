<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Configuration;

/**
 * Reads module-local ZIP deployment endpoints from config/environment.php.
 */
final class ModuleDeploymentEnvironment
{
    public const RELATIVE_PATH = 'config/environment.php';
    public const CONTROL_PANEL_URL_KEY = 'control_panel_url';
    public const API_PATH_PREFIX = '/api/v1';

    /** @var string */
    private $configFilePath;

    public function __construct(?string $configFilePath = null)
    {
        $this->configFilePath = $configFilePath ?? (dirname(__DIR__, 2) . '/' . self::RELATIVE_PATH);
    }

    /**
     * Authoritative Control Panel host base (no API suffix), e.g. https://cp.example.com
     */
    public function controlPanelUrl(): string
    {
        $loaded = $this->load();
        $url = $loaded[self::CONTROL_PANEL_URL_KEY] ?? null;
        if (!is_string($url)) {
            throw new \RuntimeException('Control Panel URL is not configured in config/environment.php.');
        }
        $url = rtrim(trim($url), '/');
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('Control Panel URL is invalid in config/environment.php.');
        }

        return $url;
    }

    /**
     * Outbound CP HTTP API base used by ControlPanelClient (host + /api/v1).
     */
    public function controlPanelApiBaseUrl(): string
    {
        return $this->controlPanelUrl() . self::API_PATH_PREFIX;
    }

    public function configFilePath(): string
    {
        return $this->configFilePath;
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        if (!is_file($this->configFilePath) || !is_readable($this->configFilePath)) {
            throw new \RuntimeException('Deployment environment file config/environment.php is missing or unreadable.');
        }

        /** @var mixed $loaded */
        $loaded = include $this->configFilePath;
        if (!is_array($loaded)) {
            throw new \RuntimeException('Deployment environment file config/environment.php must return an array.');
        }

        return $loaded;
    }
}
