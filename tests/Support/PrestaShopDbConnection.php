<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Tests\Support;

/** @internal IDE/runtime helper for PrestaShop Db methods used in tests */
interface PrestaShopDbConnection
{
    /** @return array<int, array<string, mixed>>|false|null */
    public function executeS(string $sql);
}
