<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\SmartUcf;

use PrestaShop\Module\Unipayment\Order\OrderCurrencyGuard;

/**
 * Builds the JSON payload for SmartUCF sucfOnlineSessionStart.
 * Field mapping follows the Woo reference (class-gateway.php lines 929-944).
 */
final class SmartUcfPayloadBuilder
{
    private OrderCurrencyGuard $currencyGuard;

    public function __construct(?OrderCurrencyGuard $currencyGuard = null)
    {
        $this->currencyGuard = $currencyGuard ?? new OrderCurrencyGuard();
    }
    /**
     * @param array<string, mixed> $shop     Cached shop configuration
     * @param array<string, mixed> $snapshot Financing snapshot row
     * @return array<string, mixed>
     */
    public function build(array $shop, array $snapshot): array
    {
        $this->currencyGuard->assertNativeSnapshot($snapshot);
        $customer = is_array($snapshot['customer_json'] ?? null) ? $snapshot['customer_json'] : [];
        $lines = is_array($snapshot['lines_json'] ?? null) ? $snapshot['lines_json'] : [];
        $addresses = is_array($snapshot['address_json'] ?? null) ? $snapshot['address_json'] : [];
        $delivery = is_array($addresses['delivery'] ?? null) ? $addresses['delivery'] : $addresses;

        $deliveryAddress = trim(implode(', ', array_filter([
            (string) ($delivery['address1'] ?? ''),
            (string) ($delivery['city'] ?? ''),
            (string) ($delivery['postcode'] ?? ''),
        ])));
        if ($deliveryAddress === '') {
            $deliveryAddress = trim((string) ($customer['address'] ?? '-'));
        }

        $payload = [
            'user' => trim((string) ($shop['uni_user'] ?? '')),
            'pass' => trim((string) ($shop['uni_password'] ?? '')),
            'orderNo' => (string) $snapshot['order_reference'],
            'clientFirstName' => $this->clean((string) ($customer['first_name'] ?? '')),
            'clientLastName' => $this->clean((string) ($customer['last_name'] ?? '')),
            'clientPhone' => $this->clean((string) ($customer['phone'] ?? '')),
            'clientEmail' => $this->clean((string) ($customer['email'] ?? '')),
            'clientDeliveryAddress' => $this->clean($deliveryAddress),
            'onlineProductCode' => (string) $snapshot['kop_code'],
            'totalPrice' => $this->formatAmount((float) $snapshot['order_total']),
            'initialPayment' => $this->formatAmount((float) $snapshot['first_installment']),
            'installmentCount' => (int) $snapshot['months'],
            'monthlyPayment' => $this->formatAmount((float) $snapshot['monthly_installment']),
            'items' => $this->buildItems($lines),
        ];

        if ($payload['user'] === '' || $payload['pass'] === '') {
            throw new \InvalidArgumentException('SmartUCF credentials are required before payload build.');
        }

        return $payload;
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @return array<int, array<string, mixed>>
     */
    private function buildItems(array $lines): array
    {
        $items = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $items[] = [
                'name' => $this->clean((string) ($line['name'] ?? '')),
                'code' => (int) ($line['id_product'] ?? 0),
                'type' => 0,
                'count' => max(1, (int) ($line['quantity'] ?? 1)),
                'singlePrice' => number_format(
                    abs((float) ($line['total'] ?? 0) / max(1, (int) ($line['quantity'] ?? 1))),
                    2, '.', ''
                ),
            ];
        }

        return $items;
    }

    private function formatAmount(float $amount): string
    {
        return number_format(abs($amount), 2, '.', '');
    }

    private function clean(string $value): string
    {
        return str_replace(["'", "\u{2019}"], '', $value);
    }
}
