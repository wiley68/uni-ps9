<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Tests\Support;

use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCredentialSettingStoreInterface;

/**
 * Shop-scoped in-memory settings with optional transactional working set.
 */
final class InMemorySmartUcfCredentialSettingStore implements SmartUcfCredentialSettingStoreInterface
{
    /** @var array<string, string> */
    private $committed = [];

    /** @var array<string, string>|null */
    private $working = null;

    /** @var int */
    public $setCount = 0;

    /** @var int */
    public $failOnSetNumber = 0;

    /** @var int */
    public $pairReadCount = 0;

    /** @var array<string, string> */
    public $groupScoped = [];

    /** @var array<string, string> */
    public $globalScoped = [];

    public function beginWork(): void
    {
        $this->working = $this->committed;
    }

    public function commitWork(): void
    {
        if ($this->working !== null) {
            $this->committed = $this->working;
            $this->working = null;
        }
    }

    public function rollbackWork(): void
    {
        $this->working = null;
    }

    /** @return array<string, string> */
    public function allCommitted(): array
    {
        return $this->committed;
    }

    public function get(int $idShop, string $key): ?string
    {
        $pair = $this->getPair($idShop);
        if ($key === \PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCredentialRepository::USER_KEY) {
            return $pair['user'];
        }
        if ($key === \PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCredentialRepository::PASSWORD_KEY) {
            return $pair['password'];
        }

        return null;
    }

    /**
     * @return array{user: ?string, password: ?string}
     */
    public function getPair(int $idShop): array
    {
        ++$this->pairReadCount;
        // Exact shop only — never fall back to groupScoped / globalScoped fixtures.
        $map = $this->activeMap();
        $userKey = $this->storageKey($idShop, \PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCredentialRepository::USER_KEY);
        $passwordKey = $this->storageKey($idShop, \PrestaShop\Module\Unipayment\SmartUcf\SmartUcfCredentialRepository::PASSWORD_KEY);

        return [
            'user' => $map[$userKey] ?? null,
            'password' => $map[$passwordKey] ?? null,
        ];
    }

    public function set(int $idShop, string $key, string $value): void
    {
        ++$this->setCount;
        if ($this->failOnSetNumber > 0 && $this->setCount === $this->failOnSetNumber) {
            throw new \RuntimeException('Forced setting write failure #' . $this->setCount);
        }
        $map = &$this->activeMapRef();
        $map[$this->storageKey($idShop, $key)] = $value;
    }

    public function delete(int $idShop, string $key): void
    {
        $map = &$this->activeMapRef();
        unset($map[$this->storageKey($idShop, $key)]);
    }

    public function deleteByNameAllShops(string $key): void
    {
        $map = &$this->activeMapRef();
        foreach (array_keys($map) as $storageKey) {
            if (substr($storageKey, -strlen(':' . $key)) === ':' . $key) {
                unset($map[$storageKey]);
            }
        }
    }

    /** @return array<string, string> */
    private function activeMap(): array
    {
        return $this->working !== null ? $this->working : $this->committed;
    }

    /** @return array<string, string> */
    private function &activeMapRef(): array
    {
        if ($this->working !== null) {
            return $this->working;
        }

        return $this->committed;
    }

    private function storageKey(int $idShop, string $key): string
    {
        return max(0, $idShop) . ':' . $key;
    }
}
