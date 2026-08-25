<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Product;

use Address;
use Configuration;
use Context;
use Country;
use Customer;

/**
 * Creates a PrestaShop guest Customer + Address for anonymous popup order flows.
 *
 * AUD-001: never reuse an existing Customer by e-mail. E-mail is not proof of
 * identity. Registered accounts must stay untouched; each anonymous apply gets
 * a fresh is_guest=1 customer (PS allows non-unique guest e-mails).
 *
 * Phase 7 ports the class for identity contract tests. Product popup apply does
 * not create guests until the order-lifecycle phase.
 */
final class GuestCustomerFactory
{
    /**
     * @param array<string, string> $customerData Validated Step 2 fields
     * @return array{customer: Customer, address: Address}
     */
    public function ensure(array $customerData, Context $context): array
    {
        $email = trim((string) ($customerData['email'] ?? ''));
        $firstName = trim((string) ($customerData['first_name'] ?? ''));
        $lastName = trim((string) ($customerData['last_name'] ?? ''));
        $phone = trim((string) ($customerData['phone'] ?? ''));
        $addressLine = trim((string) ($customerData['address'] ?? ''));

        if ($email === '' || $firstName === '' || $lastName === '') {
            throw new \RuntimeException('The customer data is incomplete for guest account creation.');
        }

        $customer = $this->createGuestCustomer($email, $firstName, $lastName, $context);
        $address = $this->createGuestAddress($customer, $firstName, $lastName, $phone, $addressLine, $context);

        return ['customer' => $customer, 'address' => $address];
    }

    private function createGuestCustomer(string $email, string $firstName, string $lastName, Context $context): Customer
    {
        $customer = new Customer();
        $customer->firstname = substr($firstName, 0, 255);
        $customer->lastname = substr($lastName, 0, 255);
        $customer->email = substr($email, 0, 255);
        // Random hash only for ObjectModel requirements — guests must not authenticate with it.
        $customer->passwd = md5(bin2hex(random_bytes(16)));
        $customer->is_guest = 1;
        $customer->active = 1;
        $customer->id_default_group = (int) Configuration::get('PS_GUEST_GROUP');
        $customer->id_lang = (int) $context->language->id;
        $customer->id_shop = (int) $context->shop->id;
        $customer->id_shop_group = (int) $context->shop->id_shop_group;

        if (!$customer->add()) {
            throw new \RuntimeException('The guest customer account could not be created.');
        }

        return $customer;
    }

    private function createGuestAddress(
        Customer $customer,
        string $firstName,
        string $lastName,
        string $phone,
        string $addressLine,
        Context $context
    ): Address {
        $defaultCountryId = (int) Configuration::get('PS_COUNTRY_DEFAULT');

        $address = new Address();
        $address->id_customer = (int) $customer->id;
        $address->firstname = substr($firstName, 0, 255);
        $address->lastname = substr($lastName, 0, 255);
        $address->address1 = $addressLine !== '' ? substr($addressLine, 0, 255) : '-';
        $address->phone_mobile = $phone !== '' ? substr($phone, 0, 32) : '';
        $address->city = '-';
        $address->postcode = '0000';
        $address->id_country = $defaultCountryId > 0 ? $defaultCountryId : (int) Country::getByIso('BG');
        $address->alias = 'UniCredit financing';

        if (!$address->add()) {
            throw new \RuntimeException('The guest delivery address could not be created.');
        }

        return $address;
    }
}
