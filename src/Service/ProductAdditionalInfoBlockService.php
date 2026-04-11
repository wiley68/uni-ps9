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

use Db;
use PrestaShop\Module\Unipayment\Config\UnipaymentConfig;
use PrestaShop\Module\Unipayment\DTO\ProductAdditionalInfoRequest;

/**
 * Бизнес логика за блока с UNICredit калкулатор под бутона „Купи“.
 * Hook-ът в модула само събира контекста и подава {@see ProductAdditionalInfoRequest}.
 */
final class ProductAdditionalInfoBlockService
{
    private ?UniCreditGetCoeffService $uniCreditGetCoeffService = null;

    /**
     * @param string $moduleRootPath абсолютен път към корена на модула
     */
    public function __construct(
        private readonly string $moduleRootPath,
    ) {}

    /**
     * @param list<int> $productCategoryIds всички id_category на продукта; използва се първото съвпадение с DB мапинга
     *
     * @return array{assign: array<string, mixed>, should_display: bool}|null null = не показвай блока
     */
    public function buildTemplatePayload(ProductAdditionalInfoRequest $req, array $productCategoryIds): ?array
    {
        $paramsuni = $req->paramsuni;
        if (($paramsuni['uni_status'] ?? '') !== 'Yes') {
            return null;
        }

        $uni_eur = (int) $paramsuni['uni_eur'];
        $deviceis = $this->detectDevice($req->userAgent);

        $uni_service = (int) $paramsuni['uni_testenv'] === 1
            ? (string) $paramsuni['uni_test_service']
            : (string) $paramsuni['uni_production_service'];
        $uni_user = htmlspecialchars_decode((string) $paramsuni['uni_user']);
        $uni_password = htmlspecialchars_decode((string) $paramsuni['uni_password']);
        $uni_shema_current = (int) $paramsuni['uni_shema_current'];

        $uni_categories_kop = $this->loadKopMappingFromDb();
        $uni_key = $this->findKopRowIndexForProductCategories($uni_categories_kop, $productCategoryIds);
        if ($uni_key === false) {
            return null;
        }

        $uni_kop = $this->resolveKopCode($uni_categories_kop, $uni_key, $paramsuni, $req->uniPrice, $uni_shema_current);

        if ($uni_kop === '') {
            return null;
        }

        $getCalc = $req->getCachedUniCalculation;
        $paramsunicalc = $getCalc($req->uniUnicid, $deviceis);
        if (empty($paramsunicalc) || !is_array($paramsunicalc)) {
            return null;
        }

        $row = &$uni_categories_kop[$uni_key];
        $uni_param_kimb = $row['kimb'];
        $stats = isset($row['stats']) && is_array($row['stats']) ? $row['stats'] : [];
        $uni_param_kimb_3 = $stats['kimb_3'] ?? '';
        $uni_param_glp_3 = $stats['glp_3'] ?? '';
        $uni_param_kimb_4 = $stats['kimb_4'] ?? '';
        $uni_param_glp_4 = $stats['glp_4'] ?? '';
        $uni_param_kimb_5 = $stats['kimb_5'] ?? '';
        $uni_param_glp_5 = $stats['glp_5'] ?? '';
        $uni_param_kimb_6 = $stats['kimb_6'] ?? '';
        $uni_param_glp_6 = $stats['glp_6'] ?? '';
        $uni_param_kimb_9 = $stats['kimb_9'] ?? '';
        $uni_param_glp_9 = $stats['glp_9'] ?? '';
        $uni_param_kimb_10 = $stats['kimb_10'] ?? '';
        $uni_param_glp_10 = $stats['glp_10'] ?? '';
        $uni_param_kimb_12 = $stats['kimb_12'] ?? '';
        $uni_param_glp_12 = $stats['glp_12'] ?? '';
        $uni_param_kimb_15 = $stats['kimb_15'] ?? '';
        $uni_param_glp_15 = $stats['glp_15'] ?? '';
        $uni_param_kimb_18 = $stats['kimb_18'] ?? '';
        $uni_param_glp_18 = $stats['glp_18'] ?? '';
        $uni_param_kimb_24 = $stats['kimb_24'] ?? '';
        $uni_param_glp_24 = $stats['glp_24'] ?? '';
        $uni_param_kimb_30 = $stats['kimb_30'] ?? '';
        $uni_param_glp_30 = $stats['glp_30'] ?? '';
        $uni_param_kimb_36 = $stats['kimb_36'] ?? '';
        $uni_param_glp_36 = $stats['glp_36'] ?? '';

        $uni_param_kimb_time = ($row['kimb_time'] ?? '') === '' ? 0 : $row['kimb_time'];
        $current_time = time() - 86400;

        if ((int) $current_time > (int) $uni_param_kimb_time) {
            if ($this->canUseBankCoeffApi($uni_service, $uni_user, $uni_password)) {
                $useCert = ($paramsuni['uni_sertificat'] ?? '') === 'Yes';
                if (!isset($row['stats']) || !is_array($row['stats'])) {
                    $row['stats'] = [];
                }
                $coeffService = $this->getUniCreditGetCoeffService();
                foreach (UnipaymentConfig::KIMB_BANK_INSTALLMENT_COUNTS as $cnt) {
                    $kopForCnt = $this->resolveKopCode($uni_categories_kop, $uni_key, $paramsuni, $req->uniPrice, $cnt);
                    if ($kopForCnt === '') {
                        continue;
                    }
                    $fetched = $coeffService->fetchCoeffWithFileCache(
                        $uni_service,
                        $uni_user,
                        $uni_password,
                        $kopForCnt,
                        $cnt,
                        $useCert
                    );
                    if ($fetched !== null && $fetched['kimb'] > 0) {
                        $row['stats']['kimb_' . $cnt] = (string) $fetched['kimb'];
                        $row['stats']['glp_' . $cnt] = (string) $fetched['glp'];
                    }
                }
                $kimbForCurrent = $this->kimbFromStatsForInstallments($row['stats'], $uni_shema_current);
                $row['kimb'] = $kimbForCurrent > 0 ? (string) $kimbForCurrent : '';
                $row['kimb_time'] = (string) time();
                $this->persistKopRuntimeData($row);
            }
            $stats = isset($row['stats']) && is_array($row['stats']) ? $row['stats'] : [];
            $uni_param_kimb = $row['kimb'];
            $uni_param_kimb_3 = $stats['kimb_3'] ?? '';
            $uni_param_glp_3 = $stats['glp_3'] ?? '';
            $uni_param_kimb_4 = $stats['kimb_4'] ?? '';
            $uni_param_glp_4 = $stats['glp_4'] ?? '';
            $uni_param_kimb_5 = $stats['kimb_5'] ?? '';
            $uni_param_glp_5 = $stats['glp_5'] ?? '';
            $uni_param_kimb_6 = $stats['kimb_6'] ?? '';
            $uni_param_glp_6 = $stats['glp_6'] ?? '';
            $uni_param_kimb_9 = $stats['kimb_9'] ?? '';
            $uni_param_glp_9 = $stats['glp_9'] ?? '';
            $uni_param_kimb_10 = $stats['kimb_10'] ?? '';
            $uni_param_glp_10 = $stats['glp_10'] ?? '';
            $uni_param_kimb_12 = $stats['kimb_12'] ?? '';
            $uni_param_glp_12 = $stats['glp_12'] ?? '';
            $uni_param_kimb_15 = $stats['kimb_15'] ?? '';
            $uni_param_glp_15 = $stats['glp_15'] ?? '';
            $uni_param_kimb_18 = $stats['kimb_18'] ?? '';
            $uni_param_glp_18 = $stats['glp_18'] ?? '';
            $uni_param_kimb_24 = $stats['kimb_24'] ?? '';
            $uni_param_glp_24 = $stats['glp_24'] ?? '';
            $uni_param_kimb_30 = $stats['kimb_30'] ?? '';
            $uni_param_glp_30 = $stats['glp_30'] ?? '';
            $uni_param_kimb_36 = $stats['kimb_36'] ?? '';
            $uni_param_glp_36 = $stats['glp_36'] ?? '';
            $kimb = $this->kimbFromStatsForInstallments($stats, $uni_shema_current);
            if ($kimb <= 0) {
                $kimb = (float) str_replace(',', '.', (string) $row['kimb']);
            }
        } else {
            $kimb = (float) str_replace(',', '.', (string) ($uni_param_kimb ?? ''));
        }

        $uni_price = $req->uniPrice;
        $uni_currency_code = $req->currencyCode;

        $uni_mesecna = number_format($uni_price * $kimb, 2, '.', '');
        $uni_mesecna_3 = number_format($uni_price * (float) $uni_param_kimb_3, 2, '.', '');
        $uni_mesecna_4 = number_format($uni_price * (float) $uni_param_kimb_4, 2, '.', '');
        $uni_mesecna_5 = number_format($uni_price * (float) $uni_param_kimb_5, 2, '.', '');
        $uni_mesecna_6 = number_format($uni_price * (float) $uni_param_kimb_6, 2, '.', '');
        $uni_mesecna_9 = number_format($uni_price * (float) $uni_param_kimb_9, 2, '.', '');
        $uni_mesecna_10 = number_format($uni_price * (float) $uni_param_kimb_10, 2, '.', '');
        $uni_mesecna_12 = number_format($uni_price * (float) $uni_param_kimb_12, 2, '.', '');
        $uni_mesecna_15 = number_format($uni_price * (float) $uni_param_kimb_15, 2, '.', '');
        $uni_mesecna_18 = number_format($uni_price * (float) $uni_param_kimb_18, 2, '.', '');
        $uni_mesecna_24 = number_format($uni_price * (float) $uni_param_kimb_24, 2, '.', '');
        $uni_mesecna_30 = number_format($uni_price * (float) $uni_param_kimb_30, 2, '.', '');
        $uni_mesecna_36 = number_format($uni_price * (float) $uni_param_kimb_36, 2, '.', '');

        switch ($uni_eur) {
            case 1:
                if ($uni_currency_code === 'EUR') {
                    $uni_mesecna = (float) $uni_mesecna * UnipaymentConfig::EUR_BGN_RATE;
                }
                break;
            case 2:
            case 3:
                if ($uni_currency_code === 'BGN') {
                    $uni_mesecna = (float) $uni_mesecna / UnipaymentConfig::EUR_BGN_RATE;
                }
                break;
        }

        $uni_mesecna_second = 0;
        $uni_price_second = 0;
        $uni_sign = 'лева';
        $uni_sign_second = 'евро';
        switch ($uni_eur) {
            case 0:
                break;
            case 1:
                $uni_price_second = number_format($uni_price / UnipaymentConfig::EUR_BGN_RATE, 2, '.', '');
                $uni_mesecna_second = number_format((float) $uni_mesecna / UnipaymentConfig::EUR_BGN_RATE, 2, '.', '');
                $uni_sign = 'лева';
                $uni_sign_second = 'евро';
                break;
            case 2:
                $uni_price_second = number_format($uni_price * UnipaymentConfig::EUR_BGN_RATE, 2, '.', '');
                $uni_mesecna_second = number_format((float) $uni_mesecna * UnipaymentConfig::EUR_BGN_RATE, 2, '.', '');
                $uni_sign = 'евро';
                $uni_sign_second = 'лева';
                break;
            case 3:
                $uni_price_second = 0;
                $uni_mesecna_second = 0;
                $uni_sign = 'евро';
                $uni_sign_second = 'лева';
                break;
        }

        $uni_minstojnost = (float) $paramsuni['uni_minstojnost'];
        $uni_maxstojnost = (float) $paramsuni['uni_maxstojnost'];
        $uni_zaglavie = (string) $paramsuni['uni_zaglavie'];
        $uni_vnoska = (string) $paramsuni['uni_vnoska'];
        $uni_reklama_url = (string) $paramsunicalc['uni_reklama_url'];
        $host = rtrim($req->shopSslBaseLink, '/');
        $uni_picture = $host . '/modules/unipayment/css/uni.png';
        $uni_proces1 = (int) $paramsuni['uni_proces1'];

        $uni_mini_logo = $host . '/modules/unipayment/css/uni_mini_logo.png';

        $classes = $this->resolveUiClasses($deviceis, $req);

        $uni_product_installment_options = [];
        foreach (UnipaymentConfig::PRODUCT_INSTALLMENT_MONTHS as $m) {
            $mesecnaStr = (string) ${'uni_mesecna_' . $m};
            $configOn = (int) ($paramsuni['uni_meseci_' . $m] ?? 0) !== 0;
            $mesecnaNum = (float) str_replace(',', '.', $mesecnaStr);
            $uni_product_installment_options[] = [
                'months' => $m,
                'show_in_select' => $configOn && $mesecnaNum > 0,
            ];
        }

        $uni_kimb_hidden_fields = [];
        foreach (UnipaymentConfig::KIMB_BANK_INSTALLMENT_COUNTS as $m) {
            $uni_kimb_hidden_fields[] = [
                'm' => $m,
                'glp' => (string) ${'uni_param_glp_' . $m},
                'kimb' => (string) ${'uni_param_kimb_' . $m},
            ];
        }

        $assign = array_merge(
            [
                'UNIPAYMENT_UNICID' => '',
                'uni_status' => $req->uniStatus,
                'uni_cart' => $req->uniCart,
                'uni_minstojnost' => $uni_minstojnost,
                'uni_price' => $uni_price,
                'uni_maxstojnost' => $uni_maxstojnost,
                'uni_zaglavie' => $uni_zaglavie,
                'uni_vnoska' => $uni_vnoska,
                'uni_mesecna' => $uni_mesecna,
                'uni_product_id' => $req->productId,
                'uni_reklama_url' => $uni_reklama_url,
                'uni_mod_version' => $req->moduleVersion,
                'uni_picture' => $uni_picture,
                'uni_mesecna_3' => $uni_mesecna_3,
                'uni_param_glp_3' => $uni_param_glp_3,
                'uni_param_kimb_3' => $uni_param_kimb_3,
                'uni_mesecna_4' => $uni_mesecna_4,
                'uni_param_glp_4' => $uni_param_glp_4,
                'uni_param_kimb_4' => $uni_param_kimb_4,
                'uni_mesecna_5' => $uni_mesecna_5,
                'uni_param_glp_5' => $uni_param_glp_5,
                'uni_param_kimb_5' => $uni_param_kimb_5,
                'uni_mesecna_6' => $uni_mesecna_6,
                'uni_param_glp_6' => $uni_param_glp_6,
                'uni_param_kimb_6' => $uni_param_kimb_6,
                'uni_mesecna_9' => $uni_mesecna_9,
                'uni_param_glp_9' => $uni_param_glp_9,
                'uni_param_kimb_9' => $uni_param_kimb_9,
                'uni_mesecna_10' => $uni_mesecna_10,
                'uni_param_glp_10' => $uni_param_glp_10,
                'uni_param_kimb_10' => $uni_param_kimb_10,
                'uni_mesecna_12' => $uni_mesecna_12,
                'uni_param_glp_12' => $uni_param_glp_12,
                'uni_param_kimb_12' => $uni_param_kimb_12,
                'uni_mesecna_15' => $uni_mesecna_15,
                'uni_param_glp_15' => $uni_param_glp_15,
                'uni_param_kimb_15' => $uni_param_kimb_15,
                'uni_mesecna_18' => $uni_mesecna_18,
                'uni_param_glp_18' => $uni_param_glp_18,
                'uni_param_kimb_18' => $uni_param_kimb_18,
                'uni_mesecna_24' => $uni_mesecna_24,
                'uni_param_glp_24' => $uni_param_glp_24,
                'uni_param_kimb_24' => $uni_param_kimb_24,
                'uni_mesecna_30' => $uni_mesecna_30,
                'uni_param_glp_30' => $uni_param_glp_30,
                'uni_param_kimb_30' => $uni_param_kimb_30,
                'uni_mesecna_36' => $uni_mesecna_36,
                'uni_param_glp_36' => $uni_param_glp_36,
                'uni_param_kimb_36' => $uni_param_kimb_36,
                'token' => $req->csrfToken,
                'uni_proces1' => $uni_proces1,
                'uni_shema_current' => $uni_shema_current,
                'uni_product_installment_options' => $uni_product_installment_options,
                'uni_kimb_hidden_fields' => $uni_kimb_hidden_fields,
                'uni_mini_logo' => $uni_mini_logo,
                'uni_get_product_link' => $req->getProductModuleLink,
                'UNIPAYMENT_GAP' => $req->unipaymentGap,
                'uni_eur' => $uni_eur,
                'uni_currency_code' => $uni_currency_code,
                'uni_sign' => $uni_sign,
                'uni_mesecna_second' => $uni_mesecna_second,
                'uni_sign_second' => $uni_sign_second,
                'uni_price_second' => $uni_price_second,
            ],
            $classes
        );

        $should_display = $req->uniStatus > 0
            && $uni_price <= (float) $paramsuni['uni_maxstojnost']
            && $uni_price >= (float) $paramsuni['uni_minstojnost'];

        return [
            'assign' => $assign,
            'should_display' => $should_display,
        ];
    }

    /** Lazy singleton за {@see UniCreditGetCoeffService}. */
    private function getUniCreditGetCoeffService(): UniCreditGetCoeffService
    {
        return $this->uniCreditGetCoeffService ??= new UniCreditGetCoeffService($this->moduleRootPath);
    }

    /** Непразни URL + credentials за getCoeff от банката. */
    private function canUseBankCoeffApi(string $service, string $user, string $password): bool
    {
        return $service !== '' && $user !== '' && $password !== '';
    }

    /**
     * @param array<string, mixed> $stats ред stats от DB мапинга
     */
    private function kimbFromStatsForInstallments(array $stats, int $installments): float
    {
        $key = 'kimb_' . $installments;
        if (!isset($stats[$key])) {
            return 0.0;
        }
        $raw = trim((string) $stats[$key]);
        if ($raw === '') {
            return 0.0;
        }

        return (float) str_replace(',', '.', $raw);
    }

    /**
     * Зарежда KOP мапинга от DB като списък редове.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadKopMappingFromDb(): array
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

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $statsRaw = (string) ($row['stats'] ?? '');
            $statsDecoded = json_decode($statsRaw, true);
            $out[] = [
                'category_id' => (int) ($row['id_category'] ?? 0),
                'kop' => (string) ($row['kop'] ?? ''),
                'promo' => (string) ($row['promo'] ?? ''),
                'kimb' => (string) ($row['kimb'] ?? ''),
                'kimb_time' => (string) ($row['kimb_time'] ?? ''),
                'stats' => is_array($statsDecoded) ? $statsDecoded : [],
            ];
        }

        return $out;
    }

    /**
     * Записва runtime полетата kimb/kimb_time/stats за конкретна категория.
     *
     * @param array<string, mixed> $row
     */
    private function persistKopRuntimeData(array $row): void
    {
        $categoryId = (int) ($row['category_id'] ?? 0);
        if ($categoryId <= 0) {
            return;
        }
        $stats = isset($row['stats']) && is_array($row['stats']) ? $row['stats'] : [];
        $statsJson = json_encode($stats, JSON_UNESCAPED_UNICODE);
        if (!is_string($statsJson)) {
            return;
        }

        /** @var mixed $db */
        $db = Db::getInstance();
        $db->update(
            UnipaymentConfig::TABLE_KOP_MAPPING,
            [
                'kimb' => (string) ($row['kimb'] ?? ''),
                'kimb_time' => (int) ($row['kimb_time'] ?? 0),
                'stats' => $statsJson,
                'date_upd' => date('Y-m-d H:i:s'),
            ],
            '`id_category` = ' . $categoryId
        );
    }

    /**
     * Първо съвпадение между категориите на продукта и редовете в DB мапинга (редът на id-тата е от {@see Product::getProductCategories}).
     *
     * @param array<int, array<string, mixed>> $uni_categories_kop
     * @param list<int> $productCategoryIds
     * @return int|string|false индекс в $uni_categories_kop
     */
    private function findKopRowIndexForProductCategories(array $uni_categories_kop, array $productCategoryIds): int|string|false
    {
        if ($uni_categories_kop === [] || $productCategoryIds === []) {
            return false;
        }
        $catIds = array_column($uni_categories_kop, 'category_id');
        $catIdsAsString = array_map('strval', $catIds);
        foreach ($productCategoryIds as $idCategory) {
            $idCategory = (int) $idCategory;
            $idx = array_search($idCategory, $catIds, true);
            if ($idx === false) {
                $idx = array_search((string) $idCategory, $catIdsAsString, true);
            }
            if ($idx !== false) {
                return $idx;
            }
        }

        return false;
    }

    /**
     * Ефективен КОП за продукта: стандартен или промо според конфигурацията и избраните месеци.
     *
     * @param array<int, array<string, mixed>> $uni_categories_kop
     * @param array<string, mixed>              $paramsuni
     */
    private function resolveKopCode(array $uni_categories_kop, int|string $uni_key, array $paramsuni, float $uni_price, int $uni_shema_current): string
    {
        $row = $uni_categories_kop[$uni_key];
        $uni_promo_data = $paramsuni['uni_promo_data'] ?? '';
        $curr_date = date('Y-m-d H:i');
        $date_to = date('Y-m-d H:i', strtotime((string) $uni_promo_data));
        $udata = $curr_date <= $date_to;
        $uni_promo = $paramsuni['uni_promo'] ?? '';
        $uni_promo_meseci_znak = $paramsuni['uni_promo_meseci_znak'] ?? '';
        $uni_promo_meseci = $paramsuni['uni_promo_meseci'] ?? '';
        $uni_promo_price = $paramsuni['uni_promo_price'] ?? '';

        if ($uni_promo === 'Yes' && $udata) {
            if ($uni_promo_meseci_znak === 'eq') {
                $uni_promo_meseci_arr = explode(',', (string) $uni_promo_meseci);
                if ($uni_price >= (float) $uni_promo_price && in_array($uni_shema_current, $uni_promo_meseci_arr, false)) {
                    $kop = $row['promo'] ?? '';
                    if ($kop === '' || $kop === null) {
                        $kop = $row['kop'] ?? '';
                    }

                    return (string) $kop;
                }

                return (string) ($row['kop'] ?? '');
            }
            $uni_promo_meseci_arr = explode(',', (string) $uni_promo_meseci);
            $first = $uni_promo_meseci_arr[0] ?? '0';
            if ($uni_price >= (float) $uni_promo_price && $uni_shema_current >= (int) $first) {
                $kop = $row['promo'] ?? '';
                if ($kop === '' || $kop === null) {
                    $kop = $row['kop'] ?? '';
                }

                return (string) $kop;
            }

            return (string) ($row['kop'] ?? '');
        }

        return (string) ($row['kop'] ?? '');
    }

    private function detectDevice(string $useragent): string
    {
        if (
            preg_match('/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows (ce|phone)|xda|xiino/i', $useragent)
            || preg_match('/1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\/)|klon|kpt |kwc\-|kyo(c|k)|le(no|xi)|lg( g|\/(k|l|u)|50|54|\-[a-w])|libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\-|oo|p\-)|sdk\/|se(c(\-|0|1)|47|mc|nd|ri)|sgh\-|shar|sie(\-|m)|sk\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\-9|up(\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\-|your|zeto|zte\-/i', substr($useragent, 0, 4))
        ) {
            return 'mobile';
        }

        return 'pc';
    }

    /**
     * @return array<string, string>
     */
    private function resolveUiClasses(string $deviceis, ProductAdditionalInfoRequest $req): array
    {
        if ($deviceis === 'mobile') {
            return [
                'modalpayment_content_uni' => 'modalpaymentm_content_uni',
                'uni_body' => 'unim_body',
                'uni_title_head' => 'unim_title_head',
                'uni_title' => 'unim_title',
                'uni_calc' => 'unim_calc',
                'uni_calc_back' => 'unim_calc_back',
                'uni_gpr_container' => 'unim_gpr_container',
                'uni_gpr_container_row' => 'unim_gpr_container_row',
                'uni_gpr_column' => 'unim_gpr_column',
                'uni_gpr_column_right' => 'unim_gpr_column_right',
                'uni_txt_right' => 'unim_txt_right',
                'uni_panel_help_text' => 'unim_panel_help_text',
                'uni_btn_primary' => 'unim_btn_primary',
                'uni_btn_primary_inner' => 'unim_btn_primary_inner',
                'notify_badge' => 'notifym-badge',
                'uni_btn_seccondary' => 'unim_btn_seccondary',
                'uni_btn_seccondary_inner' => 'unim_btn_seccondary_inner',
                'uni_meseci_txt' => $req->shopLabelMonthsMobile,
                'uni_vnoska_txt' => $req->shopLabelInstallmentMobile,
            ];
        }

        return [
            'modalpayment_content_uni' => 'modalpayment_content_uni',
            'uni_body' => 'uni_body',
            'uni_title_head' => 'uni_title_head',
            'uni_title' => 'uni_title',
            'uni_calc' => 'uni_calc',
            'uni_calc_back' => 'uni_calc_back',
            'uni_gpr_container' => 'uni_gpr_container',
            'uni_gpr_container_row' => 'uni_gpr_container_row',
            'uni_gpr_column' => 'uni_gpr_column',
            'uni_gpr_column_right' => 'uni_gpr_column_right',
            'uni_txt_right' => 'uni_txt_right',
            'uni_panel_help_text' => 'uni_panel_help_text',
            'uni_btn_primary' => 'uni_btn_primary',
            'uni_btn_primary_inner' => 'uni_btn_primary_inner',
            'notify_badge' => 'notify-badge',
            'uni_btn_seccondary' => 'uni_btn_seccondary',
            'uni_btn_seccondary_inner' => 'uni_btn_seccondary_inner',
            'uni_meseci_txt' => $req->shopLabelMonthsDesktop,
            'uni_vnoska_txt' => $req->shopLabelInstallmentDesktop,
        ];
    }
}
