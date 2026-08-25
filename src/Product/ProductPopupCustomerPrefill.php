<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Product;

final class ProductPopupCustomerPrefill
{
    /** @var PopupPreferredAddressSelector */
    private $preferredSelector;

    public function __construct(?PopupPreferredAddressSelector $preferredSelector = null)
    {
        $this->preferredSelector = $preferredSelector ?? new PopupPreferredAddressSelector();
    }

    /**
     * @param array<string, mixed> $customer
     * @param array<int, array<string, mixed>> $addresses
     * @return array{first_name:string,last_name:string,address:string,phone:string,email:string,is_logged:bool}
     */
    public function present(bool $isLogged, array $customer, array $addresses, int $deliveryAddressId = 0, int $invoiceAddressId = 0): array
    {
        $empty = ['first_name' => '', 'last_name' => '', 'address' => '', 'phone' => '', 'email' => '', 'is_logged' => false];
        if (!$isLogged) {
            return $empty;
        }

        $address = $this->preferredSelector->select($addresses, $deliveryAddressId, $invoiceAddressId);

        return [
            'first_name' => trim((string) ($address['firstname'] ?? $customer['firstname'] ?? '')),
            'last_name' => trim((string) ($address['lastname'] ?? $customer['lastname'] ?? '')),
            'address' => $this->preferredSelector->joinAddress($address),
            'phone' => trim((string) ($address['phone_mobile'] ?? '')) ?: trim((string) ($address['phone'] ?? '')),
            'email' => trim((string) ($customer['email'] ?? '')),
            'is_logged' => true,
        ];
    }
}
