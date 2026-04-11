<?php

/**
 * Copyright since 2024 Ilko Ivanov
 *
 * @author    Ilko Ivanov
 * @copyright Ilko Ivanov
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 *
 * Checkout калкулатор UniCredit: избор на КОП (вкл. промо), getCoeff през {@see UniCreditGetCoeffService} (кеш) или DB stats,
 * ГПР чрез {@see FinancialRateHelper} (аналог на PS 1.7 RATE).
 */

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Service;

use Db;
use PrestaShop\Module\Unipayment\Config\UnipaymentConfig;
use PrestaShop\Module\Unipayment\Helper\FinancialRateHelper;

final class CheckoutUniCalculationService
{
    private readonly UniCreditGetCoeffService $getCoeffService;

    /**
     * @param string $moduleRootPath абсолютен път към корена на модула (за PEM материали при getCoeff)
     */
    public function __construct(
        private readonly string $moduleRootPath,
    ) {
        $this->getCoeffService = new UniCreditGetCoeffService($this->moduleRootPath);
    }

    /**
     * @param list<int> $productCategoryIds първо съвпадение с DB мапинга по този ред (checkout: сечение при мулти-количка)
     *
     * @return array{success: string, result: array<string, mixed>}|null
     */
    public function buildAjaxResponse(
        float $totalPrice,
        float $parva,
        int $installments,
        array $productCategoryIds,
        int $uniEur,
        string $currencyIsoCode,
        string $uniPromo = '',
        string $uniPromoData = '',
        string $uniPromoMeseciZnak = '',
        string $uniPromoMeseci = '',
        string $uniPromoPrice = '',
        string $uniService = '',
        string $uniUser = '',
        string $uniPassword = '',
        string $uniSertificat = 'No',
    ): ?array {
        if ($installments <= 0 || $totalPrice <= 0) {
            return null;
        }

        $row = $this->findFirstKopRowForProductCategories($productCategoryIds);
        if ($row === null) {
            return null;
        }

        $kopCode = $this->resolveEffectiveKop(
            $row,
            $totalPrice,
            $installments,
            $uniPromo,
            $uniPromoData,
            $uniPromoMeseciZnak,
            $uniPromoMeseci,
            $uniPromoPrice
        );

        $useCert = $uniSertificat === 'Yes';
        $kimb = 0.0;
        $glp = 0.0;

        if ($this->canUseBankApi($uniService, $uniUser, $uniPassword)) {
            $fetched = $this->getCoeffService->fetchCoeffWithFileCache(
                $uniService,
                $uniUser,
                $uniPassword,
                $kopCode,
                $installments,
                $useCert
            );
            if ($fetched !== null && $fetched['kimb'] > 0) {
                $kimb = $fetched['kimb'];
                $glp = $fetched['glp'];
            }
        }

        if ($kimb <= 0) {
            $stats = isset($row['stats']) && is_array($row['stats']) ? $row['stats'] : [];
            $kimbKey = 'kimb_' . $installments;
            $glpKey = 'glp_' . $installments;
            $kimb = isset($stats[$kimbKey]) ? (float) str_replace(',', '.', (string) $stats[$kimbKey]) : 0.0;
            $glp = isset($stats[$glpKey]) ? (float) str_replace(',', '.', (string) $stats[$glpKey]) : 0.0;
        }

        if ($kimb <= 0) {
            return null;
        }

        $financed = max(0.0, $totalPrice - $parva);
        if ($financed <= 0) {
            return null;
        }

        $uniMesecnaNum = $financed * $kimb;
        $uniMesecna = number_format($uniMesecnaNum, 2, '.', '');

        $gprm = (FinancialRateHelper::periodicRate(
            (float) $installments,
            -1 * $uniMesecnaNum,
            $financed
        ) * $installments) / ($installments / 12);
        $gprRaw = (pow((1 + $gprm / 12), 12) - 1) * 100;
        $uniGpr = FinancialRateHelper::formatGprPercentForDisplay($gprRaw);

        $uniObshtoStr = number_format($financed, 2, '.', '');
        $sumInstallmentsNum = $uniMesecnaNum * $installments;
        $uniObshtozaplashtaneStr = number_format($sumInstallmentsNum, 2, '.', '');

        [$mesSecond, $obsSecond, $zaplSecond] = $this->secondaryAmountsLegacyStyle(
            $uniMesecnaNum,
            $financed,
            $sumInstallmentsNum,
            $uniEur
        );

        return [
            'success' => 'success',
            'result' => [
                'uni_mesecna' => $uniMesecna,
                'uni_mesecna_second' => $mesSecond,
                'uni_glp' => number_format($glp, 2, '.', ''),
                'uni_obshto' => $uniObshtoStr,
                'uni_obshto_second' => $obsSecond,
                'uni_obshtozaplashtane' => $uniObshtozaplashtaneStr,
                'uni_obshtozaplashtane_second' => $zaplSecond,
                'uni_gpr' => $uniGpr,
                'uni_kop' => $kopCode,
            ],
        ];
    }

    /**
     * Промо логика като PS 1.7 calculateuni (eq / „над праг“ по първия месец от списъка).
     *
     * @param array<string, mixed> $row
     */
    private function resolveEffectiveKop(
        array $row,
        float $totalPrice,
        int $installments,
        string $uniPromo,
        string $uniPromoData,
        string $uniPromoMeseciZnak,
        string $uniPromoMeseci,
        string $uniPromoPrice,
    ): string {
        $standardKop = isset($row['kop']) ? (string) $row['kop'] : '';
        $promoKop = isset($row['promo']) ? (string) $row['promo'] : '';

        $curr = date('Y-m-d H:i');
        $dateTo = date('Y-m-d H:i', strtotime($uniPromoData) ?: 0);
        $promoActive = $uniPromo === 'Yes' && ($uniPromoData === '' || $curr <= $dateTo);

        if (!$promoActive) {
            return $standardKop;
        }

        $promoPrice = (float) str_replace(',', '.', $uniPromoPrice);
        $meseciArr = array_filter(array_map('trim', explode(',', $uniPromoMeseci)));

        if ($uniPromoMeseciZnak === 'eq') {
            if ($totalPrice >= $promoPrice && $meseciArr !== [] && in_array((string) $installments, $meseciArr, true)) {
                return $promoKop !== '' ? $promoKop : $standardKop;
            }
        } else {
            $first = isset($meseciArr[0]) ? (int) $meseciArr[0] : 0;
            if ($totalPrice >= $promoPrice && $first > 0 && $installments >= $first) {
                return $promoKop !== '' ? $promoKop : $standardKop;
            }
        }

        return $standardKop;
    }

    /** Непразни service URL + user + password за извикване на getCoeff. */
    private function canUseBankApi(string $service, string $user, string $password): bool
    {
        return $service !== '' && $user !== '' && $password !== '';
    }

    /**
     * Вторични суми: като PS 1.7 (case 1/2 без проверка на iso_code).
     *
     * @return array{0: float|string, 1: float|string, 2: float|string}
     */
    private function secondaryAmountsLegacyStyle(
        float $mesecnaNum,
        float $financed,
        float $sumInstallmentsNum,
        int $uniEur,
    ): array {
        $r = UnipaymentConfig::EUR_BGN_RATE;
        switch ($uniEur) {
            case 1:
                return [
                    number_format($mesecnaNum / $r, 2, '.', ''),
                    number_format($financed / $r, 2, '.', ''),
                    number_format($sumInstallmentsNum / $r, 2, '.', ''),
                ];
            case 2:
                return [
                    number_format($mesecnaNum * $r, 2, '.', ''),
                    number_format($financed * $r, 2, '.', ''),
                    number_format($sumInstallmentsNum * $r, 2, '.', ''),
                ];
            case 0:
            case 3:
            default:
                return [0.0, 0.0, 0.0];
        }
    }

    /**
     * @param list<int> $productCategoryIds
     *
     * @return array<string, mixed>|null
     */
    private function findFirstKopRowForProductCategories(array $productCategoryIds): ?array
    {
        if ($productCategoryIds === []) {
            return null;
        }
        /** @var mixed $db */
        $db = Db::getInstance();
        $data = $db->executeS(
            'SELECT `id_category`, `kop`, `promo`, `stats`
            FROM `' . _DB_PREFIX_ . UnipaymentConfig::TABLE_KOP_MAPPING . '`'
        );
        if (!is_array($data) || $data === []) {
            return null;
        }
        $rows = [];
        foreach ($data as $row) {
            if (is_array($row)) {
                $statsRaw = (string) ($row['stats'] ?? '');
                $statsDecoded = json_decode($statsRaw, true);
                $rows[] = [
                    'category_id' => (int) ($row['id_category'] ?? 0),
                    'kop' => (string) ($row['kop'] ?? ''),
                    'promo' => (string) ($row['promo'] ?? ''),
                    'stats' => is_array($statsDecoded) ? $statsDecoded : [],
                ];
            }
        }
        if ($rows === []) {
            return null;
        }
        $catIds = array_column($rows, 'category_id');
        $catIdsAsString = array_map('strval', $catIds);
        foreach ($productCategoryIds as $idCategory) {
            $idCategory = (int) $idCategory;
            if ($idCategory <= 0) {
                continue;
            }
            $idx = array_search($idCategory, $catIds, true);
            if ($idx === false) {
                $idx = array_search((string) $idCategory, $catIdsAsString, true);
            }
            if ($idx !== false && isset($rows[$idx]) && is_array($rows[$idx])) {
                return $rows[$idx];
            }
        }

        return null;
    }
}
