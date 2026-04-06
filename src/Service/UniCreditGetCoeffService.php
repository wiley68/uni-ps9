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

use PrestaShop\Module\Unipayment\Config\UnipaymentConfig;

/**
 * getCoeff към UniCredit + файлов кеш в {@see self::getCoeffCacheDirectory()} (coeff_*.json) —
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
    ) {
    }

    /**
     * Път към директорията с кеш файловете на getCoeff (не в корена на keys/).
     */
    public function getCoeffCacheDirectory(): string
    {
        return $this->moduleRootPath . '/keys/coeff';
    }

    /**
     * В keys/coeff/: изтрива coeff_*.json с mtime преди началото на текущия календарен ден (PS_TIMEZONE при наличност).
     * Не пипа keys/coeff_*.json в корена на keys/.
     *
     * @return int брой успешно изтрити файлове
     */
    public function purgeCoeffCacheFilesOlderThanToday(): int
    {
        $threshold = $this->getStartOfTodayTimestamp();
        $deleted = 0;
        $coeffDir = $this->getCoeffCacheDirectory();
        if (!is_dir($coeffDir)) {
            return 0;
        }
        foreach (glob($coeffDir . '/coeff_*.json') ?: [] as $file) {
            if ($this->unlinkCoeffFileIfOlderThan($file, $threshold)) {
                ++$deleted;
            }
        }

        return $deleted;
    }

    private function unlinkCoeffFileIfOlderThan(string $file, int $threshold): bool
    {
        if (!is_file($file)) {
            return false;
        }
        if ((int) filemtime($file) >= $threshold) {
            return false;
        }

        return @unlink($file);
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

    private function ensureCoeffCacheDirectoryExists(): void
    {
        $dir = $this->getCoeffCacheDirectory();
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /**
     * Кеш (ако е валиден), иначе банка; при успех записва кеша.
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
        $file = $this->bankCacheFilePath($user, $kop, $installments, $useCert);
        if (!is_readable($file) || (time() - (int) filemtime($file)) >= self::BANK_COEFF_CACHE_TTL) {
            return null;
        }
        $raw = file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
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
        $keysRoot = $this->moduleRootPath . '/keys';
        if (!is_dir($keysRoot)) {
            mkdir($keysRoot, 0755, true);
        }
        $this->ensureCoeffCacheDirectoryExists();
        $file = $this->bankCacheFilePath($user, $kop, $installments, $useCert);
        file_put_contents(
            $file,
            (string) json_encode(['kimb' => $payload['kimb'], 'glp' => $payload['glp']], JSON_UNESCAPED_UNICODE)
        );
    }

    /** Име на JSON файл в keys/coeff/ по user|kop|срок|сертификат. */
    private function bankCoeffCacheFileName(string $user, string $kop, int $installments, bool $useCert): string
    {
        $key = $user . '|' . $kop . '|' . $installments . '|' . ($useCert ? '1' : '0');

        return 'coeff_' . md5($key) . '.json';
    }

    /** Пълен път към един кеш файл в {@see self::getCoeffCacheDirectory()}. */
    private function bankCacheFilePath(string $user, string $kop, int $installments, bool $useCert): string
    {
        return $this->getCoeffCacheDirectory() . '/' . $this->bankCoeffCacheFileName($user, $kop, $installments, $useCert);
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
