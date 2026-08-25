<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Product;

/**
 * Preferred-address selection for popup customer prefill.
 * Order: cart delivery → cart invoice → first valid address.
 */
final class PopupPreferredAddressSelector
{
    /**
     * @param array<int, array<string, mixed>> $addresses Customer address rows (e.g. Customer::getAddresses)
     * @return array<string, mixed>
     */
    public function select(array $addresses, int $deliveryAddressId = 0, int $invoiceAddressId = 0): array
    {
        foreach ([$deliveryAddressId, $invoiceAddressId] as $preferredId) {
            if ($preferredId <= 0) {
                continue;
            }
            foreach ($addresses as $address) {
                if ((int) ($address['id_address'] ?? 0) === $preferredId) {
                    return $address;
                }
            }
        }

        return $addresses[0] ?? [];
    }

    /**
     * @param array<string, mixed> $address
     */
    public function joinAddress(array $address): string
    {
        $parts = [];
        foreach (['address1', 'address2', 'city', 'postcode'] as $field) {
            $value = trim((string) ($address[$field] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return substr(implode(', ', $parts), 0, 256);
    }
}
