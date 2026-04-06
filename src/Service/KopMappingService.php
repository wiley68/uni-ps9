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
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Админ мапинг категория → КОП/промо в keys/kop.json (списък от всички категории под home).
 */
final class KopMappingService
{
    private const KOP_MAX_LENGTH = 64;

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getMappings(): array
    {
        $categoryIds = $this->getAllCategoryIds((int) Configuration::get('PS_HOME_CATEGORY'));
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
     * Нулира kimb/kimb_time/stats във всички редове и записва kop.json (преди пълнене от банка/UI).
     */
    public function refreshMappings(): bool
    {
        $rows = $this->getMappings();
        foreach ($rows as &$row) {
            $row['kimb'] = '';
            $row['kimb_time'] = '';
            $row['stats'] = $this->defaultStats();
        }

        return $this->writeMappings($rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadPersistedMappingsIndexed(): array
    {
        $filePath = $this->getStorageFilePath();
        if (!is_file($filePath)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($filePath), true);
        if (!is_array($decoded)) {
            return [];
        }

        $indexed = [];
        foreach ($decoded as $row) {
            if (!is_array($row) || !isset($row['category_id'])) {
                continue;
            }
            $indexed[(int) $row['category_id']] = $row;
        }

        return $indexed;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function writeMappings(array $rows): bool
    {
        $filePath = $this->getStorageFilePath();
        return false !== file_put_contents($filePath, (string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /** Път към keys/kop.json под корена на модула. */
    private function getStorageFilePath(): string
    {
        $moduleRoot = dirname(__DIR__, 2);
        $keysDir = $moduleRoot . '/keys';

        if (!is_dir($keysDir)) {
            mkdir($keysDir, 0755, true);
        }

        return $keysDir . '/kop.json';
    }

    /**
     * @return array<int, int>
     */
    private function getAllCategoryIds(int $parentId): array
    {
        $ids = [$parentId];
        $category = new Category($parentId);
        $children = $category->getSubCategories((int) Configuration::get('PS_LANG_DEFAULT'));
        foreach ($children as $child) {
            $ids = array_merge($ids, $this->getAllCategoryIds((int) $child['id_category']));
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
