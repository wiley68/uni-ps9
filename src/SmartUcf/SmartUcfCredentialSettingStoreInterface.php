<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\SmartUcf;

/**
 * Shop-scoped persistent setting store for encrypted SmartUCF credential envelopes.
 */
interface SmartUcfCredentialSettingStoreInterface
{
    /**
     * Exact-context coherent pair read (both keys, no group/global fallback).
     *
     * @return array{user: ?string, password: ?string}
     */
    public function getPair(int $idShop): array;

    public function get(int $idShop, string $key): ?string;

    public function set(int $idShop, string $key, string $value): void;

    public function delete(int $idShop, string $key): void;

    /** Remove the named key for every shop / context (uninstall). */
    public function deleteByNameAllShops(string $key): void;
}
