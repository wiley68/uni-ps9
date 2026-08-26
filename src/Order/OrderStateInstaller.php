<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

final class OrderStateInstaller
{
    public const AWAITING = 'UNIPAYMENT_OS_AWAITING';
    public const FAILED = 'UNIPAYMENT_OS_FAILED';
    public const REJECTED = 'UNIPAYMENT_OS_REJECTED';

    private const AWAITING_NAME = 'Awaiting UniCredit financing';
    private const FAILED_NAME = 'UniCredit submission failed';
    /** Merchant-facing BO name (AUD-009 dedicated bank-rejection state). */
    private const REJECTED_NAME = 'Отказано финансиране от УниКредит';
    private const AWAITING_COLOR = '#4169E1';
    private const FAILED_COLOR = '#DC3545';
    private const REJECTED_COLOR = '#B71C1C';

    public function install(): bool
    {
        return $this->create(self::AWAITING, self::AWAITING_NAME, self::AWAITING_COLOR)
            && $this->create(self::FAILED, self::FAILED_NAME, self::FAILED_COLOR)
            && $this->create(self::REJECTED, self::REJECTED_NAME, self::REJECTED_COLOR);
    }

    /**
     * Normal uninstall: delete unused states; preserve referenced historical states;
     * always remove Configuration pointers.
     */
    public function uninstall(): bool
    {
        return $this->purge();
    }

    /**
     * Uninstall OrderState policy (AUD-006): unused delete; referenced preserve.
     */
    public function purge(): bool
    {
        $result = true;
        foreach ([self::AWAITING, self::FAILED, self::REJECTED] as $key) {
            $id = (int) \Configuration::get($key);
            if ($id <= 0) {
                $id = $this->findExistingStateId($this->nameForKey($key), $this->colorForKey($key));
            }

            if ($id > 0) {
                if (!$this->isReferenced($id)) {
                    $state = new \OrderState($id);
                    if (\Validate::isLoadedObject($state) && !$state->delete()) {
                        $result = false;
                    }
                }
                // Referenced states are preserved intentionally.
            }

            if (!\Configuration::deleteByName($key)) {
                // Missing key is treated as already clean.
            }
        }

        return $result;
    }

    public function isReferenced(int $orderStateId): bool
    {
        if ($orderStateId <= 0) {
            return false;
        }

        $prefix = _DB_PREFIX_;
        $id = (int) $orderStateId;
        $db = \Db::getInstance();

        if ((bool) $db->getValue(
            'SELECT 1 FROM `' . $prefix . 'orders` WHERE `current_state` = ' . $id
        )) {
            return true;
        }

        return (bool) $db->getValue(
            'SELECT 1 FROM `' . $prefix . 'order_history` WHERE `id_order_state` = ' . $id
        );
    }

    private function create(string $key, string $name, string $color): bool
    {
        $configuredId = (int) \Configuration::get($key);
        if ($configuredId > 0) {
            $existing = new \OrderState($configuredId);
            if (\Validate::isLoadedObject($existing)) {
                return true;
            }
        }

        $reusedId = $this->findExistingStateId($name, $color);
        if ($reusedId > 0) {
            return \Configuration::updateValue($key, $reusedId);
        }

        $state = new \OrderState();
        $state->name = [];
        foreach (\Language::getLanguages(false) as $language) {
            $state->name[(int) $language['id_lang']] = $name;
        }
        $state->color = $color;
        $state->send_email = false;
        $state->module_name = 'unipayment';
        $state->unremovable = false;
        $state->hidden = false;
        $state->logable = false;
        $state->paid = false;
        $state->invoice = false;
        $state->delivery = false;
        $state->shipped = false;

        return $state->add() && \Configuration::updateValue($key, (int) $state->id);
    }

    private function findExistingStateId(string $name, string $color): int
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT os.`id_order_state`
             FROM `' . _DB_PREFIX_ . 'order_state` os
             INNER JOIN `' . _DB_PREFIX_ . 'order_state_lang` osl
               ON osl.`id_order_state` = os.`id_order_state`
             WHERE os.`module_name` = \'unipayment\'
               AND os.`color` = \'' . pSQL($color) . '\'
               AND osl.`name` = \'' . pSQL($name) . '\'
             ORDER BY os.`id_order_state` ASC'
        );
        if (!is_array($rows) || $rows === []) {
            return 0;
        }

        return (int) ($rows[0]['id_order_state'] ?? 0);
    }

    private function nameForKey(string $key): string
    {
        if ($key === self::FAILED) {
            return self::FAILED_NAME;
        }
        if ($key === self::REJECTED) {
            return self::REJECTED_NAME;
        }

        return self::AWAITING_NAME;
    }

    private function colorForKey(string $key): string
    {
        if ($key === self::FAILED) {
            return self::FAILED_COLOR;
        }
        if ($key === self::REJECTED) {
            return self::REJECTED_COLOR;
        }

        return self::AWAITING_COLOR;
    }
}
