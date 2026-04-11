<?php

/**
 * Copyright since 2024 Ilko Ivanov
 *
 * @author    Ilko Ivanov <ilko.iv@gmail.com>
 * @copyright Ilko Ivanov
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

use PrestaShop\Module\Unipayment\Config\UnipaymentConfig;
use PrestaShop\Module\Unipayment\Form\UnipaymentConfigurationDataConfiguration as UnipaymentConf;
use PrestaShop\Module\Unipayment\Service\UniCreditGetCoeffService;
use PrestaShop\PrestaShop\Adapter\SymfonyContainer;

/**
 * Front controller webhook: принудителен refresh на кеша от външен Control Panel.
 *
 * PrestaShop 9: съвместим с DB кеш и Symfony контейнера; при липса на услуга във front контекст се ползва SQL fallback.
 *
 * Защита:
 * - само POST
 * - Origin/Referer (или X-UniPayment-Source) host = host от {@see UnipaymentConfig::LIVE_URL}
 * - unicid от тялото/формата трябва да съвпада с UNIPAYMENT_UNICID (след евентуална legacy миграция)
 * - HMAC-SHA256: подпис върху низа "{timestamp}.{rawBody}" с ключ = UNIPAYMENT_UNICID
 *   (заглавки X-UniPayment-Timestamp, X-UniPayment-Signature — hex lowercase)
 *
 * @property UniPayment $module
 */
class UnipaymentRefreshcacheModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    private const SIGNATURE_TTL_SECONDS = 300;

    /** HTTP статус за {@see displayAjax()} (за мониторинг; тялото винаги е JSON с поле result). */
    private int $httpStatus = 200;

    /** @var string|null */
    private $rawBody = null;

    /** @var array<string, mixed>|null */
    private $decodedBody = null;

    /**
     * @var array{
     *   result: string,
     *   kop_refreshed: bool,
     *   params_refreshed: bool,
     *   coeff_purged: int,
     *   errors: array<int, string>
     * }
     */
    public $result = [
        'result' => 'error',
        'kop_refreshed' => false,
        'params_refreshed' => false,
        'coeff_purged' => 0,
        'errors' => [],
    ];

    /**
     * @see FrontController::initContent()
     */
    public function initContent()
    {
        $this->ajax = true;

        if ($this->module instanceof UniPayment) {
            $this->module->migrateLegacyUnipaymentKeysIfNeeded();
        }

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            $this->httpStatus = 405;
            $this->result['errors'][] = 'Method not allowed.';
            parent::initContent();

            return;
        }

        if (!$this->isRequestFromLiveUrlHost()) {
            $this->httpStatus = 403;
            $this->result['errors'][] = 'Request origin is not allowed.';
            parent::initContent();

            return;
        }

        $incomingCid = $this->readIncomingCid();
        $configuredCid = trim((string) Configuration::get(UnipaymentConf::UNIPAYMENT_UNICID));
        if ($incomingCid === '' || $configuredCid === '' || !hash_equals($configuredCid, $incomingCid)) {
            $this->httpStatus = 403;
            $this->result['errors'][] = 'Invalid webhook token.';
            parent::initContent();

            return;
        }
        if (!$this->isValidHmacSignature($configuredCid)) {
            $this->httpStatus = 403;
            $this->result['errors'][] = 'Invalid webhook signature.';
            parent::initContent();

            return;
        }

        $coeffPurged = $this->purgeUniCoeffCacheOlderThanToday();
        $this->result['coeff_purged'] = $coeffPurged;

        $kopOk = $this->refreshKopMappings();
        $this->result['kop_refreshed'] = $kopOk;
        if (!$kopOk) {
            $this->httpStatus = 500;
            $this->result['errors'][] = 'Could not refresh KOP mapping.';
            parent::initContent();

            return;
        }

        $paramsOk = $this->module instanceof UniPayment
            ? $this->module->refreshCachedUniParamsFromApi()
            : false;
        $this->result['params_refreshed'] = $paramsOk;

        if (!$paramsOk) {
            $this->result['result'] = 'partial';
            $this->httpStatus = 200;
            $this->result['errors'][] = 'KOP mapping was updated, but bank parameters cache refresh failed.';
            parent::initContent();

            return;
        }

        $this->result['result'] = 'success';
        $this->httpStatus = 200;
        parent::initContent();
    }

    /**
     * @see ModuleFrontController::displayAjax()
     */
    public function displayAjax()
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($this->httpStatus);
        $json = (string) json_encode($this->result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->ajaxRender($json);
        exit;
    }

    private function isRequestFromLiveUrlHost(): bool
    {
        $expectedHost = '';
        if ($this->module instanceof UniPayment) {
            $expectedHost = (string) parse_url($this->module->getLiveUrl(), PHP_URL_HOST);
        }
        if ($expectedHost === '') {
            return false;
        }

        $candidates = [];
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? trim((string) $_SERVER['HTTP_ORIGIN']) : '';
        $referer = isset($_SERVER['HTTP_REFERER']) ? trim((string) $_SERVER['HTTP_REFERER']) : '';
        $customSource = isset($_SERVER['HTTP_X_UNIPAYMENT_SOURCE']) ? trim((string) $_SERVER['HTTP_X_UNIPAYMENT_SOURCE']) : '';
        if ($origin !== '') {
            $candidates[] = $origin;
        }
        if ($referer !== '') {
            $candidates[] = $referer;
        }
        if ($customSource !== '') {
            $candidates[] = $customSource;
        }
        if ($candidates === []) {
            return false;
        }

        foreach ($candidates as $url) {
            $host = (string) parse_url($url, PHP_URL_HOST);
            if ($host !== '' && strcasecmp($host, $expectedHost) === 0) {
                return true;
            }
        }

        return false;
    }

    private function readIncomingCid(): string
    {
        $fromPost = trim((string) Tools::getValue('unicid', ''));
        if ($fromPost !== '') {
            return $fromPost;
        }
        $decoded = $this->getDecodedBody();
        if ($decoded === null) {
            return '';
        }

        return isset($decoded['unicid']) ? trim((string) $decoded['unicid']) : '';
    }

    private function isValidHmacSignature(string $secret): bool
    {
        $timestampHeader = isset($_SERVER['HTTP_X_UNIPAYMENT_TIMESTAMP'])
            ? trim((string) $_SERVER['HTTP_X_UNIPAYMENT_TIMESTAMP'])
            : '';
        $signatureHeader = isset($_SERVER['HTTP_X_UNIPAYMENT_SIGNATURE'])
            ? strtolower(trim((string) $_SERVER['HTTP_X_UNIPAYMENT_SIGNATURE']))
            : '';
        if ($timestampHeader === '' || $signatureHeader === '') {
            return false;
        }
        if (!ctype_digit($timestampHeader)) {
            return false;
        }
        $timestamp = (int) $timestampHeader;
        if ($timestamp <= 0 || abs(time() - $timestamp) > self::SIGNATURE_TTL_SECONDS) {
            return false;
        }
        $rawBody = $this->getRawBody();
        $signedPayload = $timestampHeader . '.' . $rawBody;
        $expected = hash_hmac('sha256', $signedPayload, $secret);

        return hash_equals($expected, $signatureHeader);
    }

    private function getRawBody(): string
    {
        if ($this->rawBody !== null) {
            return $this->rawBody;
        }
        $raw = file_get_contents('php://input');
        $this->rawBody = is_string($raw) ? $raw : '';

        return $this->rawBody;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getDecodedBody(): ?array
    {
        if ($this->decodedBody !== null) {
            return $this->decodedBody;
        }
        $raw = $this->getRawBody();
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        $this->decodedBody = $decoded;

        return $this->decodedBody;
    }

    private function purgeUniCoeffCacheOlderThanToday(): int
    {
        if (!$this->module instanceof UniPayment) {
            return 0;
        }
        $root = method_exists($this->module, 'getLocalPath')
            ? $this->module->getLocalPath()
            : (_PS_MODULE_DIR_ . 'unipayment/');
        $root = rtrim((string) $root, '/\\');

        return (new UniCreditGetCoeffService($root))->purgeCoeffCacheFilesOlderThanToday();
    }

    private function refreshKopMappings(): bool
    {
        $container = SymfonyContainer::getInstance();
        if (is_object($container) && method_exists($container, 'has') && method_exists($container, 'get')) {
            try {
                if ($container->has('prestashop.module.unipayment.service.kop_mapping')) {
                    $service = $container->get('prestashop.module.unipayment.service.kop_mapping');
                    if (is_object($service) && method_exists($service, 'refreshMappings')) {
                        return (bool) $service->refreshMappings();
                    }
                }
            } catch (\Throwable) {
                // контейнерът може да не е напълно наличен в изолиран front контекст
            }
        }

        return $this->refreshKopMappingsBySqlFallback();
    }

    /**
     * Един UPDATE за всички редове (по-бързо от N отделни заявки при голям брой категории).
     */
    private function refreshKopMappingsBySqlFallback(): bool
    {
        /** @var mixed $db */
        $db = Db::getInstance();
        $table = _DB_PREFIX_ . UnipaymentConfig::TABLE_KOP_MAPPING;
        $statsJson = json_encode($this->defaultStats(), JSON_UNESCAPED_UNICODE);
        if (!is_string($statsJson)) {
            return false;
        }
        $escapedStats = $db->escape($statsJson, true, false);

        return (bool) $db->execute(
            'UPDATE `' . $table . '` SET `kimb` = \'\', `kimb_time` = 0, `stats` = \'' . $escapedStats . '\', `date_upd` = NOW() WHERE 1=1'
        );
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
}
