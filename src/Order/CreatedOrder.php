<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

final class CreatedOrder
{
    public $idOrder;
    public $reference;
    public $total;
    public $currencyIso;
    public $idCurrency;
    public $customer;
    public $addresses;
    public $lines;

    /** @param array<string, string> $customer @param array<string, mixed> $addresses @param array<int, array<string, mixed>> $lines */
    public function __construct(int $idOrder, string $reference, float $total, string $currencyIso, int $idCurrency, array $customer, array $addresses, array $lines)
    {
        $this->idOrder = $idOrder;
        $this->reference = substr($reference, 0, 13);
        $this->total = round($total, 2);
        $this->currencyIso = strtoupper($currencyIso);
        $this->idCurrency = $idCurrency;
        $this->customer = $customer;
        $this->addresses = $addresses;
        $this->lines = $lines;
    }
}
