<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Checkout;

final class CustomerFieldValidator
{
    /** @param array<string, mixed> $shop @param array<string, mixed> $customer @param array<string, mixed> $posted @return array<string, string> */
    public function validate(array $shop, array $customer, array $posted): array
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
        if ((int) ($shop['uni_proces'] ?? 0) !== 1) {
            return $result;
        }

        $egn = preg_replace('/\D/', '', (string) ($posted['egn'] ?? ''));
        $egn = is_string($egn) ? $egn : '';
        $phone2 = $this->phone((string) ($posted['phone2'] ?? ''));
        if (!$this->validEgn($egn)) {
            throw new CheckoutValidationException('Въведете валидно ЕГН (10 цифри, първите 8 — дата YYYYMMDD).');
        }
        if (!$this->validPhone($phone2)) {
            throw new CheckoutValidationException('Въведете валиден втори телефонен номер.');
        }
        $result['egn'] = $egn;
        $result['phone2'] = $phone2;

        return $result;
    }

    public function validEgn(string $egn): bool
    {
        if (!preg_match('/^\d{10}$/', $egn)) {
            return false;
        }

        return checkdate((int) substr($egn, 4, 2), (int) substr($egn, 6, 2), (int) substr($egn, 0, 4));
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
