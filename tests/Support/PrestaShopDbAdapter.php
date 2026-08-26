<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Tests\Support;

final class PrestaShopDbAdapter implements PrestaShopDbConnection
{
    /** @var \Db */
    private $database;

    public function __construct(\Db $database)
    {
        $this->database = $database;
    }

    public static function wrap(\Db $database): self
    {
        return new self($database);
    }

    /** @return array<int, array<string, mixed>>|false|null */
    public function executeS(string $sql)
    {
        return \call_user_func([$this->database, 'executeS'], $sql);
    }
}
