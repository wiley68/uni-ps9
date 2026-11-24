<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Product;

/**
 * Step 2 customer validation for Product popup apply.
 * Name rules mirror PrestaShop Address Validate::isName so Address::add is not the first gate.
 *
 * Message copy summarizes PrestaShop Validate::isName / isAddress.
 * isName rejects digits and ! < > , ; ? = + ( ) @ # " ° { } _ $ % : ¤ |
 * Explanatory name text lists the common safe set (letters, space, hyphen, apostrophe)
 * without claiming rejected characters are allowed.
 */
final class ProductPopupCustomerValidator
{
    /**
     * Validates the supported Step 2 customer fields.
     *
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public function validate(array $input): array
    {
        $customer = [
            'first_name' => $this->text($input['first_name'] ?? ''),
            'last_name' => $this->text($input['last_name'] ?? ''),
            'address' => $this->text($input['address'] ?? ''),
            'phone' => $this->phone($input['phone'] ?? ''),
            'email' => trim((string) ($input['email'] ?? '')),
        ];
        $errors = [];
        foreach (['first_name', 'last_name', 'address'] as $field) {
            if ($customer[$field] === '') {
                $errors[$field] = 'Полето е задължително.';
            }
        }
        if (!isset($errors['first_name']) && !$this->validName($customer['first_name'])) {
            $errors['first_name'] = 'Името може да съдържа само букви, интервал, тире и апостроф.';
        }
        if (!isset($errors['last_name']) && !$this->validName($customer['last_name'])) {
            $errors['last_name'] = 'Фамилията може да съдържа само букви, интервал, тире и апостроф.';
        }
        if (!isset($errors['address']) && !$this->validAddressLine($customer['address'])) {
            $errors['address'] = 'Адресът може да съдържа букви, цифри, интервали и стандартни знаци. Не използвайте символи като <, >, =, +, @, {, }, _, $, %, !, ?.';
        }
        if ($customer['phone'] === '') {
            $errors['phone'] = 'Полето е задължително.';
        } elseif (!$this->validPhone($customer['phone'])) {
            $errors['phone'] = 'Телефонът може да съдържа цифри, интервали, +, -, ( и ).';
        }
        if ($customer['email'] === '') {
            $errors['email'] = 'Полето е задължително.';
        } elseif (!filter_var($customer['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Въведете валиден e-mail адрес, например name@example.com.';
        }
        if ($errors !== []) {
            throw new ProductPopupValidationException($errors);
        }

        return $customer;
    }

    public function validPhone(string $phone): bool
    {
        return $phone !== '' && (bool) preg_match('/^[-0-9+() ]+$/', $phone) && (bool) preg_match('/\d/', $phone);
    }

    /**
     * PrestaShop Address firstname/lastname rule (Validate::isName).
     */
    public function validName(string $name): bool
    {
        if (class_exists(\Validate::class) && is_callable([\Validate::class, 'isName'])) {
            return (bool) \Validate::isName($name);
        }

        return (bool) preg_match('/^[^0-9!<>,;?=+()@#"°{}_$%:¤|]*$/u', $name);
    }

    /**
     * PrestaShop Address address1 rule (Validate::isAddress).
     */
    public function validAddressLine(string $address): bool
    {
        if (class_exists(\Validate::class) && is_callable([\Validate::class, 'isAddress'])) {
            return (bool) \Validate::isAddress($address);
        }

        return $address === '' || (bool) preg_match('/^[^!<>?=+@{}_$%]*$/u', $address);
    }

    /** @param mixed $value */
    private function text($value): string
    {
        return trim(strip_tags((string) $value));
    }

    /** @param mixed $value */
    private function phone($value): string
    {
        $phone = preg_replace('/[^0-9+() -]/', '', (string) $value);

        return is_string($phone) ? trim($phone) : '';
    }
}
