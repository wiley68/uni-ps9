<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

use PrestaShop\Module\Unipayment\Calculator\CurrencyGate;

/** Verifies native EUR order provenance without changing historical records. */
final class OrderCurrencyGuard
{
    /** @var callable|null */
    private $nativeOrderCurrencyReader;

    /** @param callable|null $nativeOrderCurrencyReader fn(int): array{id_currency:int,currency_iso:string} */
    public function __construct(?callable $nativeOrderCurrencyReader = null)
    {
        $this->nativeOrderCurrencyReader = $nativeOrderCurrencyReader;
    }

    public function assertOrder(CreatedOrder $order): void
    {
        if ($order->idOrder <= 0 || $order->idCurrency <= 0 || !(new CurrencyGate())->supports($order->currencyIso)) {
            throw new \RuntimeException('The financing order currency must be EUR.');
        }
    }

    /** @param array<string, mixed> $snapshot */
    public function assertMatchesSnapshot(CreatedOrder $order, array $snapshot): void
    {
        $this->assertOrder($order);
        if ((int) ($snapshot['id_order'] ?? 0) !== $order->idOrder
            || (int) ($snapshot['id_currency'] ?? 0) !== $order->idCurrency
            || (string) ($snapshot['currency_iso'] ?? '') !== 'EUR'
        ) {
            throw new \RuntimeException('The financing snapshot currency does not match the EUR order.');
        }
    }

    /** @param array<string, mixed> $snapshot */
    public function assertNativeSnapshot(array $snapshot): void
    {
        $idOrder = (int) ($snapshot['id_order'] ?? 0);
        if ($idOrder <= 0) {
            throw new \RuntimeException('The financing order currency is unavailable.');
        }
        if ($this->nativeOrderCurrencyReader !== null) {
            $native = call_user_func($this->nativeOrderCurrencyReader, $idOrder);
        } else {
            $order = new \Order($idOrder);
            if (!\Validate::isLoadedObject($order)) {
                throw new \RuntimeException('The financing order currency is unavailable.');
            }
            $currency = new \Currency((int) $order->id_currency);
            if (!\Validate::isLoadedObject($currency)) {
                throw new \RuntimeException('The financing order currency is unavailable.');
            }
            $native = ['id_currency' => (int) $order->id_currency, 'currency_iso' => (string) $currency->iso_code];
        }
        if (!is_array($native)
            || (int) ($native['id_currency'] ?? 0) <= 0
            || (int) ($native['id_currency'] ?? 0) !== (int) ($snapshot['id_currency'] ?? 0)
            || !(new CurrencyGate())->supports((string) ($native['currency_iso'] ?? ''))
            || (string) ($snapshot['currency_iso'] ?? '') !== 'EUR'
        ) {
            throw new \RuntimeException('The financing snapshot currency does not match the EUR order.');
        }
    }

    /** @param mixed $savedPayload @return array<string, mixed> */
    public function decodeSavedCpPayload($savedPayload, array $snapshot): array
    {
        if (!is_string($savedPayload) || trim($savedPayload) === '') {
            throw new \RuntimeException('The saved Control Panel payload is unavailable.');
        }
        $decoded = json_decode($savedPayload);
        if (!$decoded instanceof \stdClass) {
            throw new \RuntimeException('The saved Control Panel payload is invalid.');
        }
        $payload = get_object_vars($decoded);
        $this->assertSavedCpPayload($payload, $snapshot);

        return $payload;
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $snapshot */
    public function assertSavedCpPayload(array $payload, array $snapshot): void
    {
        $strings = [
            'order_id', 'name', 'phone', 'email', 'address', 'address2',
            'products_id', 'products_name', 'products_q', 'currency', 'version',
        ];
        $amounts = ['price', 'vnoska', 'gpr', 'parva'];
        foreach ($strings as $key) {
            if (!array_key_exists($key, $payload) || !is_string($payload[$key])) {
                throw new \RuntimeException('The saved Control Panel payload structure is invalid.');
            }
        }
        foreach ($amounts as $key) {
            if (!array_key_exists($key, $payload)
                || !(is_int($payload[$key]) || is_float($payload[$key]))
                || !is_finite((float) $payload[$key])
            ) {
                throw new \RuntimeException('The saved Control Panel payload structure is invalid.');
            }
        }
        if (!isset($payload['vnoski'], $payload['type_client'])
            || !is_int($payload['vnoski'])
            || $payload['vnoski'] <= 0
            || !is_int($payload['type_client'])
            || !in_array($payload['type_client'], [0, 1], true)
            || $payload['currency'] !== 'EUR'
            || $payload['order_id'] === ''
            || !hash_equals(substr((string) ($snapshot['order_reference'] ?? ''), 0, 13), $payload['order_id'])
            || abs((float) $payload['price'] - round((float) ($snapshot['order_total'] ?? 0), 2)) > 0.01
        ) {
            throw new \RuntimeException('The saved Control Panel payload does not match the EUR order.');
        }
    }
}
