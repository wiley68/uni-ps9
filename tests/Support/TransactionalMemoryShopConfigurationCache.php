<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Tests\Support;

use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationCacheInterface;

/**
 * Memory shop cache with transactional commit/rollback for atomicity tests.
 */
class TransactionalMemoryShopConfigurationCache implements ShopConfigurationCacheInterface
{
    /** @var array<string, array<string, mixed>> */
    private $committed = [];

    /** @var array<string, array<string, mixed>>|null */
    private $working = null;

    /** @var bool */
    public $failNextReplace = false;

    /** @var bool */
    public $wroteShopCache = false;

    /** @var bool */
    public $sqlContainedPlaintextCredential = false;

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

    /** @return array<string, mixed>|null */
    public function getCommitted(string $unicid): ?array
    {
        return $this->committed[$unicid] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function getFresh(string $unicid): ?array
    {
        $map = $this->working !== null ? $this->working : $this->committed;

        return $map[$unicid] ?? null;
    }

    public function replace(string $unicid, array $shopData): bool
    {
        if ($this->failNextReplace) {
            $this->failNextReplace = false;
            throw new \RuntimeException('Forced cache persistence failure');
        }
        $encoded = json_encode($shopData);
        if (is_string($encoded) && (
            strpos($encoded, 'demo-secret-password') !== false
            || strpos($encoded, '"uni_password":"') !== false
        )) {
            // Detect plaintext credential material in the persisted payload.
            if (preg_match('/"uni_(?:user|password)"\s*:\s*"(?!enc:v1:)/', $encoded)) {
                $this->sqlContainedPlaintextCredential = true;
            }
        }
        $map = &$this->activeMapRef();
        $map[$unicid] = $shopData;
        $this->wroteShopCache = true;

        return true;
    }

    public function delete(string $unicid): bool
    {
        $map = &$this->activeMapRef();
        unset($map[$unicid]);

        return true;
    }

    public function clear(): bool
    {
        if ($this->working !== null) {
            $this->working = [];
        } else {
            $this->committed = [];
        }

        return true;
    }

    public function getMetadata(string $unicid): ?array
    {
        if ($this->getFresh($unicid) === null) {
            return null;
        }

        return [
            'fetched_at' => '2026-09-10 10:00:00',
            'expires_at' => '2026-09-11 10:00:00',
            'is_fresh' => true,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function &activeMapRef(): array
    {
        if ($this->working !== null) {
            return $this->working;
        }

        return $this->committed;
    }
}
