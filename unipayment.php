<?php

/**
 * Copyright since 2024 Ilko Ivanov
 *
 * UniPayment – UNICredit (PrestaShop 9.x).
 *
 * @author    Ilko Ivanov
 * @copyright Ilko Ivanov
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use PrestaShop\Module\Unipayment\Config\UnipaymentConfig;
use PrestaShop\Module\Unipayment\DTO\ProductAdditionalInfoRequest;
use PrestaShop\Module\Unipayment\Form\UnipaymentConfigurationDataConfiguration as UnipaymentConf;
use PrestaShop\Module\Unipayment\Service\ProductAdditionalInfoBlockService;
use PrestaShop\PrestaShop\Adapter\SymfonyContainer;
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

/**
 * @method Currency|array<int, array<string, mixed>>|false getCurrency(?int $current_id_currency = null)
 */
class UniPayment extends PaymentModule
{
    private const UNIPAYMENT_COOKIE_BANK_REDIRECT = 'unipayment_bank_redirect';

    private const UNIPAYMENT_BANK_REDIRECT_COOKIE_TTL = 3600;

    /** Име на бисквитка: брой месеци от продуктов попъп („Купи на изплащане“). Синхронизирай с {@see UniPayment::clearUnipaymentCheckoutPreferenceBrowserCookie}. */
    public const BROWSER_COOKIE_CHECKOUT_INSTALLMENTS = 'unipayment_pc_inst';

    private const UNIPAYMENT_CHECKOUT_BROWSER_COOKIE_TTL = 1800;

    private ?ProductAdditionalInfoBlockService $productAdditionalInfoBlockService = null;

    /**
     * @see PaymentModule::__construct()
     */
    public function __construct()
    {
        $this->name = 'unipayment';
        $this->tab = 'payments_gateways';
        $this->version = '1.4.1';
        $this->author = 'Ilko Ivanov';
        $this->need_instance = 0;

        $this->ps_versions_compliancy = [
            'min' => '9.0.0',
            'max' => '9.999.999',
        ];

        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('UniCredit Credit purchases', [], 'Modules.Unipayment.Admin');
        $this->description = $this->trans(
            'Enables your customers to purchase goods on installments with UniCredit.',
            [],
            'Modules.Unipayment.Admin'
        );
        $this->confirmUninstall = $this->trans(
            'Are you sure you want to uninstall this module?',
            [],
            'Modules.Unipayment.Admin'
        );
    }

    /**
     * Базов URL на live средата (стойността е в {@see UnipaymentConfig::LIVE_URL}).
     */
    public function getLiveUrl(): string
    {
        return UnipaymentConfig::LIVE_URL;
    }

    /**
     * Кеширани параметри от getparameters.php за текущия CID (validation / банкови извиквания).
     *
     * @return array<string, mixed>|null
     */
    public function getCachedUniParameters(): ?array
    {
        $this->migrateLegacyConfigurationKeysIfNeeded();
        $cid = (string) (Configuration::get(UnipaymentConf::UNIPAYMENT_UNICID) ?: '');
        if ($cid === '') {
            return null;
        }

        return $this->getCachedUniParams($cid);
    }

    /**
     * Регистрира hook-ове и състояние на поръчка за UniCredit.
     *
     * @see Module::install()
     */
    public function install(): bool
    {
        if (Shop::isFeatureActive()) {
            Shop::setContext(Shop::CONTEXT_ALL);
        }

        if (
            !parent::install() ||
            !$this->registerHook('actionFrontControllerSetMedia') ||
            !$this->registerHook('paymentOptions') ||
            !$this->registerHook('displayProductAdditionalInfo') ||
            !$this->registerHook('displayShoppingCart') ||
            !$this->registerHook('paymentReturn') ||
            !$this->registerHook('displayHome') ||
            !$this->registerHook('actionValidateOrder') ||
            !Configuration::updateValue('UNIPAYMENT_NAME', 'UniCredit Credit purchases') ||
            !$this->createKopMappingTable() ||
            !$this->createApiCacheTable()
        ) {
            return false;
        }

        return $this->ensureOrderState();
    }

    /**
     * Премахва конфигурационните ключове на модула (без PS_OS_UNIPAYMENT).
     *
     * @see Module::uninstall()
     */
    public function uninstall(): bool
    {
        if (!parent::uninstall()) {
            return false;
        }

        // Best-effort cleanup of module configuration keys.
        Configuration::deleteByName('UNIPAYMENT_NAME');
        // Не изтриваме PS_OS_UNIPAYMENT и реда в order_state — стари поръчки сочат към този id;
        // изтриването води до „увиснали“ current_state и грешки в админката (препоръка PrestaShop).
        Configuration::deleteByName('UNIPAYMENT_STATUS');
        Configuration::deleteByName('UNIPAYMENT_UNICID');
        Configuration::deleteByName('UNIPAYMENT_REKLAMA');
        Configuration::deleteByName('UNIPAYMENT_CART');
        Configuration::deleteByName('UNIPAYMENT_DEBUG');
        Configuration::deleteByName('UNIPAYMENT_GAP');

        foreach (
            [
                'unipayment_status',
                'unipayment_unicid',
                'unipayment_reklama',
                'unipayment_cart',
                'unipayment_debug',
                'unipayment_gap',
            ] as $legacyKey
        ) {
            Configuration::deleteByName($legacyKey);
        }

        return $this->dropApiCacheTable() && $this->dropKopMappingTable();
    }

    private function createKopMappingTable(): bool
    {
        $engine = defined('_MYSQL_ENGINE_') ? (string) constant('_MYSQL_ENGINE_') : 'InnoDB';
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . UnipaymentConfig::TABLE_KOP_MAPPING . '` (
            `id_unipayment_kop` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_category` INT UNSIGNED NOT NULL,
            `kop` VARCHAR(64) NOT NULL DEFAULT \'\',
            `promo` VARCHAR(64) NOT NULL DEFAULT \'\',
            `kimb` VARCHAR(32) NOT NULL DEFAULT \'\',
            `kimb_time` INT UNSIGNED NOT NULL DEFAULT 0,
            `stats` LONGTEXT NULL,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_unipayment_kop`),
            UNIQUE KEY `uniq_unipayment_kop_category` (`id_category`)
        ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        /** @var mixed $db */
        $db = Db::getInstance();

        return (bool) $db->execute($sql);
    }

    private function dropKopMappingTable(): bool
    {
        $sql = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . UnipaymentConfig::TABLE_KOP_MAPPING . '`';

        /** @var mixed $db */
        $db = Db::getInstance();

        return (bool) $db->execute($sql);
    }

    private function createApiCacheTable(): bool
    {
        $engine = defined('_MYSQL_ENGINE_') ? (string) constant('_MYSQL_ENGINE_') : 'InnoDB';
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . UnipaymentConfig::TABLE_API_CACHE . '` (
            `id_unipayment_api_cache` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `cache_group` VARCHAR(32) NOT NULL,
            `cache_key` VARCHAR(191) NOT NULL,
            `payload` LONGTEXT NULL,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_unipayment_api_cache`),
            UNIQUE KEY `uniq_unipayment_api_cache_key` (`cache_key`),
            KEY `idx_unipayment_api_cache_group_upd` (`cache_group`, `date_upd`)
        ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        /** @var mixed $db */
        $db = Db::getInstance();

        return (bool) $db->execute($sql);
    }

    private function dropApiCacheTable(): bool
    {
        $sql = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . UnipaymentConfig::TABLE_API_CACHE . '`';
        /** @var mixed $db */
        $db = Db::getInstance();

        return (bool) $db->execute($sql);
    }

    /**
     * Зареждане на assets на frontend (минимална имплементация).
     *
     * @param array<string, mixed> $params
     */
    public function hookActionFrontControllerSetMedia(array $params): void
    {
        if ('product' === $this->context->controller->php_self) {
            $this->registerRobotoCondensedFonts();
            $this->context->controller->registerStylesheet(
                'unipayment-product-page',
                'modules/' . $this->name . '/css/unipayment_product.css',
                [
                    'media' => 'all',
                    'priority' => 200,
                    'version' => (string) @filemtime(__DIR__ . '/css/unipayment_product.css'),
                ]
            );
            $this->context->controller->registerJavascript(
                'unipayment-product-page-js',
                'modules/' . $this->name . '/js/unipayment_product.js',
                [
                    'position' => 'bottom',
                    'priority' => 200,
                    // Време на промяна на файла — нов URL при ъпдейт (Cloudflare/браузър иначе държат стар .js).
                    'version' => (string) @filemtime(__DIR__ . '/js/unipayment_product.js'),
                ]
            );
        }
        if ('cart' === $this->context->controller->php_self) {
            $this->registerRobotoCondensedFonts();
            $this->context->controller->registerStylesheet(
                'unipayment-cart-page',
                'modules/' . $this->name . '/css/unipayment_cart.css',
                [
                    'media' => 'all',
                    'priority' => 200,
                    'version' => (string) @filemtime(__DIR__ . '/css/unipayment_cart.css'),
                ]
            );
            $this->context->controller->registerJavascript(
                'unipayment-cart-page-js',
                'modules/' . $this->name . '/js/unipayment_cart.js',
                [
                    'position' => 'bottom',
                    'priority' => 200,
                    'version' => (string) @filemtime(__DIR__ . '/js/unipayment_cart.js'),
                ]
            );
        }
        if ('index' === $this->context->controller->php_self && $this->shouldRegisterUnipanelHomeAssets()) {
            $this->context->controller->registerStylesheet(
                'unipayment-home-page',
                'modules/' . $this->name . '/css/unipanel.css',
                [
                    'media' => 'all',
                    'priority' => 200,
                    'version' => (string) @filemtime(__DIR__ . '/css/unipanel.css'),
                ]
            );
            $this->context->controller->registerJavascript(
                'unipayment-home-page-js',
                'modules/' . $this->name . '/js/unipanel.js',
                [
                    'position' => 'bottom',
                    'priority' => 200,
                    'version' => (string) @filemtime(__DIR__ . '/js/unipanel.js'),
                ]
            );
        }
        if ($this->isFrontCheckoutOrderPage()) {
            $this->context->controller->registerStylesheet(
                'unipayment-order-page',
                'modules/' . $this->name . '/css/uniorder.css',
                [
                    'media' => 'all',
                    'priority' => 200,
                    'version' => (string) @filemtime(__DIR__ . '/css/uniorder.css'),
                ]
            );
            $this->context->controller->registerJavascript(
                'unipayment-order-page-js',
                'modules/' . $this->name . '/js/uniorder.js',
                [
                    'position' => 'bottom',
                    'priority' => 200,
                    'version' => (string) @filemtime(__DIR__ . '/js/uniorder.js'),
                ]
            );
            $this->context->controller->registerJavascript(
                'unipayment-order-preselect-js',
                'modules/' . $this->name . '/js/uniorder_preselect.js',
                [
                    'position' => 'bottom',
                    'priority' => 205,
                    'version' => (string) @filemtime(__DIR__ . '/js/uniorder_preselect.js'),
                ]
            );
        }
    }

    /**
     * Премахва бисквитката за предварителен избор на UNI след потвърдена поръчка.
     *
     * @param array<string, mixed> $params
     */
    public function hookActionValidateOrder(array $params): void
    {
        self::clearUnipaymentCheckoutPreferenceBrowserCookie();
    }

    /**
     * Изчиства бисквитките за предварителен избор на UniCredit в checkout (продукт/cart → order).
     */
    public static function clearUnipaymentCheckoutPreferenceBrowserCookie(): void
    {
        $opts = [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => (bool) Tools::usingSecureMode(),
            'httponly' => false,
            'samesite' => 'Lax',
        ];
        setcookie('unipayment_pc', '', $opts);
        setcookie(self::BROWSER_COOKIE_CHECKOUT_INSTALLMENTS, '', $opts);
    }

    /**
     * @param array<string, mixed> $paramsuni
     */
    public function isCheckoutInstallmentMonthAllowed(array $paramsuni, int $months): bool
    {
        if ($months <= 0 || !in_array($months, UnipaymentConfig::PRODUCT_INSTALLMENT_MONTHS, true)) {
            return false;
        }

        return (int) ($paramsuni['uni_meseci_' . $months] ?? 0) !== 0;
    }

    /**
     * Връща заявените месеци само ако са разрешени в кешираните bank params; иначе 0.
     */
    public function resolveCheckoutInstallmentsBrowserPreference(int $requestedMonths): int
    {
        if ($requestedMonths <= 0) {
            return 0;
        }
        $uniCid = (string) (Configuration::get(UnipaymentConf::UNIPAYMENT_UNICID) ?: '');
        if ($uniCid === '') {
            return 0;
        }
        $paramsuni = $this->getCachedUniParams($uniCid);
        if (!is_array($paramsuni) || ($paramsuni['uni_status'] ?? '') !== 'Yes') {
            return 0;
        }

        return $this->isCheckoutInstallmentMonthAllowed($paramsuni, $requestedMonths) ? $requestedMonths : 0;
    }

    /**
     * Бисквитки след успешно „Купи на изплащане“: избор на UNI + опционално предпочитани месеци.
     */
    public function writeBrowserCookiesForPrepareCheckout(int $resolvedInstallmentsMonths): void
    {
        $common = [
            'path' => '/',
            'secure' => (bool) Tools::usingSecureMode(),
            'httponly' => false,
            'samesite' => 'Lax',
        ];
        setcookie('unipayment_pc', '1', ['expires' => time() + self::UNIPAYMENT_CHECKOUT_BROWSER_COOKIE_TTL] + $common);
        if ($resolvedInstallmentsMonths > 0) {
            setcookie(
                self::BROWSER_COOKIE_CHECKOUT_INSTALLMENTS,
                (string) $resolvedInstallmentsMonths,
                ['expires' => time() + self::UNIPAYMENT_CHECKOUT_BROWSER_COOKIE_TTL] + $common
            );
        } else {
            setcookie(self::BROWSER_COOKIE_CHECKOUT_INSTALLMENTS, '', ['expires' => time() - 3600] + $common);
        }
    }

    /**
     * Платежни опции в checkout.
     *
     * @param array<string, mixed> $params
     *
     * @return array<int, mixed>
     */
    public function hookPaymentOptions(array $params): array
    {
        $cartParam = $params['cart'] ?? null;
        if (!$this->active || !$cartParam instanceof Cart || !$this->checkCurrency($cartParam)) {
            return [];
        }

        $this->migrateLegacyConfigurationKeysIfNeeded();

        $uni_status = (int) Configuration::get(UnipaymentConf::UNIPAYMENT_STATUS);
        $uni_currency_code = (string) $this->context->currency->iso_code;
        if ($uni_status <= 0 || ($uni_currency_code !== 'EUR' && $uni_currency_code !== 'BGN')) {
            return [];
        }

        $uni_unicid = (string) (Configuration::get(UnipaymentConf::UNIPAYMENT_UNICID) ?: '');
        if ($uni_unicid === '') {
            return [];
        }

        $paramsuni = $this->getCachedUniParams($uni_unicid);
        if (!is_array($paramsuni) || empty($paramsuni) || ($paramsuni['uni_status'] ?? '') !== 'Yes') {
            return [];
        }

        /** @var Cart $cart */
        $cart = $cartParam;
        if (!Validate::isLoadedObject($cart) || $cart->nbProducts() <= 0) {
            return [];
        }

        $uni_products = $cart->getProducts(true);
        if ($uni_products === [] || !isset($uni_products[0]['id_product'])) {
            return [];
        }

        $uni_price = (float) $cart->getOrderTotal(true, Cart::ONLY_PRODUCTS);
        $uni_minstojnost = (float) ($paramsuni['uni_minstojnost'] ?? 0);
        $uni_maxstojnost = (float) ($paramsuni['uni_maxstojnost'] ?? 0);
        if ($uni_price < $uni_minstojnost || $uni_price > $uni_maxstojnost) {
            return [];
        }

        $langID = (int) $this->context->language->id;
        $uni_product_category_ids = $this->getCheckoutKopCategoryIdsForCartProducts($uni_products, $langID);
        $uni_product_cat_id = $uni_product_category_ids[0] ?? 0;
        $uni_product_category_ids_csv = implode(',', array_map('strval', $uni_product_category_ids));

        $uni_testenv = (int) ($paramsuni['uni_testenv'] ?? 0);
        $uni_service = $uni_testenv === 1
            ? (string) ($paramsuni['uni_test_service'] ?? '')
            : (string) ($paramsuni['uni_production_service'] ?? '');

        $link_to_calculateuni = $this->context->link->getModuleLink($this->name, 'calculateuni', [], true);
        $link_to_session = $this->context->link->getModuleLink($this->name, 'send', [], true);

        $customer = $this->context->customer;
        $uni_firstname = '';
        $uni_lastname = '';
        $uni_email = '';
        $uni_phone = '';
        $uni_shipping_addresses = [];

        if (Validate::isLoadedObject($customer) && (int) $customer->id > 0) {
            $uni_firstname = (string) $customer->firstname;
            $uni_lastname = (string) $customer->lastname;
            $uni_email = (string) $customer->email;
            $idLang = (int) $customer->id_lang > 0 ? (int) $customer->id_lang : $langID;
            foreach ($customer->getAddresses($idLang) as $uni_address) {
                if ((int) ($uni_address['id_address'] ?? 0) === (int) $cart->id_address_delivery) {
                    $uni_shipping_addresses = $uni_address;
                    break;
                }
            }
            $uni_phone = (string) ($uni_shipping_addresses['phone'] ?? '');
        }

        $rate = UnipaymentConfig::EUR_BGN_RATE;
        $uni_eur = (int) ($paramsuni['uni_eur'] ?? 0);
        switch ($uni_eur) {
            case 1:
                if ($uni_currency_code === 'EUR') {
                    $uni_price *= $rate;
                }
                break;
            case 2:
            case 3:
                if ($uni_currency_code === 'BGN') {
                    $uni_price /= $rate;
                }
                break;
        }

        $uni_price_second = '0';
        $uni_sign = 'лева';
        $uni_sign_second = 'евро';
        switch ($uni_eur) {
            case 0:
                break;
            case 1:
                $uni_price_second = number_format($uni_price / $rate, 2, '.', '');
                break;
            case 2:
                $uni_price_second = number_format($uni_price * $rate, 2, '.', '');
                $uni_sign = 'евро';
                $uni_sign_second = 'лева';
                break;
            case 3:
                $uni_sign = 'евро';
                $uni_sign_second = 'лева';
                break;
        }

        $uni_check = (int) Tools::getValue('uni_check', 0);
        $uni_fname_get = (int) Tools::getValue('uni_fname_get', 0);
        $uni_lname_get = (int) Tools::getValue('uni_lname_get', 0);
        $uni_egn_get = (int) Tools::getValue('uni_egn_get', 0);
        $uni_phone_get = (int) Tools::getValue('uni_phone_get', 0);
        $uni_email_get = (int) Tools::getValue('uni_email_get', 0);

        $uni_checkout_installment_options = [];
        foreach (UnipaymentConfig::PRODUCT_INSTALLMENT_MONTHS as $m) {
            $uni_checkout_installment_options[] = [
                'months' => $m,
                'enabled' => (int) ($paramsuni['uni_meseci_' . $m] ?? 0) !== 0,
            ];
        }

        $uniShemaCurrent = (string) ($paramsuni['uni_shema_current'] ?? '');
        $preferredMonths = isset($_COOKIE[self::BROWSER_COOKIE_CHECKOUT_INSTALLMENTS])
            ? (int) $_COOKIE[self::BROWSER_COOKIE_CHECKOUT_INSTALLMENTS]
            : 0;
        if ($preferredMonths > 0 && $this->isCheckoutInstallmentMonthAllowed($paramsuni, $preferredMonths)) {
            $uniShemaCurrent = (string) $preferredMonths;
        }

        $this->context->smarty->assign([
            'uni_liveurl' => $this->getLiveUrl(),
            'uni_unicid' => $uni_unicid,
            'uni_proces1' => $paramsuni['uni_proces1'] ?? '',
            'uni_nbProducts' => $cart->nbProducts(),
            'uni_total' => $uni_price,
            'uni_first_vnoska' => $paramsuni['uni_first_vnoska'] ?? '',
            'uni_proces2' => $paramsuni['uni_proces2'] ?? '',
            'uni_firstname' => $uni_firstname,
            'uni_lastname' => $uni_lastname,
            'uni_phone' => $uni_phone,
            'uni_email' => $uni_email,
            'uni_uslovia' => Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/css/uni_uslovia.pdf'),
            'uni_mod_version' => $this->version,
            'link_to_calculateuni' => $link_to_calculateuni,
            'link_to_session' => $link_to_session,
            'uni_promo' => $paramsuni['uni_promo'] ?? '',
            'uni_promo_data' => $paramsuni['uni_promo_data'] ?? '',
            'uni_promo_meseci_znak' => $paramsuni['uni_promo_meseci_znak'] ?? '',
            'uni_promo_meseci' => $paramsuni['uni_promo_meseci'] ?? '',
            'uni_promo_price' => $paramsuni['uni_promo_price'] ?? '',
            'uni_product_cat_id' => $uni_product_cat_id,
            'uni_product_category_ids' => $uni_product_category_ids_csv,
            'uni_service' => $uni_service,
            'uni_user' => htmlspecialchars_decode((string) ($paramsuni['uni_user'] ?? '')),
            'uni_password' => htmlspecialchars_decode((string) ($paramsuni['uni_password'] ?? '')),
            'uni_sertificat' => $paramsuni['uni_sertificat'] ?? '',
            'uni_real_ip' => '',
            'uni_check' => $uni_check,
            'uni_shema_current' => $uniShemaCurrent,
            'uni_fname_get' => $uni_fname_get,
            'uni_lname_get' => $uni_lname_get,
            'uni_egn_get' => $uni_egn_get,
            'uni_phone_get' => $uni_phone_get,
            'uni_email_get' => $uni_email_get,
            'uni_checkout_installment_options' => $uni_checkout_installment_options,
            'uni_eur' => $uni_eur,
            'uni_sign' => $uni_sign,
            'uni_sign_second' => $uni_sign_second,
            'uni_price_second' => $uni_price_second,
        ]);

        $this->context->smarty->assign([
            'uni_checkout_js_strings' => (string) json_encode([
                'mustAgreeTerms' => $this->trans('You must agree to the UniCredit terms and conditions.', [], 'Modules.Unipayment.Shop'),
                'fillFirstName' => $this->trans('Please fill in the First name field.', [], 'Modules.Unipayment.Shop'),
                'fillLastName' => $this->trans('Please fill in the Last name field.', [], 'Modules.Unipayment.Shop'),
                'fillPersonalId' => $this->trans('Please fill in the Personal ID field.', [], 'Modules.Unipayment.Shop'),
                'fillPhone' => $this->trans('Please fill in the Phone field.', [], 'Modules.Unipayment.Shop'),
                'fillEmail' => $this->trans('Please fill in the E-mail field.', [], 'Modules.Unipayment.Shop'),
                'waitRecalculation' => $this->trans('Please wait, installment values are being updated.', [], 'Modules.Unipayment.Shop'),
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
        ]);

        $newOption = new PaymentOption();
        $newOption->setModuleName($this->name)
            ->setCallToActionText($this->trans('UniCredit purchases on credit', [], 'Modules.Unipayment.Shop'))
            ->setAction($this->context->link->getModuleLink($this->name, 'validation', [], true))
            ->setLogo(Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/css/uni_logo.jpg'))
            ->setAdditionalInformation($this->fetch('module:unipayment/views/templates/hook/unipayment_intro.tpl'));

        return [$newOption];
    }

    /**
     * Блок под бутона "Купи" в продуктова страница.
     *
     * @param array<string, mixed> $params
     */
    public function hookDisplayProductAdditionalInfo(array $params): string
    {
        $controller = $this->context->controller ?? null;
        if (!$controller || !isset($controller->php_self) || $controller->php_self !== 'product') {
            return '';
        }

        $this->migrateLegacyConfigurationKeysIfNeeded();

        $uni_status = (int) Configuration::get(UnipaymentConf::UNIPAYMENT_STATUS);
        $uni_currency_code = $this->context->currency->iso_code;
        if ($uni_status <= 0 || ($uni_currency_code !== 'EUR' && $uni_currency_code !== 'BGN')) {
            return '';
        }

        $product_id = (int) Tools::getValue('id_product');
        if ($product_id <= 0) {
            return '';
        }

        $product_category_ids = $this->getTopLevelCategoryIdsForProduct($product_id, (int) $this->context->language->id);
        if ($product_category_ids === []) {
            return '';
        }

        $uni_unicid = (string) (Configuration::get(UnipaymentConf::UNIPAYMENT_UNICID) ?: '');
        $paramsuni = $this->getCachedUniParams($uni_unicid);
        if (empty($paramsuni) || !is_array($paramsuni)) {
            return '';
        }

        $useragent = array_key_exists('HTTP_USER_AGENT', $_SERVER) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

        $request = new ProductAdditionalInfoRequest(
            $product_id,
            (float) Product::getPriceStatic($product_id, true),
            $uni_currency_code,
            $uni_status,
            (int) Configuration::get(UnipaymentConf::UNIPAYMENT_CART),
            (int) Configuration::get(UnipaymentConf::UNIPAYMENT_GAP),
            $uni_unicid,
            $useragent,
            $this->version,
            Tools::getToken(false),
            $this->getShopSslBaseUrl(),
            $this->context->link->getModuleLink('unipayment', 'getproduct', []),
            $paramsuni,
            $this->trans('Number of months *', [], 'Modules.Unipayment.Shop'),
            $this->trans('Number of months for repayment *', [], 'Modules.Unipayment.Shop'),
            $this->trans('Installment payment', [], 'Modules.Unipayment.Shop'),
            $this->trans('Monthly installment amount', [], 'Modules.Unipayment.Shop'),
            function (string $cid, string $deviceis): ?array {
                return $this->getCachedUniCalculation($cid, $deviceis);
            }
        );

        $payload = $this->getProductAdditionalInfoBlockService()->buildTemplatePayload($request, $product_category_ids);
        if ($payload === null) {
            return '';
        }

        $this->context->smarty->assign($payload['assign']);
        $this->context->smarty->assign([
            'uni_prepare_installmentcheckout_url' => $this->context->link->getModuleLink($this->name, 'prepareinstallmentcheckout', [], true),
            'uni_js_shop_strings' => (string) json_encode([
                'cartAddFailed' => $this->trans('Could not add to cart. Please try again.', [], 'Modules.Unipayment.Shop'),
                'storeError' => $this->trans('An error occurred while contacting the store.', [], 'Modules.Unipayment.Shop'),
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
        ]);

        if ($payload['should_display']) {
            return $this->display(__FILE__, 'unipayment_product.tpl');
        }

        return '';
    }

    /**
     * Базов URL на магазина с HTTPS (ресурси на модула, без mixed content).
     */
    /**
     * Локални @font-face за Roboto Condensed — отделен линк + filemtime за кеш busting (без @import в други CSS).
     */
    private function registerRobotoCondensedFonts(): void
    {
        $path = __DIR__ . '/css/roboto-condensed-font-face.css';
        $this->context->controller->registerStylesheet(
            'unipayment-roboto-condensed-fonts',
            'modules/' . $this->name . '/css/roboto-condensed-font-face.css',
            [
                'media' => 'all',
                'priority' => 190,
                'version' => (string) @filemtime($path),
            ]
        );
    }

    private function getShopSslBaseUrl(): string
    {
        return rtrim($this->context->link->getBaseLink((int) $this->context->shop->id, true), '/');
    }

    /**
     * Абсолютен HTTPS URL към файл под корена на модула (подпапка на магазина вече е в {@see getBaseLink()}).
     */
    private function getModuleSslAssetUrl(string $relativePathFromModuleRoot): string
    {
        return $this->getShopSslBaseUrl() . '/modules/' . $this->name . '/' . ltrim($relativePathFromModuleRoot, '/');
    }

    /**
     * Дали да се заредят CSS/JS за рекламния floater на началната страница (без излишни заявки, ако рекламата е изключена).
     */
    private function shouldRegisterUnipanelHomeAssets(): bool
    {
        if (!$this->active) {
            return false;
        }
        $this->migrateLegacyConfigurationKeysIfNeeded();
        $uni_status = (int) Configuration::get(UnipaymentConf::UNIPAYMENT_STATUS);
        $uni_reklama = (int) Configuration::get(UnipaymentConf::UNIPAYMENT_REKLAMA);
        if ($uni_status !== 1 || $uni_reklama !== 1) {
            return false;
        }
        $cid = (string) (Configuration::get(UnipaymentConf::UNIPAYMENT_UNICID) ?: '');

        return $cid !== '';
    }

    /**
     * Евристика за мобилен UA (същата логика като досега — за PC се показва панелът с toggle).
     */
    private function isUserAgentMobileUnipanel(string $userAgent): bool
    {
        if ($userAgent === '') {
            return false;
        }

        return 1 === preg_match(
            '/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows (ce|phone)|xda|xiino/i',
            $userAgent
        ) || 1 === preg_match(
            '/1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\/)|klon|kpt |kwc\-|kyo(c|k)|le(no|xi)|lg( g|\/(k|l|u)|50|54|\-[a-w])|libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\-|oo|p\-)|sdk\/|se(c(\-|0|1)|47|mc|nd|ri)|sgh\-|shar|sie(\-|m)|sk\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\-9|up(\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\-|your|zeto|zte\-/i',
            substr($userAgent, 0, 4)
        );
    }

    /**
     * Страница за поръчка / checkout: php_self + page_name (PS 9 {@see OrderControllerCore} задава page_name = checkout).
     */
    private function isFrontCheckoutOrderPage(): bool
    {
        $controller = $this->context->controller ?? null;
        if (!$controller instanceof FrontController) {
            return false;
        }
        $phpSelf = isset($controller->php_self) ? (string) $controller->php_self : '';
        if (in_array($phpSelf, ['order', 'checkout'], true)) {
            return true;
        }

        // OrderController (PS 9): getPageName() връща checkout при page_name = checkout (многостъпков checkout).
        return (string) call_user_func([$controller, 'getPageName']) === 'checkout';
    }

    /** Lazy singleton за продуктовия/cart калкулаторен блок. */
    private function getProductAdditionalInfoBlockService(): ProductAdditionalInfoBlockService
    {
        return $this->productAdditionalInfoBlockService
            ??= new ProductAdditionalInfoBlockService(__DIR__);
    }

    /**
     * Блок в страницата за количка.
     *
     * @param array<string, mixed> $params
     */
    public function hookDisplayShoppingCart(array $params): string
    {
        $controller = $this->context->controller ?? null;
        if (!$controller || !isset($controller->php_self) || $controller->php_self !== 'cart') {
            return '';
        }

        $this->migrateLegacyConfigurationKeysIfNeeded();

        $uni_status = (int) Configuration::get(UnipaymentConf::UNIPAYMENT_STATUS);
        $uni_currency_code = (string) $this->context->currency->iso_code;
        if ($uni_status <= 0 || ($uni_currency_code !== 'EUR' && $uni_currency_code !== 'BGN')) {
            return '';
        }

        /** @var Cart $cart */
        $cart = $this->context->cart;
        if (!Validate::isLoadedObject($cart) || $cart->nbProducts() <= 0) {
            return '';
        }

        $uni_price = (float) $cart->getOrderTotal(true, Cart::ONLY_PRODUCTS);

        $uni_unicid = (string) (Configuration::get(UnipaymentConf::UNIPAYMENT_UNICID) ?: '');
        if ($uni_unicid === '') {
            return '';
        }

        $paramsuni = $this->getCachedUniParams($uni_unicid);
        if (!is_array($paramsuni) || ($paramsuni['uni_status'] ?? '') !== 'Yes') {
            return '';
        }

        $uni_maxstojnost = (float) ($paramsuni['uni_maxstojnost'] ?? 0);
        if ($uni_price > $uni_maxstojnost) {
            return '';
        }

        $langID = (int) $this->context->language->id;
        $cartProducts = $cart->getProducts(true);
        if ($cartProducts === [] || !isset($cartProducts[0]['id_product'])) {
            return '';
        }

        $product_category_ids = $this->getCheckoutKopCategoryIdsForCartProducts($cartProducts, $langID);
        if ($product_category_ids === []) {
            return '';
        }

        $firstProductId = (int) ($cartProducts[0]['id_product'] ?? 0);

        $useragent = array_key_exists('HTTP_USER_AGENT', $_SERVER) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

        $request = new ProductAdditionalInfoRequest(
            $firstProductId,
            $uni_price,
            $uni_currency_code,
            $uni_status,
            (int) Configuration::get(UnipaymentConf::UNIPAYMENT_CART),
            (int) Configuration::get(UnipaymentConf::UNIPAYMENT_GAP),
            $uni_unicid,
            $useragent,
            $this->version,
            Tools::getToken(false),
            $this->getShopSslBaseUrl(),
            $this->context->link->getModuleLink('unipayment', 'getproduct', []),
            $paramsuni,
            $this->trans('Number of months *', [], 'Modules.Unipayment.Shop'),
            $this->trans('Number of months for repayment *', [], 'Modules.Unipayment.Shop'),
            $this->trans('Installment payment', [], 'Modules.Unipayment.Shop'),
            $this->trans('Monthly installment amount', [], 'Modules.Unipayment.Shop'),
            function (string $cid, string $deviceis): ?array {
                return $this->getCachedUniCalculation($cid, $deviceis);
            },
            true
        );

        $payload = $this->getProductAdditionalInfoBlockService()->buildTemplatePayload($request, $product_category_ids);
        if ($payload === null) {
            return '';
        }
        if (!($payload['should_display'] ?? false) && !($payload['render_cart_latent'] ?? false)) {
            return '';
        }

        $this->context->smarty->assign($payload['assign']);
        $this->context->smarty->assign([
            'uni_prepare_cart_checkout_url' => $this->context->link->getModuleLink($this->name, 'preparecartcheckout', [], true),
            'uni_js_shop_strings' => (string) json_encode([
                'checkoutRedirectFailed' => $this->trans('Could not redirect to checkout. Please try again.', [], 'Modules.Unipayment.Shop'),
                'storeError' => $this->trans('An error occurred while contacting the store.', [], 'Modules.Unipayment.Shop'),
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
        ]);

        return $this->display(__FILE__, 'unipayment_cart.tpl');
    }

    /**
     * Блок в началната страница.
     *
     * @param array<string, mixed> $params
     */
    public function hookDisplayHome(array $params): string
    {
        $this->migrateLegacyConfigurationKeysIfNeeded();

        $uni_status = (int) Configuration::get(UnipaymentConf::UNIPAYMENT_STATUS);
        $uni_reklama = (int) Configuration::get(UnipaymentConf::UNIPAYMENT_REKLAMA);

        if ($uni_status === 1 && $uni_reklama === 1) {
            $uni_unicid = (string) (Configuration::get(UnipaymentConf::UNIPAYMENT_UNICID) ?: '');
            if ($uni_unicid === '') {
                return '';
            }

            $paramsuni = $this->getCachedUniParams($uni_unicid);
            if (!is_array($paramsuni)) {
                return '';
            }
            $useragent = array_key_exists('HTTP_USER_AGENT', $_SERVER) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
            $deviceis = $this->isUserAgentMobileUnipanel($useragent) ? 'mobile' : 'pc';
            $uni_backurl = isset($paramsuni['uni_backurl']) ? (string) $paramsuni['uni_backurl'] : '';
            $uni_logo = $this->getModuleSslAssetUrl('css/uni_logo.jpg');
            $uni_picture = $this->getModuleSslAssetUrl('css/unim.png');
            $uni_container_txt1 = isset($paramsuni['uni_container_txt1']) ? (string) $paramsuni['uni_container_txt1'] : '';
            $uni_container_txt2 = isset($paramsuni['uni_container_txt2']) ? (string) $paramsuni['uni_container_txt2'] : '';
            $uni_container_status = isset($paramsuni['uni_container_status']) ? (string) $paramsuni['uni_container_status'] : 'No';
            $uni_status_cp = isset($paramsuni['uni_status']) ? (string) $paramsuni['uni_status'] : 'No';
            $this->context->smarty->assign(
                [
                    'deviceis' => $deviceis,
                    'uni_container_status' => $uni_container_status,
                    'uni_logo' => $uni_logo,
                    'uni_backurl' => $uni_backurl,
                    'uni_picture' => $uni_picture,
                    'uni_container_txt1' => $uni_container_txt1,
                    'uni_container_txt2' => $uni_container_txt2,
                    'uni_status_cp' => $uni_status_cp,
                ]
            );
            return $this->display(__FILE__, 'views/templates/hook/unipanel.tpl');
        } else {
            return '';
        }
    }

    /**
     * Cached UNI parameters from bank API.
     *
     * @return array<string, mixed>|null
     */
    private function getCachedUniParams(string $cid, bool $forceReload = false): ?array
    {
        $cacheKey = 'params:' . md5($cid);
        if (!$forceReload) {
            $cached = $this->readApiCache($cacheKey, 600);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        curl_setopt($ch, CURLOPT_URL, $this->getLiveUrl() . '/function/getparameters.php?cid=' . urlencode($cid));

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        if ($response === false || $httpCode !== 200) {
            return null;
        }

        $params = json_decode((string) $response, true);
        if (!is_array($params)) {
            return null;
        }

        $this->writeApiCache($cacheKey, 'params', $params);

        return $params;
    }

    /**
     * Кеширани UI параметри от getcalculation.php (по CID и устройство).
     *
     * @return array<string, mixed>|null
     */
    private function getCachedUniCalculation(string $cid, string $deviceis, bool $forceReload = false): ?array
    {
        $cacheKey = 'calc:' . md5($cid . '_' . $deviceis);
        if (!$forceReload) {
            $cached = $this->readApiCache($cacheKey, 600);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        curl_setopt(
            $ch,
            CURLOPT_URL,
            $this->getLiveUrl() . '/function/getcalculation.php?cid=' . urlencode($cid) . '&deviceis=' . urlencode($deviceis)
        );

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        if ($response === false || $httpCode !== 200) {
            return null;
        }

        $params = json_decode((string) $response, true);
        if (!is_array($params)) {
            return null;
        }

        $this->writeApiCache($cacheKey, 'calc', $params);

        return $params;
    }

    /**
     * Публичен вход за webhook/скриптове: мигрира legacy lowercase ключове към UNIPAYMENT_* (идемпотентно).
     */
    public function migrateLegacyUnipaymentKeysIfNeeded(): void
    {
        $this->migrateLegacyConfigurationKeysIfNeeded();
    }

    /**
     * Принудително презареждане на кеша с параметри от банковия API (getparameters.php).
     * Използва се от админ refresh бутона заедно с обновяване на КОП мапинга.
     */
    public function refreshCachedUniParamsFromApi(): bool
    {
        $this->migrateLegacyConfigurationKeysIfNeeded();
        $cid = (string) (Configuration::get(UnipaymentConf::UNIPAYMENT_UNICID) ?: '');
        if ($cid === '') {
            return false;
        }

        return is_array($this->getCachedUniParams($cid, true));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readApiCache(string $cacheKey, int $ttlSeconds): ?array
    {
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
        if ($updatedTs === false || (time() - (int) $updatedTs) >= $ttlSeconds) {
            return null;
        }
        $payload = isset($row['payload']) ? json_decode((string) $row['payload'], true) : null;

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeApiCache(string $cacheKey, string $group, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
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
            'cache_group' => $group,
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

    private function escapeSqlLiteral(string $value): string
    {
        return addslashes($value);
    }

    /**
     * Еднократно копира стойности от legacy lowercase ключове (1.7) към UNIPAYMENT_*.
     */
    private function migrateLegacyConfigurationKeysIfNeeded(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $pairs = [
            'unipayment_status' => UnipaymentConf::UNIPAYMENT_STATUS,
            'unipayment_unicid' => UnipaymentConf::UNIPAYMENT_UNICID,
            'unipayment_reklama' => UnipaymentConf::UNIPAYMENT_REKLAMA,
            'unipayment_cart' => UnipaymentConf::UNIPAYMENT_CART,
            'unipayment_debug' => UnipaymentConf::UNIPAYMENT_DEBUG,
            'unipayment_gap' => UnipaymentConf::UNIPAYMENT_GAP,
        ];

        foreach ($pairs as $legacy => $upper) {
            if (!Configuration::hasKey($legacy) || Configuration::hasKey($upper)) {
                continue;
            }
            Configuration::updateValue($upper, Configuration::get($legacy));
        }
    }

    /** Създава PS_OS_UNIPAYMENT при липса на валидно състояние. */
    private function ensureOrderState(): bool
    {
        $existingId = (int) Configuration::get('PS_OS_UNIPAYMENT');
        if ($existingId > 0) {
            $existing = new OrderState($existingId);
            if (Validate::isLoadedObject($existing)) {
                return true;
            }
        }

        $orderState = new OrderState();

        foreach (Language::getLanguages(false) as $language) {
            $idLang = (int) $language['id_lang'];
            $locale = isset($language['locale']) ? (string) $language['locale'] : null;
            $orderState->name[$idLang] = $this->trans(
                'UniCredit purchases on credit',
                [],
                'Modules.Unipayment.Shop',
                $locale
            );
        }

        $orderState->send_mail = false;
        $orderState->template = '';
        $orderState->invoice = false;
        $orderState->color = '#DDEAF8';
        $orderState->unremovable = false;
        $orderState->logable = false;
        $orderState->module_name = $this->name;

        if (!$orderState->add()) {
            return false;
        }

        return Configuration::updateValue('PS_OS_UNIPAYMENT', (int) $orderState->id);
    }

    /**
     * @see Module::isUsingNewTranslationSystem()
     */
    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    /**
     * BO „Конфигурирай“: пренасочва към Symfony маршрута на формата.
     *
     * @return string празен низ след успешен redirect; иначе съобщение за грешка
     */
    public function getContent(): string
    {
        $this->migrateLegacyConfigurationKeysIfNeeded();
        $this->ensureRequiredHooksAreRegistered();

        $route = null;
        $router = SymfonyContainer::getInstance()->get('router');

        try {
            $route = $router->generate('unipayment_configuration_form');
        } catch (Exception $exception) {
            // In some setups Symfony routes cache is stale right after module changes.
            Tools::clearSf2Cache();
            try {
                $router = SymfonyContainer::getInstance()->get('router');
                $route = $router->generate('unipayment_configuration_form');
            } catch (Exception $innerException) {
                return $this->trans(
                    'Configuration route is not available yet. Please clear cache and try again.',
                    [],
                    'Modules.Unipayment.Admin'
                );
            }
        }

        Tools::redirectAdmin($route);
        return '';
    }

    private function ensureRequiredHooksAreRegistered(): void
    {
        $requiredHooks = [
            'actionFrontControllerSetMedia',
            'paymentOptions',
            'displayProductAdditionalInfo',
            'displayShoppingCart',
            'paymentReturn',
            'displayHome',
            'actionValidateOrder',
        ];

        foreach ($requiredHooks as $hookName) {
            if ($this->isHookLinkedInDatabase($hookName)) {
                continue;
            }
            try {
                $this->registerHook($hookName);
            } catch (Exception $e) {
                $msg = $e->getMessage();
                if (stripos($msg, '1062') !== false || stripos($msg, 'Duplicate entry') !== false) {
                    continue;
                }
                throw $e;
            }
        }
    }

    /**
     * Директна проверка в ps_hook_module — {@see Module::isRegisteredInHook} понякога връща false в BO (PS 8 / multishop),
     * което води до повторен INSERT и грешка 1062.
     */
    private function isHookLinkedInDatabase(string $hookName): bool
    {
        $idHook = (int) Hook::getIdByName($hookName);
        if ($idHook <= 0) {
            return false;
        }
        $idModule = (int) $this->id;
        if ($idModule <= 0) {
            return false;
        }

        $sql = 'SELECT 1 FROM `' . _DB_PREFIX_ . 'hook_module` WHERE `id_module` = ' . $idModule . ' AND `id_hook` = ' . $idHook;

        return (bool) Db::getInstance()->getValue($sql);
    }

    /**
     * Дали валутата на количката е разрешена за модула (checkbox: списък; radio: един {@see Currency} от {@see PaymentModule::getCurrency}).
     *
     * @param \Cart $cart
     */
    public function checkCurrency($cart): bool
    {
        if (!Validate::isLoadedObject($cart) || (int) $cart->id_currency <= 0) {
            return false;
        }

        $currency_order = new Currency((int) $cart->id_currency);
        if (!Validate::isLoadedObject($currency_order)) {
            return false;
        }

        $currencies_module = $this->getCurrency((int) $cart->id_currency);
        if ($currencies_module === false) {
            return false;
        }

        if (is_array($currencies_module)) {
            foreach ($currencies_module as $currency_module) {
                if ((int) $currency_order->id === (int) ($currency_module['id_currency'] ?? 0)) {
                    return true;
                }
            }

            return false;
        }

        if ($currencies_module instanceof Currency) {
            return (int) $currency_order->id === (int) $currencies_module->id;
        }

        return false;
    }

    /**
     * Резерв за пренасочване към банката (process1), ако при refresh липсват GET параметри.
     *
     * @param array<string, mixed> $extras Резултат от UniCreditPostValidateService::run
     */
    public function persistUniCreditBankRedirectCookie(int $idOrder, array $extras): void
    {
        if ($idOrder <= 0 || (int) ($extras['uni_proces1'] ?? 0) !== 1) {
            return;
        }
        $application = trim((string) ($extras['uni_application'] ?? ''));
        $api = trim((string) ($extras['uni_api'] ?? ''));
        if ($application === '' || $api === '' || $this->buildUniCreditBankRedirectUrl($application, $api) === '') {
            return;
        }
        $payload = [
            'id_order' => $idOrder,
            'uni_proces1' => 1,
            'uni_application' => $application,
            'uni_api' => $api,
            'ts' => time(),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }
        $this->context->cookie->__set(self::UNIPAYMENT_COOKIE_BANK_REDIRECT, $json);
        $this->context->cookie->write();
    }

    /**
     * Страница след плащане: лизинг (uni_proces2 + uniresult) или пренасочване към банката (uni_proces1).
     *
     * @param array{order?: \Order} $params
     */
    public function hookPaymentReturn(array $params): string
    {
        if (!$this->active) {
            return '';
        }
        $controller = $this->context->controller ?? null;
        if ($controller !== null) {
            $paymentReturnCssPath = __DIR__ . '/css/payment_return.css';
            $paymentReturnJsPath = __DIR__ . '/js/payment_return.js';
            $controller->registerStylesheet(
                'unipayment-payment-return',
                'modules/' . $this->name . '/css/payment_return.css',
                [
                    'media' => 'all',
                    'priority' => 200,
                    'version' => (string) @filemtime($paymentReturnCssPath),
                ]
            );
            $controller->registerJavascript(
                'unipayment-payment-return-js',
                'modules/' . $this->name . '/js/payment_return.js',
                [
                    'position' => 'bottom',
                    'priority' => 200,
                    'version' => (string) @filemtime($paymentReturnJsPath),
                ]
            );
        }

        $smarty = $this->context->smarty;
        $link = $this->context->link;

        $defaults = [
            'uni_status' => 'failed',
            'uni_pause_txt' => '',
            'uni_logo' => '',
            'uni_proces1' => '',
            'uni_proces2' => '',
            'uni_application' => '',
            'uni_api' => '',
            'result_html' => '',
            'uni_process2_display' => false,
            'uni_process1_redirect' => false,
            'uni_redirect_url' => '',
            'link' => $link,
        ];

        if (!isset($params['order']) || !Validate::isLoadedObject($params['order'])) {
            $smarty->assign($defaults);

            return $this->display(__FILE__, 'views/templates/hook/payment_return.tpl');
        }

        /** @var Order $order */
        $order = $params['order'];
        $stateId = (int) $order->getCurrentState();

        $allowedStates = array_values(array_filter([
            (int) Configuration::get('PS_OS_UNIPAYMENT'),
            (int) Configuration::get('PS_OS_OUTOFSTOCK'),
            (int) Configuration::get('PS_OS_OUTOFSTOCK_UNPAID'),
        ], static fn(int $id): bool => $id > 0));

        if (!in_array($stateId, $allowedStates, true)) {
            $smarty->assign($defaults);

            return $this->display(__FILE__, 'views/templates/hook/payment_return.tpl');
        }

        $idOrder = (int) $order->id;

        $uniProces1 = (string) Tools::getValue('uni_proces1');
        $uniApplication = (string) Tools::getValue('uni_application');
        $uniApi = (string) Tools::getValue('uni_api');
        $uniProces2 = (string) Tools::getValue('uni_proces2');

        $cookieBackup = $this->readUniCreditBankRedirectCookieForOrder($idOrder);
        $hadMatchingBankRedirectCookie = $cookieBackup !== null;
        if ($cookieBackup !== null) {
            if ($uniProces1 === '') {
                $uniProces1 = $cookieBackup['uni_proces1'];
            }
            if ($uniApplication === '') {
                $uniApplication = $cookieBackup['uni_application'];
            }
            if ($uniApi === '') {
                $uniApi = $cookieBackup['uni_api'];
            }
        }

        $uniPlaintext64 = (string) Tools::getValue('uniresult');
        if ($uniPlaintext64 === '' && !empty($_SERVER['QUERY_STRING'])) {
            parse_str((string) $_SERVER['QUERY_STRING'], $queryParts);
            if (isset($queryParts['uniresult'])) {
                $uniPlaintext64 = (string) $queryParts['uniresult'];
            }
        }

        $resultHtml = '';
        if ($uniPlaintext64 !== '') {
            $decoded = base64_decode(rawurldecode($uniPlaintext64), true);
            if ($decoded !== false && $decoded !== '') {
                $resultHtml = $decoded;
            }
        }

        $redirectUrl = $this->buildUniCreditBankRedirectUrl($uniApplication, $uniApi);
        $base = rtrim($link->getBaseLink((int) $this->context->shop->id, true), '/');
        $uniLogo = $base . '/modules/' . $this->name . '/css/uni_logo.jpg';

        $process2On = $uniProces2 === '1';
        $process1On = $uniProces1 === '1';

        if ($process1On && $redirectUrl !== '') {
            $this->clearUniCreditBankRedirectCookie();
        } elseif ($hadMatchingBankRedirectCookie && $process1On && $redirectUrl === '') {
            $this->clearUniCreditBankRedirectCookie();
        }

        $smarty->assign([
            'uni_status' => 'ok',
            'uni_pause_txt' => $this->trans(
                'You will be redirected to UniCredit.',
                [],
                'Modules.Unipayment.Shop'
            ),
            'uni_logo' => $uniLogo,
            'uni_proces1' => $uniProces1,
            'uni_application' => $uniApplication,
            'uni_api' => $uniApi,
            'uni_proces2' => $uniProces2,
            'result_html' => $resultHtml,
            'uni_process2_display' => $process2On && $resultHtml !== '',
            'uni_process1_redirect' => $process1On && $redirectUrl !== '',
            'uni_redirect_url' => $redirectUrl,
            'link' => $link,
        ]);

        return $this->display(__FILE__, 'views/templates/hook/payment_return.tpl');
    }

    /**
     * @return array{uni_proces1: string, uni_application: string, uni_api: string}|null
     */
    private function readUniCreditBankRedirectCookieForOrder(int $idOrder): ?array
    {
        if ($idOrder <= 0) {
            return null;
        }
        $cookie = $this->context->cookie;
        if (!isset($cookie->{self::UNIPAYMENT_COOKIE_BANK_REDIRECT})) {
            return null;
        }
        $raw = (string) $cookie->__get(self::UNIPAYMENT_COOKIE_BANK_REDIRECT);
        if ($raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->clearUniCreditBankRedirectCookie();

            return null;
        }
        if ((int) ($data['id_order'] ?? 0) !== $idOrder) {
            return null;
        }
        $ts = (int) ($data['ts'] ?? 0);
        if ($ts > 0 && (time() - $ts) > self::UNIPAYMENT_BANK_REDIRECT_COOKIE_TTL) {
            $this->clearUniCreditBankRedirectCookie();

            return null;
        }
        if ((int) ($data['uni_proces1'] ?? 0) !== 1) {
            return null;
        }
        $application = trim((string) ($data['uni_application'] ?? ''));
        $api = trim((string) ($data['uni_api'] ?? ''));
        if ($application === '' || $api === '' || $this->buildUniCreditBankRedirectUrl($application, $api) === '') {
            return null;
        }

        return [
            'uni_proces1' => '1',
            'uni_application' => $application,
            'uni_api' => $api,
        ];
    }

    /** Премахва резервната бисквитка за пренасочване към банката. */
    private function clearUniCreditBankRedirectCookie(): void
    {
        $this->context->cookie->__set(self::UNIPAYMENT_COOKIE_BANK_REDIRECT, null);
        $this->context->cookie->write();
    }

    /**
     * Пълен URL към банковата стъпка (само http/https).
     */
    private function buildUniCreditBankRedirectUrl(string $application, string $api): string
    {
        $base = trim($application);
        $suffix = trim($api);
        if ($base === '' || $suffix === '') {
            return '';
        }

        $url = rtrim($base, '/') . '/' . ltrim($suffix, '/');
        if (!preg_match('#^https?://#i', $url)) {
            return '';
        }

        return $url;
    }

    /**
     * Id-та на категории за търсене на КОП в checkout:
     * работим само с главни категории ниво 1 (директни деца на Home), по реда на срещане.
     * При 2+ продукта ползваме сечение; при празно сечение — fallback към първия продукт.
     *
     * @param array<int, array<string, mixed>> $cartProducts редове от Cart::getProducts(true)
     *
     * @return list<int>
     */
    public function getCheckoutKopCategoryIdsForCartProducts(array $cartProducts, int $langID): array
    {
        $uniqueProductIdsOrdered = [];
        $seenPid = [];
        foreach ($cartProducts as $uni_line) {
            $pid = (int) ($uni_line['id_product'] ?? 0);
            if ($pid <= 0 || isset($seenPid[$pid])) {
                continue;
            }
            $seenPid[$pid] = true;
            $uniqueProductIdsOrdered[] = $pid;
        }

        if ($uniqueProductIdsOrdered === []) {
            return [];
        }

        if (count($uniqueProductIdsOrdered) === 1) {
            return $this->getTopLevelCategoryIdsForProduct($uniqueProductIdsOrdered[0], $langID);
        }

        $perProductOrdered = [];
        foreach ($uniqueProductIdsOrdered as $pid) {
            $perProductOrdered[$pid] = $this->getTopLevelCategoryIdsForProduct($pid, $langID);
        }

        $firstPid = $uniqueProductIdsOrdered[0];
        $intersection = $perProductOrdered[$firstPid] ?? [];
        foreach ($uniqueProductIdsOrdered as $pid) {
            if ($pid === $firstPid) {
                continue;
            }
            $intersection = array_values(array_intersect($intersection, $perProductOrdered[$pid] ?? []));
        }

        $inIntersection = array_fill_keys($intersection, true);
        $orderedIntersection = [];
        foreach ($perProductOrdered[$firstPid] as $cid) {
            if (isset($inIntersection[$cid])) {
                $orderedIntersection[] = $cid;
            }
        }

        if ($orderedIntersection !== []) {
            return $orderedIntersection;
        }

        return $this->getTopLevelCategoryIdsForProduct($firstPid, $langID);
    }

    /**
     * @return list<int>
     */
    private function getOrderedProductCategoriesRaw(int $pid): array
    {
        $ordered = [];
        $seen = [];
        $cats = Product::getProductCategories($pid);
        if (!is_array($cats)) {
            return [];
        }
        foreach ($cats as $cid) {
            $cid = (int) $cid;
            if ($cid > 0 && !isset($seen[$cid])) {
                $seen[$cid] = true;
                $ordered[] = $cid;
            }
        }

        return $ordered;
    }

    /**
     * @return list<int>
     */
    private function getTopLevelCategoryIdsForProduct(int $pid, int $langID): array
    {
        $ordered = $this->getOrderedProductCategoriesRaw($pid);
        $topLevel = [];
        $seenTop = [];
        foreach ($ordered as $cid) {
            $topCid = $this->resolveLevelOneCategoryId($cid);
            if ($topCid > 0 && !isset($seenTop[$topCid])) {
                $seenTop[$topCid] = true;
                $topLevel[] = $topCid;
            }
        }
        if ($topLevel !== []) {
            return $topLevel;
        }

        $product = new Product($pid, false, $langID);
        $def = (int) $product->id_category_default;
        $defTop = $this->resolveLevelOneCategoryId($def);

        return $defTop > 0 ? [$defTop] : [];
    }

    /**
     * Връща id на главната категория ниво 1 (директно дете на Home) за подадена категория.
     */
    private function resolveLevelOneCategoryId(int $categoryId): int
    {
        if ($categoryId <= 0) {
            return 0;
        }

        $homeId = (int) Configuration::get('PS_HOME_CATEGORY');
        if ($homeId <= 0 || $categoryId === $homeId) {
            return 0;
        }

        $current = $categoryId;
        $visited = [];
        for ($i = 0; $i < 32; ++$i) {
            if (isset($visited[$current])) {
                break;
            }
            $visited[$current] = true;

            $category = new Category($current, (int) Configuration::get('PS_LANG_DEFAULT'));
            if (!Validate::isLoadedObject($category)) {
                break;
            }
            $parentId = $this->getCategoryParentId($current);
            if ($parentId === $homeId) {
                return $current;
            }
            if ($parentId <= 0 || $parentId === $current) {
                break;
            }

            $current = $parentId;
        }

        return 0;
    }

    private function getCategoryParentId(int $categoryId): int
    {
        if ($categoryId <= 0) {
            return 0;
        }

        $sql = 'SELECT c.id_parent FROM ' . _DB_PREFIX_ . 'category c WHERE c.id_category = ' . (int) $categoryId;
        $value = Db::getInstance()->getValue($sql);
        if ($value === false || $value === null || $value === '') {
            return 0;
        }

        return (int) $value;
    }
}
