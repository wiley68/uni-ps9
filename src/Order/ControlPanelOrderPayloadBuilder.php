<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

final class ControlPanelOrderPayloadBuilder
{
    /** @param array<string, mixed> $snapshot @param array<string, mixed> $shop @return array<string, mixed> */
    public function build(array $snapshot, array $shop): array
    {
        $customer = is_array($snapshot['customer_json'] ?? null) ? $snapshot['customer_json'] : [];
        $addresses = is_array($snapshot['address_json'] ?? null) ? $snapshot['address_json'] : [];
        $invoice = is_array($addresses['invoice'] ?? null) ? $addresses['invoice'] : [];
        $delivery = is_array($addresses['delivery'] ?? null) ? $addresses['delivery'] : [];
        $lines = is_array($snapshot['lines_json'] ?? null) ? $snapshot['lines_json'] : [];
        $ids = $names = $quantities = [];
        foreach ($lines as $line) {
            if (!is_array($line)) continue;
            $ids[] = (int) (($line['id_product_attribute'] ?? 0) ?: ($line['id_product'] ?? 0));
            $names[] = str_replace('_', '-', (string) ($line['name'] ?? ''));
            $quantities[] = max(1, (int) ($line['quantity'] ?? 1));
        }
        $name = trim((string) ($customer['first_name'] ?? '') . ' ' . (string) ($customer['last_name'] ?? ''));
        $process2 = (int) ($shop['uni_proces'] ?? 0) === 1;
        $address = $this->address($invoice);
        $address2 = $this->address($delivery);
        if ($address2 === '') $address2 = $address;
        if ($address2 === '') $address2 = '-';

        $payload = [
            'order_id' => substr((string) $snapshot['order_reference'], 0, 13),
            'name' => substr($name, 0, 65),
            'phone' => substr((string) ($customer['phone'] ?? ''), 0, 45),
            'email' => substr((string) ($customer['email'] ?? ''), 0, 128),
            'address' => substr($address, 0, 256),
            'address2' => substr($address2, 0, 256),
            'price' => round((float) $snapshot['order_total'], 2),
            'vnoska' => round((float) $snapshot['monthly_installment'], 2),
            'gpr' => round((float) $snapshot['gpr'], 2),
            'vnoski' => (int) $snapshot['months'],
            'parva' => round((float) $snapshot['first_installment'], 2),
            'products_id' => implode('_', $ids),
            'products_name' => substr(implode('_', $names), 0, 255),
            'products_q' => implode('_', $quantities),
            'type_client' => !empty($shop['_is_mobile']) ? 0 : 1,
            'currency' => (string) $snapshot['currency_iso'],
            'version' => (string) $snapshot['module_version'],
        ];

        if ($process2) {
            $payload['status'] = 'Изпратен Банка - Процес 2';
            $payload['status_id'] = 'bank_sent_process2';
        }

        return $payload;
    }

    /** @param array<string, mixed> $address */
    private function address(array $address): string
    {
        return trim(implode(', ', array_filter([(string) ($address['address1'] ?? ''), (string) ($address['address2'] ?? ''), (string) ($address['postcode'] ?? ''), (string) ($address['city'] ?? ''), (string) ($address['country'] ?? '')])));
    }
}
