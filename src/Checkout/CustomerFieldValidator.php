<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Checkout;

final class CustomerFieldValidator
{
    /** @param array<string, mixed> $customer @return array<string, string> */
    public function validate(array $customer): array
    {
        $result = [
            'first_name' => trim((string) ($customer['first_name'] ?? '')),
            'last_name' => trim((string) ($customer['last_name'] ?? '')),
            'address' => trim((string) ($customer['address'] ?? '')),
            'phone' => $this->phone((string) ($customer['phone'] ?? '')),
            'email' => trim((string) ($customer['email'] ?? '')),
        ];
        if ($result['first_name'] === '' || $result['last_name'] === '' || $result['address'] === '') {
            throw new CheckoutValidationException('Задължителните лични данни са непълни.');
        }
        if (!$this->validPhone($result['phone'])) {
            throw new CheckoutValidationException('Въведете валиден телефонен номер.');
        }
        if (!filter_var($result['email'], FILTER_VALIDATE_EMAIL)) {
            throw new CheckoutValidationException('Въведете валиден e-mail адрес.');
        }

        return $result;
    }

    public function validPhone(string $phone): bool
    {
        return $phone !== '' && (bool) preg_match('/^[-0-9+() ]+$/', $phone) && (bool) preg_match('/\d/', $phone);
    }

    private function phone(string $phone): string
    {
        $value = preg_replace('/[^0-9+() -]/', '', $phone);

        return is_string($value) ? trim($value) : '';
    }
}
