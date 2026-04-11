<?php

/**
 * Copyright since 2024 Ilko Ivanov
 *
 * @author    Ilko Ivanov
 * @copyright Ilko Ivanov
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Service;

use Category;
use Configuration;
use Db;
use PrestaShop\Module\Unipayment\Config\UnipaymentConfig;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Админ мапинг категория -> КОП/промо в DB (само категории ниво 1 под Home).
 */
final class KopMappingService
{
    private const KOP_MAX_LENGTH = 64;

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getMappings(): array
    {
        $categoryIds = $this->getTopLevelCategoryIdsUnderHome((int) Configuration::get('PS_HOME_CATEGORY'));
        $persisted = $this->loadPersistedMappingsIndexed();
        $rows = [];

        foreach ($categoryIds as $categoryId) {
            $category = new Category((int) $categoryId, (int) Configuration::get('PS_LANG_DEFAULT'));
            $row = $this->defaultRow((int) $categoryId);
            $row['name'] = is_array($category->name) ? (string) reset($category->name) : (string) $category->name;

            if (isset($persisted[(int) $categoryId])) {
                $row = array_replace_recursive($row, $persisted[(int) $categoryId]);
                $row['name'] = is_array($category->name) ? (string) reset($category->name) : (string) $category->name;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function saveMappings(array $rows): bool
    {
        $current = [];
        foreach ($this->getMappings() as $row) {
            $current[(int) $row['category_id']] = $row;
        }

        foreach ($rows as $row) {
            $categoryId = isset($row['category_id']) ? (int) $row['category_id'] : 0;
            if ($categoryId <= 0 || !isset($current[$categoryId])) {
                continue;
            }

            $current[$categoryId]['kop'] = isset($row['kop']) ? trim((string) $row['kop']) : '';
            $current[$categoryId]['promo'] = isset($row['promo']) ? trim((string) $row['promo']) : '';
        }

        return $this->writeMappings(array_values($current));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, string>
     */
    public function validateMappings(array $rows): array
    {
        $errors = [];
        $allowedCategoryIds = [];
        foreach ($this->getMappings() as $row) {
            $allowedCategoryIds[(int) $row['category_id']] = true;
        }

        foreach ($rows as $index => $row) {
            $rowNum = $index + 1;
            $categoryId = isset($row['category_id']) ? (int) $row['category_id'] : 0;
            if ($categoryId <= 0 || !isset($allowedCategoryIds[$categoryId])) {
                $errors[] = $this->translator->trans(
                    'Invalid category on line %d.',
                    ['%d%' => $rowNum],
                    'Modules.Unipayment.Admin'
                );
                continue;
            }

            $kop = isset($row['kop']) ? trim((string) $row['kop']) : '';
            $promo = isset($row['promo']) ? trim((string) $row['promo']) : '';

            $kopLabel = $this->translator->trans(
                'Standard KOP (category %d)',
                ['%d%' => $categoryId],
                'Modules.Unipayment.Admin'
            );
            $kopError = $this->validateKopValue($kop, $kopLabel);
            if ($kopError !== null) {
                $errors[] = $kopError;
            }

            $promoLabel = $this->translator->trans(
                'Promo KOP (category %d)',
                ['%d%' => $categoryId],
                'Modules.Unipayment.Admin'
            );
            $promoError = $this->validateKopValue($promo, $promoLabel);
            if ($promoError !== null) {
                $errors[] = $promoError;
            }
        }

        return $errors;
    }

    /**
     * Нулира kimb/kimb_time/stats във всички редове в DB (преди пълнене от банка/UI).
     */
    public function refreshMappings(): bool
    {
        $rows = $this->getMappings();
        $ok = true;
        foreach ($rows as $row) {
            $categoryId = (int) ($row['category_id'] ?? 0);
            if ($categoryId <= 0) {
                continue;
            }
            $ok = $this->upsertRow($categoryId, [
                'kop' => (string) ($row['kop'] ?? ''),
                'promo' => (string) ($row['promo'] ?? ''),
                'kimb' => '',
                'kimb_time' => '',
                'stats' => $this->defaultStats(),
            ]) && $ok;
        }

        return $ok;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadPersistedMappingsIndexed(): array
    {
        /** @var mixed $db */
        $db = Db::getInstance();
        $rows = $db->executeS(
            'SELECT `id_category`, `kop`, `promo`, `kimb`, `kimb_time`, `stats`
            FROM `' . _DB_PREFIX_ . UnipaymentConfig::TABLE_KOP_MAPPING . '`'
        );
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $indexed = [];
        foreach ($rows as $dbRow) {
            if (!is_array($dbRow)) {
                continue;
            }
            $categoryId = (int) ($dbRow['id_category'] ?? 0);
            if ($categoryId <= 0) {
                continue;
            }
            $statsRaw = (string) ($dbRow['stats'] ?? '');
            $statsDecoded = json_decode($statsRaw, true);
            $stats = is_array($statsDecoded) ? array_merge($this->defaultStats(), $statsDecoded) : $this->defaultStats();

            $indexed[$categoryId] = [
                'category_id' => $categoryId,
                'kop' => (string) ($dbRow['kop'] ?? ''),
                'promo' => (string) ($dbRow['promo'] ?? ''),
                'kimb' => (string) ($dbRow['kimb'] ?? ''),
                'kimb_time' => (string) ($dbRow['kimb_time'] ?? ''),
                'stats' => $stats,
            ];
        }

        return $indexed;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function writeMappings(array $rows): bool
    {
        $ok = true;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $categoryId = (int) ($row['category_id'] ?? 0);
            if ($categoryId <= 0) {
                continue;
            }
            $ok = $this->upsertRow($categoryId, [
                'kop' => (string) ($row['kop'] ?? ''),
                'promo' => (string) ($row['promo'] ?? ''),
                'kimb' => (string) ($row['kimb'] ?? ''),
                'kimb_time' => (string) ($row['kimb_time'] ?? ''),
                'stats' => isset($row['stats']) && is_array($row['stats']) ? $row['stats'] : $this->defaultStats(),
            ]) && $ok;
        }

        return $ok;
    }

    /**
     * @param array{kop: string, promo: string, kimb: string, kimb_time: string, stats: array<string, mixed>} $row
     */
    private function upsertRow(int $categoryId, array $row): bool
    {
        /** @var mixed $db */
        $db = Db::getInstance();
        $table = _DB_PREFIX_ . UnipaymentConfig::TABLE_KOP_MAPPING;
        $statsJson = json_encode($row['stats'], JSON_UNESCAPED_UNICODE);
        if (!is_string($statsJson)) {
            return false;
        }

        $kimbTimeVal = $row['kimb_time'];
        $kimbTimeInt = is_numeric($kimbTimeVal) ? (int) $kimbTimeVal : 0;

        $data = [
            'id_category' => (int) $categoryId,
            'kop' => $row['kop'],
            'promo' => $row['promo'],
            'kimb' => $row['kimb'],
            'kimb_time' => $kimbTimeInt,
            'stats' => $statsJson,
            'date_upd' => date('Y-m-d H:i:s'),
        ];

        $exists = (int) $db->getValue(
            'SELECT `id_category` FROM `' . $table . '` WHERE `id_category` = ' . (int) $categoryId
        ) > 0;
        if ($exists) {
            return (bool) $db->update(
                UnipaymentConfig::TABLE_KOP_MAPPING,
                $data,
                '`id_category` = ' . (int) $categoryId
            );
        }

        $data['date_add'] = date('Y-m-d H:i:s');

        return (bool) $db->insert(UnipaymentConfig::TABLE_KOP_MAPPING, $data);
    }

    /** @return list<int> Само директните подкатегории на Home (ниво 1). */
    private function getTopLevelCategoryIdsUnderHome(int $homeCategoryId): array
    {
        if ($homeCategoryId <= 0) {
            return [];
        }

        $category = new Category($homeCategoryId);
        $children = $category->getSubCategories((int) Configuration::get('PS_LANG_DEFAULT'));
        if (!is_array($children) || $children === []) {
            return [];
        }

        $ids = [];
        $seen = [];
        foreach ($children as $child) {
            $id = (int) ($child['id_category'] ?? 0);
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * @return array<string, string>
     */
    private function defaultStats(): array
    {
        return [
            'kimb_3' => '',
            'glp_3' => '',
            'kimb_4' => '',
            'glp_4' => '',
            'kimb_5' => '',
            'glp_5' => '',
            'kimb_6' => '',
            'glp_6' => '',
            'kimb_9' => '',
            'glp_9' => '',
            'kimb_10' => '',
            'glp_10' => '',
            'kimb_12' => '',
            'glp_12' => '',
            'kimb_15' => '',
            'glp_15' => '',
            'kimb_18' => '',
            'glp_18' => '',
            'kimb_24' => '',
            'glp_24' => '',
            'kimb_30' => '',
            'glp_30' => '',
            'kimb_36' => '',
            'glp_36' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultRow(int $categoryId): array
    {
        return [
            'category_id' => $categoryId,
            'name' => '',
            'kop' => '',
            'promo' => '',
            'kimb' => '',
            'kimb_time' => '',
            'stats' => $this->defaultStats(),
        ];
    }

    /**
     * @return string|null съобщение за грешка или null при валидна стойност/празно
     */
    private function validateKopValue(string $value, string $fieldLabel): ?string
    {
        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > self::KOP_MAX_LENGTH) {
            return $this->translator->trans(
                '%field% is too long (maximum %max% characters).',
                ['%field%' => $fieldLabel, '%max%' => (string) self::KOP_MAX_LENGTH],
                'Modules.Unipayment.Admin'
            );
        }

        // Allow bank-provided symbols; reject only control characters/new lines.
        if (preg_match('/[\x00-\x1F\x7F]/u', $value)) {
            return $this->translator->trans(
                '%field% contains control characters that are not allowed.',
                ['%field%' => $fieldLabel],
                'Modules.Unipayment.Admin'
            );
        }

        return null;
    }
}
