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
     * Validates Step 2 customer fields. When $requireEgn is true (Process 2),
     * EGN and secondary phone are required, matching Woo Process 2.
     *
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public function validate(array $input, bool $requireEgn = false): array
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
        if ($requireEgn) {
            $egn = preg_replace('/\D/', '', (string) ($input['egn'] ?? ''));
            $egn = is_string($egn) ? $egn : '';
            $phone2 = $this->phone($input['phone2'] ?? '');
            if ($egn === '') {
                $errors['egn'] = 'Полето е задължително.';
            } elseif (!$this->validEgn($egn)) {
                $errors['egn'] = 'ЕГН трябва да съдържа 10 цифри. Първите 8 трябва да са валидна дата във формат ГГГГММДД.';
            }
            if ($phone2 === '') {
                $errors['phone2'] = 'Полето е задължително.';
            } elseif (!$this->validPhone($phone2)) {
                $errors['phone2'] = 'Вторият телефон може да съдържа цифри, интервали, +, -, ( и ).';
            }
            if (!isset($errors['egn'])) {
                $customer['egn'] = $egn;
            }
            if (!isset($errors['phone2'])) {
                $customer['phone2'] = $phone2;
            }
        }
        if ($errors !== []) {
            throw new ProductPopupValidationException($errors);
        }

        return $customer;
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
