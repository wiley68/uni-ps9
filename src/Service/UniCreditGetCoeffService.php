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

/**
 * getCoeff към UniCredit + DB кеш (group=coeff) —
 * споделен от продуктовия блок и checkout калкулатора.
 */
final class UniCreditGetCoeffService
{
    private const BANK_COEFF_CACHE_TTL = 600;

    private const CLIENT_PEM_PASSPHRASE = '1234';

    /**
     * @param string $moduleRootPath абсолютен път към корена на модула
     */
    public function __construct(
        private readonly string $moduleRootPath,
    ) {}

    /**
     * Изтрива coeff cache редове с date_upd преди началото на текущия календарен ден (PS_TIMEZONE при наличност).
     */
    public function purgeCoeffCacheFilesOlderThanToday(): int
    {
        $thresholdSql = date('Y-m-d H:i:s', $this->getStartOfTodayTimestamp());
        /** @var mixed $db */
        $db = Db::getInstance();
        $table = _DB_PREFIX_ . UnipaymentConfig::TABLE_API_CACHE;
        $existing = $db->getValue(
            'SELECT COUNT(*) FROM `' . $table . '`
            WHERE `cache_group` = \'coeff\' AND `date_upd` < \'' . $this->escapeSqlLiteral($thresholdSql) . '\''
        );
        if ((int) $existing <= 0) {
            return 0;
        }
        $db->execute(
            'DELETE FROM `' . $table . '`
            WHERE `cache_group` = \'coeff\' AND `date_upd` < \'' . $this->escapeSqlLiteral($thresholdSql) . '\''
        );

        return (int) $existing;
    }

    private function getStartOfTodayTimestamp(): int
    {
        if (class_exists(\Configuration::class)) {
            $tzName = (string) \Configuration::get('PS_TIMEZONE');
            if ($tzName !== '') {
                try {
                    return (new \DateTimeImmutable('today', new \DateTimeZone($tzName)))->getTimestamp();
                } catch (\Exception) {
                }
            }
        }
        $t = strtotime('today');

        return $t !== false ? $t : time();
    }

    /**
     * Кеш (ако е валиден), иначе банка; при успех записва кеша в DB.
     *
     * @return array{kimb: float, glp: float}|null
     */
    public function fetchCoeffWithFileCache(
        string $serviceUrl,
        string $user,
        string $password,
        string $kop,
        int $installments,
        bool $useCert,
    ): ?array {
        if (trim($kop) === '' || $installments <= 0) {
            return null;
        }

        $cached = $this->readBankCoeffCache($user, $kop, $installments, $useCert);
        if ($cached !== null) {
            return $cached;
        }

        $fetched = $this->fetchCoeffFromBank(
            $serviceUrl,
            $user,
            $password,
            $kop,
            $installments,
            $useCert
        );
        if ($fetched !== null && $fetched['kimb'] > 0) {
            $this->writeBankCoeffCache($user, $kop, $installments, $useCert, $fetched);

            return $fetched;
        }

        return null;
    }

    /**
     * @return array{kimb: float, glp: float}|null
     */
    private function readBankCoeffCache(string $user, string $kop, int $installments, bool $useCert): ?array
    {
        $cacheKey = $this->bankCoeffCacheKey($user, $kop, $installments, $useCert);
        /** @var mixed $db */
        $db = Db::getInstance();
        $row = $db->getRow(
            'SELECT `payload`, `date_upd` FROM `' . _DB_PREFIX_ . UnipaymentConfig::TABLE_API_CACHE . '`
            WHERE `cache_key` = \'' . $this->escapeSqlLiteral($cacheKey) . '\''
        );
        if (!is_array($row)) {
            return null;
        }
        $updatedTs = isset($row['date_upd']) ? strtotime((string) $row['date_upd']) : false;
        if ($updatedTs === false || (time() - (int) $updatedTs) >= self::BANK_COEFF_CACHE_TTL) {
            return null;
        }
        $data = isset($row['payload']) ? json_decode((string) $row['payload'], true) : null;
        if (!is_array($data) || !isset($data['kimb'])) {
            return null;
        }

        return [
            'kimb' => (float) $data['kimb'],
            'glp' => isset($data['glp']) ? (float) $data['glp'] : 0.0,
        ];
    }

    /**
     * @param array{kimb: float, glp: float} $payload
     */
    private function writeBankCoeffCache(string $user, string $kop, int $installments, bool $useCert, array $payload): void
    {
        $cacheKey = $this->bankCoeffCacheKey($user, $kop, $installments, $useCert);
        $json = json_encode(['kimb' => $payload['kimb'], 'glp' => $payload['glp']], JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        /** @var mixed $db */
        $db = Db::getInstance();
        $exists = (int) $db->getValue(
            'SELECT `id_unipayment_api_cache` FROM `' . _DB_PREFIX_ . UnipaymentConfig::TABLE_API_CACHE . '`
            WHERE `cache_key` = \'' . $this->escapeSqlLiteral($cacheKey) . '\''
        ) > 0;
        $data = [
            'cache_group' => 'coeff',
            'cache_key' => $cacheKey,
            'payload' => $json,
            'date_upd' => $now,
        ];
        if ($exists) {
            $db->update(UnipaymentConfig::TABLE_API_CACHE, $data, '`cache_key` = \'' . $this->escapeSqlLiteral($cacheKey) . '\'');

            return;
        }
        $data['date_add'] = $now;
        $db->insert(UnipaymentConfig::TABLE_API_CACHE, $data);
    }

    /** Име на DB cache key за coeff по user|kop|срок|сертификат. */
    private function bankCoeffCacheKey(string $user, string $kop, int $installments, bool $useCert): string
    {
        $key = $user . '|' . $kop . '|' . $installments . '|' . ($useCert ? '1' : '0');

        return 'coeff:' . md5($key);
    }

    private function escapeSqlLiteral(string $value): string
    {
        return addslashes($value);
    }

    /**
     * @return array{kimb: float, glp: float}|null
     */
    private function fetchCoeffFromBank(
        string $serviceUrl,
        string $user,
        string $password,
        string $kop,
        int $installments,
        bool $useCert,
    ): ?array {
        $url = $serviceUrl . 'getCoeff';
        $body = http_build_query([
            'user' => $user,
            'pass' => $password,
            'onlineProductCode' => $kop,
            'installmentCount' => (string) $installments,
        ], '', '&');

        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'cache-control: no-cache',
            ],
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 2,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ];

        if (defined('CURL_SSLVERSION_TLSv1_2')) {
            $opts[CURLOPT_SSLVERSION] = CURL_SSLVERSION_TLSv1_2;
        } else {
            $opts[CURLOPT_SSLVERSION] = 6;
        }

        if ($useCert) {
            $paths = $this->ensureClientPemMaterial();
            if ($paths === null) {
                return null;
            }
            $opts[CURLOPT_SSLKEY] = $paths['key'];
            $opts[CURLOPT_SSLKEYPASSWD] = self::CLIENT_PEM_PASSPHRASE;
            $opts[CURLOPT_SSLCERT] = $paths['cert'];
            $opts[CURLOPT_SSLCERTPASSWD] = self::CLIENT_PEM_PASSPHRASE;
        }

        $ch = curl_init();
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);

        if ($response === false || $response === '') {
            return null;
        }

        $obj = json_decode($response);
        if (!is_object($obj) || empty($obj->coeffList) || empty($obj->coeffList[0])) {
            return null;
        }

        $first = $obj->coeffList[0];
        $kimb = isset($first->coeff) ? (float) $first->coeff : 0.0;
        $glp = isset($first->interestPercent) ? (float) $first->interestPercent : 0.0;

        if ($kimb <= 0) {
            return null;
        }

        return ['kimb' => $kimb, 'glp' => $glp];
    }

    /**
     * @return array{key: string, cert: string}|null
     */
    private function ensureClientPemMaterial(): ?array
    {
        $base = rtrim(UnipaymentConfig::LIVE_URL, '/');
        $keyUrl = $base . '/calculators/key/avalon_private_key.pem';
        $certUrl = $base . '/calculators/key/avalon_cert.pem';
        $keyPath = $this->moduleRootPath . '/keys/avalon_private_key.pem';
        $certPath = $this->moduleRootPath . '/keys/avalon_cert.pem';

        if (!$this->downloadUrlToFile($keyUrl, $keyPath) || !$this->downloadUrlToFile($certUrl, $certPath)) {
            return null;
        }

        return ['key' => $keyPath, 'cert' => $certPath];
    }

    /** Изтегля ресурс само от префикс {@see UnipaymentConfig::LIVE_URL}. */
    private function downloadUrlToFile(string $url, string $destination): bool
    {
        $allowedPrefix = rtrim(UnipaymentConfig::LIVE_URL, '/');
        if (!str_starts_with($url, $allowedPrefix)) {
            return false;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 8,
        ]);
        $data = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($data === false || $code !== 200) {
            return false;
        }

        $dir = dirname($destination);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return file_put_contents($destination, $data) !== false;
    }
}
