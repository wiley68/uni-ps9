<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\SmartUcf;

/**
 * Builds the JSON payload for SmartUCF sucfOnlineSessionStart.
 * Field mapping follows the Woo reference (class-gateway.php lines 929-944).
 */
final class SmartUcfPayloadBuilder
{
    /**
     * @param array<string, mixed> $shop     Cached shop configuration
     * @param array<string, mixed> $snapshot Financing snapshot row
     * @return array<string, mixed>
     */
    public function build(array $shop, array $snapshot): array
    {
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

        return [
            'user' => (string) ($shop['uni_user'] ?? ''),
            'pass' => (string) ($shop['uni_password'] ?? ''),
            'orderNo' => (string) $snapshot['order_reference'],
            'clientFirstName' => $this->clean((string) ($customer['first_name'] ?? '')),
            'clientLastName' => $this->clean((string) ($customer['last_name'] ?? '')),
            'clientPhone' => $this->clean((string) ($customer['phone'] ?? '')),
            'clientEmail' => $this->clean((string) ($customer['email'] ?? '')),
            'clientDeliveryAddress' => $this->clean($deliveryAddress),
            'onlineProductCode' => (string) $snapshot['kop_code'],
            'totalPrice' => $this->formatAmount((float) $snapshot['order_total'], $shop),
            'initialPayment' => $this->formatAmount((float) $snapshot['first_installment'], $shop),
            'installmentCount' => (int) $snapshot['months'],
            'monthlyPayment' => $this->formatAmount((float) $snapshot['monthly_installment'], $shop),
            'items' => $this->buildItems($lines, $shop),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @param array<string, mixed> $shop
     * @return array<int, array<string, mixed>>
     */
    private function buildItems(array $lines, array $shop): array
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
                'singlePrice' => $this->convertUnitPrice(
                    (float) ($line['total'] ?? 0),
                    max(1, (int) ($line['quantity'] ?? 1)),
                    $shop
                ),
            ];
        }

        return $items;
    }

    /**
     * Currency conversion matching Woo reference (lines 855-869).
     * uni_eur: 0 = BGN only, 1 = BGN+EUR display (bank works in BGN),
     *          2 = EUR+BGN display (bank works in EUR), 3 = EUR only
     */
    private function convertUnitPrice(float $lineTotal, int $quantity, array $shop): string
    {
        $unitPrice = $lineTotal / $quantity;
        $uniEur = (int) ($shop['uni_eur'] ?? 0);
        $currencyIso = (string) ($shop['_currency_iso'] ?? 'BGN');

        if ($uniEur === 1 && strtoupper($currencyIso) === 'EUR') {
            $unitPrice = $unitPrice * 1.95583;
        } elseif (in_array($uniEur, [2, 3], true) && strtoupper($currencyIso) === 'BGN') {
            $unitPrice = $unitPrice / 1.95583;
        }

        return number_format(abs($unitPrice), 2, '.', '');
    }

    /** @param array<string, mixed> $shop */
    private function formatAmount(float $amount, array $shop): string
    {
        return number_format(abs($amount), 2, '.', '');
    }

    private function clean(string $value): string
    {
        return str_replace(["'", "\u{2019}"], '', $value);
    }
}
