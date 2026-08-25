<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Product;

use Address;
use Cart;
use Configuration;
use Context;
use Country;
use Customer;
use Validate;

/**
 * Resolves delivery/invoice Address for logged-in popup direct orders (AUD-010).
 * Reuses exact semantic matches; otherwise creates a new Address. Never mutates existing rows.
 *
 * Phase 7 apply uses preferred-id / ownership / exact-match reads only.
 * createAddress remains for later order-lifecycle apply.
 */
final class PopupOrderAddressResolver
{
    private const ALIAS_BASE = 'UniCredit financing';

    /** @var PopupPreferredAddressSelector */
    private $preferredSelector;

    public function __construct(?PopupPreferredAddressSelector $preferredSelector = null)
    {
        $this->preferredSelector = $preferredSelector ?? new PopupPreferredAddressSelector();
    }

    /**
     * @param array<string, string> $customerData Validated Step 2 fields
     */
    public function resolveForLoggedInCustomer(
        Customer $customer,
        array $customerData,
        Context $context,
        ?Cart $cart = null
    ): int {
        $customerId = (int) $customer->id;
        if ($customerId <= 0) {
            throw new \RuntimeException('The authenticated customer is invalid for address resolution.');
        }

        $deliveryId = $cart instanceof Cart ? (int) $cart->id_address_delivery : 0;
        $invoiceId = $cart instanceof Cart ? (int) $cart->id_address_invoice : 0;
        $preferredId = $this->resolvePreferredAddressId($customerId, $deliveryId, $invoiceId, (int) $context->language->id);

        $desired = $this->buildDesiredFields($customerData, $preferredId);
        $matchId = $this->findExactMatch($customerId, $customerData, (int) $context->language->id);
        if ($matchId > 0) {
            return $matchId;
        }

        return $this->createAddress($customerId, $desired);
    }

    public function resolvePreferredAddressId(
        int $customerId,
        int $deliveryAddressId,
        int $invoiceAddressId,
        int $idLang
    ): int {
        if ($customerId <= 0) {
            return 0;
        }

        $rows = $this->loadAddressRows($customerId, $idLang);
        $selected = $this->preferredSelector->select($rows, $deliveryAddressId, $invoiceAddressId);
        $id = (int) ($selected['id_address'] ?? 0);
        if ($id <= 0 || !$this->isOwnedActiveAddress($id, $customerId)) {
            return 0;
        }

        return $id;
    }

    public function assertOwnedActiveAddress(int $addressId, int $customerId): bool
    {
        return $this->isOwnedActiveAddress($addressId, $customerId);
    }

    /**
     * @param array<string, string> $customerData
     * @return array{
     *   firstname: string,
     *   lastname: string,
     *   address1: string,
     *   address2: string,
     *   city: string,
     *   postcode: string,
     *   id_country: int,
     *   id_state: int,
     *   phone_mobile: string
     * }
     */
    private function buildDesiredFields(array $customerData, int $preferredId): array
    {
        $firstname = $this->normalizeName((string) ($customerData['first_name'] ?? ''));
        $lastname = $this->normalizeName((string) ($customerData['last_name'] ?? ''));
        $address1 = $this->normalizeLine((string) ($customerData['address'] ?? ''));
        $phoneMobile = $this->normalizePhone((string) ($customerData['phone'] ?? ''));

        $address2 = '';
        $city = '-';
        $postcode = '0000';
        $idCountry = (int) Configuration::get('PS_COUNTRY_DEFAULT');
        if ($idCountry <= 0) {
            $idCountry = (int) Country::getByIso('BG');
        }
        $idState = 0;

        if ($preferredId > 0) {
            $preferred = new Address($preferredId);
            if (Validate::isLoadedObject($preferred) && !(bool) $preferred->deleted) {
                $address2 = trim((string) $preferred->address2);
                $city = trim((string) $preferred->city) !== '' ? trim((string) $preferred->city) : '-';
                $postcode = trim((string) $preferred->postcode) !== '' ? trim((string) $preferred->postcode) : '0000';
                $idCountry = (int) $preferred->id_country > 0 ? (int) $preferred->id_country : $idCountry;
                $idState = (int) $preferred->id_state;
            }
        }

        if ($address1 === '') {
            $address1 = '-';
        }

        return [
            'firstname' => substr($firstname, 0, 255),
            'lastname' => substr($lastname, 0, 255),
            'address1' => substr($address1, 0, 128),
            'address2' => substr($address2, 0, 128),
            'city' => substr($city, 0, 64),
            'postcode' => substr($postcode, 0, 12),
            'id_country' => $idCountry,
            'id_state' => $idState,
            'phone_mobile' => substr($phoneMobile, 0, 32),
        ];
    }

    /**
     * Exact reuse: same customer Address whose contact/text equals submitted Step 2.
     *
     * @param array<string, string> $customerData
     */
    public function findExactMatch(int $customerId, array $customerData, int $idLang): int
    {
        $firstname = $this->normalizeName((string) ($customerData['first_name'] ?? ''));
        $lastname = $this->normalizeName((string) ($customerData['last_name'] ?? ''));
        $phoneMobile = $this->normalizePhone((string) ($customerData['phone'] ?? ''));
        $popupAddress = $this->normalizeLine((string) ($customerData['address'] ?? ''));

        foreach ($this->loadAddressRows($customerId, $idLang) as $row) {
            $id = (int) ($row['id_address'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $address = new Address($id);
            if (!Validate::isLoadedObject($address) || (bool) $address->deleted) {
                continue;
            }
            if ($this->normalizeName((string) $address->firstname) !== $firstname) {
                continue;
            }
            if ($this->normalizeName((string) $address->lastname) !== $lastname) {
                continue;
            }
            $existingPhone = $this->normalizePhone(self::effectiveContactPhone(
                (string) $address->phone_mobile,
                (string) $address->phone
            ));
            if ($existingPhone !== $phoneMobile) {
                continue;
            }

            $existingA1 = $this->normalizeLine((string) $address->address1);
            $existingJoined = $this->normalizeLine($this->preferredSelector->joinAddress([
                'address1' => (string) $address->address1,
                'address2' => (string) $address->address2,
                'city' => (string) $address->city,
                'postcode' => (string) $address->postcode,
            ]));
            if ($popupAddress === $existingA1 || $popupAddress === $existingJoined) {
                return $id;
            }
        }

        return 0;
    }

    /**
     * @param array{
     *   firstname: string,
     *   lastname: string,
     *   address1: string,
     *   address2: string,
     *   city: string,
     *   postcode: string,
     *   id_country: int,
     *   id_state: int,
     *   phone_mobile: string
     * } $desired
     */
    private function createAddress(int $customerId, array $desired): int
    {
        $address = new Address();
        $address->id_customer = $customerId;
        $address->firstname = $desired['firstname'];
        $address->lastname = $desired['lastname'];
        $address->address1 = $desired['address1'];
        $address->address2 = $desired['address2'];
        $address->city = $desired['city'];
        $address->postcode = $desired['postcode'];
        $address->id_country = $desired['id_country'];
        $address->id_state = $desired['id_state'];
        $address->phone_mobile = $desired['phone_mobile'];
        $address->phone = '';
        $address->alias = $this->nextAlias($customerId);
        $address->deleted = false;

        try {
            if (!$address->add()) {
                throw new ProductPopupValidationException([
                    'address' => 'Личните данни не могат да бъдат записани. Моля, проверете въведената информация.',
                ]);
            }
        } catch (ProductPopupValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new ProductPopupValidationException([
                'address' => 'Личните данни не могат да бъдат записани. Моля, проверете въведената информация.',
            ]);
        }

        return (int) $address->id;
    }

    /**
     * Prefill/exact-match phone parity: phone_mobile if non-empty, otherwise phone.
     */
    public static function effectiveContactPhone(string $phoneMobile, string $phone): string
    {
        $mobile = trim($phoneMobile);
        if ($mobile !== '') {
            return $mobile;
        }

        return trim($phone);
    }

    private function nextAlias(int $customerId): string
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT `alias` FROM `' . _DB_PREFIX_ . 'address`
             WHERE `id_customer` = ' . (int) $customerId . ' AND `deleted` = 0'
        );
        $used = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $used[mb_strtolower(trim((string) ($row['alias'] ?? '')), 'UTF-8')] = true;
            }
        }

        $candidate = self::ALIAS_BASE;
        $n = 1;
        while (isset($used[mb_strtolower($candidate, 'UTF-8')])) {
            ++$n;
            $candidate = self::ALIAS_BASE . ' ' . $n;
        }

        return $candidate;
    }

    private function isOwnedActiveAddress(int $addressId, int $customerId): bool
    {
        $address = new Address($addressId);
        if (!Validate::isLoadedObject($address) || (bool) $address->deleted) {
            return false;
        }

        return (int) $address->id_customer === $customerId;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadAddressRows(int $customerId, int $idLang): array
    {
        $customer = new Customer($customerId);
        if (!Validate::isLoadedObject($customer)) {
            return [];
        }
        $rows = $customer->getAddresses($idLang);

        return is_array($rows) ? $rows : [];
    }

    private function normalizeName(string $value): string
    {
        return $this->normalizeLine($value);
    }

    private function normalizePhone(string $value): string
    {
        return preg_replace('/\s+/u', '', trim($value)) ?? trim($value);
    }

    private function normalizeLine(string $value): string
    {
        $value = trim($value);
        $collapsed = preg_replace('/\s+/u', ' ', $value);

        return is_string($collapsed) ? $collapsed : $value;
    }
}
