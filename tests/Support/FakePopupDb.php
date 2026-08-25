<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Tests\Support;

use PrestaShop\Module\Unipayment\Product\PopupSubmissionStates;

final class FakePopupDb
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    public int $nextId = 1;
    public int $affected = 0;
    /** @var list<string> */
    public array $sql = [];

    public function execute(string $sql): bool
    {
        $this->sql[] = $sql;
        if (stripos($sql, 'CREATE TABLE') !== false || stripos($sql, 'DROP TABLE') !== false) {
            return true;
        }
        if (stripos($sql, 'DELETE FROM') !== false) {
            if (preg_match("/expires_at` < '([^']+)'/", $sql, $m)) {
                foreach ($this->rows as $id => $row) {
                    if ((string) $row['expires_at'] < $m[1]
                        && in_array((string) $row['state'], [
                            PopupSubmissionStates::ISSUED,
                            PopupSubmissionStates::FAILED,
                            PopupSubmissionStates::IDENTITY_ACCEPTED,
                        ], true)
                    ) {
                        unset($this->rows[$id]);
                    }
                }
            }

            return true;
        }
        if (preg_match(
            "/INSERT INTO.*?VALUES\s*\(\s*(\d+),\s*'([^']+)',\s*'([^']+)',\s*'([^']+)',\s*'([^']+)',\s*(NULL|\d+),\s*(NULL|\d+),\s*'([^']+)',\s*'([^']+)',\s*'([^']+)'/s",
            $sql,
            $m
        )) {
            $id = $this->nextId++;
            $this->rows[$id] = [
                'id_submission' => $id,
                'id_shop' => (int) $m[1],
                'submission_token' => $m[2],
                'selection_hash' => $m[3],
                'flow' => $m[4],
                'state' => $m[5],
                'id_guest' => $m[6] === 'NULL' ? null : (int) $m[6],
                'id_customer' => $m[7] === 'NULL' ? null : (int) $m[7],
                'id_cart' => null,
                'id_attempt' => null,
                'id_order' => null,
                'order_reference' => null,
                'control_panel_order_id' => null,
                'expires_at' => $m[8],
                'created_at' => $m[9],
                'updated_at' => $m[10],
            ];
            $this->affected = 1;

            return true;
        }
        if (preg_match(
            "/UPDATE.*?SET `state` = 'processing'[\s\S]*WHERE `submission_token` = '([^']+)'\s+AND `state` = 'issued'\s+AND `expires_at` > '([^']+)'/s",
            $sql,
            $m
        )) {
            $this->affected = 0;
            foreach ($this->rows as &$row) {
                if ($row['submission_token'] === $m[1]
                    && $row['state'] === PopupSubmissionStates::ISSUED
                    && $row['expires_at'] > $m[2]
                ) {
                    $row['state'] = PopupSubmissionStates::PROCESSING;
                    $row['updated_at'] = gmdate('Y-m-d H:i:s');
                    $this->affected = 1;
                    break;
                }
            }
            unset($row);

            return true;
        }

        return true;
    }

    /** @return array<string, mixed>|false */
    public function getRow(string $sql)
    {
        if (preg_match("/submission_token` = '([^']+)'/", $sql, $m)) {
            foreach ($this->rows as $row) {
                if ($row['submission_token'] === $m[1]) {
                    return $row;
                }
            }

            return false;
        }
        if (preg_match('/id_submission` = (\d+)/', $sql, $m)) {
            return $this->rows[(int) $m[1]] ?? false;
        }
        if (strpos($sql, 'selection_hash') !== false && preg_match("/selection_hash` = '([^']+)'/", $sql, $m)) {
            $shop = 0;
            if (preg_match('/id_shop` = (\d+)/', $sql, $shopMatch)) {
                $shop = (int) $shopMatch[1];
            }
            $guestNull = strpos($sql, '`id_guest` IS NULL') !== false;
            $customerNull = strpos($sql, '`id_customer` IS NULL') !== false;
            $guestId = 0;
            $customerId = 0;
            if (preg_match('/`id_guest` = (\d+)/', $sql, $g)) {
                $guestId = (int) $g[1];
            }
            if (preg_match('/`id_customer` = (\d+)/', $sql, $c)) {
                $customerId = (int) $c[1];
            }
            $found = null;
            foreach ($this->rows as $row) {
                if ((string) $row['selection_hash'] !== $m[1]
                    || (int) $row['id_shop'] !== $shop
                    || (string) $row['state'] !== PopupSubmissionStates::ISSUED
                ) {
                    continue;
                }
                $rowGuest = (int) ($row['id_guest'] ?? 0);
                $rowCustomer = (int) ($row['id_customer'] ?? 0);
                if ($guestNull && $rowGuest !== 0) {
                    continue;
                }
                if (!$guestNull && $rowGuest !== $guestId) {
                    continue;
                }
                if ($customerNull && $rowCustomer !== 0) {
                    continue;
                }
                if (!$customerNull && $rowCustomer !== $customerId) {
                    continue;
                }
                if ($found === null || (int) $row['id_submission'] > (int) $found['id_submission']) {
                    $found = $row;
                }
            }

            return $found ?? false;
        }

        return false;
    }

    /** @param array<string, mixed> $data */
    public function update(string $table, array $data, string $where): bool
    {
        unset($table);
        $this->affected = 0;
        foreach ($this->rows as &$row) {
            if (!$this->matchesWhere($row, $where)) {
                continue;
            }
            foreach ($data as $key => $value) {
                $row[$key] = $value;
            }
            $this->affected = 1;

            return true;
        }
        unset($row);

        return true;
    }

    public function Affected_Rows(): int
    {
        return $this->affected;
    }

    /** @param array<string, mixed> $row */
    private function matchesWhere(array $row, string $where): bool
    {
        if (preg_match('/id_submission` = (\d+)/', $where, $m) && (int) $row['id_submission'] !== (int) $m[1]) {
            return false;
        }
        if (preg_match("/state` = '([^']+)'/", $where, $m) && (string) $row['state'] !== $m[1]) {
            return false;
        }
        if (strpos($where, 'id_cart` IS NULL OR `id_cart` = 0') !== false && (int) ($row['id_cart'] ?? 0) > 0) {
            return false;
        }

        return true;
    }
}
