<?php

/**
 * IDE stubs only (Intelephense / static analysis). Not loaded at runtime; PrestaShop defines these classes.
 */

namespace {
    if (!defined('_PS_VERSION_')) {
        define('_PS_VERSION_', '8.0.0');
    }
    if (!defined('_PS_BASE_URL_')) {
        define('_PS_BASE_URL_', 'https://example.com');
    }
    if (!defined('__PS_BASE_URI__')) {
        define('__PS_BASE_URI__', '/');
    }
    if (!defined('_PS_MODULE_DIR_')) {
        define('_PS_MODULE_DIR_', '/modules/');
    }
    if (!defined('_DB_PREFIX_')) {
        define('_DB_PREFIX_', 'ps_');
    }

    class Db
    {
        public static function getInstance(): self
        {
            return new self();
        }

        /**
         * @param string $sql
         * @return mixed
         */
        public function getValue($sql)
        {
            return false;
        }
    }

    class Hook
    {
        public static function getIdByName($hookName): int
        {
            return 0;
        }
    }

    class Smarty
    {
        /**
         * @param array<string, mixed>|string $tpl_var
         * @param mixed $value
         */
        public function assign($tpl_var, $value = null, $nocache = false): void {}
    }

    class Controller
    {
        /** @var string */
        public $php_self = '';

        public function registerStylesheet($id, $path, $params = []): void {}

        public function registerJavascript($id, $path, $params = []): void {}
    }

    class Link
    {
        public function getBaseLink($idShop, $ssl = false, $relative = false): string
        {
            return '';
        }

        /**
         * @param array<string, mixed> $params
         */
        public function getModuleLink(
            $module,
            $controller = 'default',
            array $params = [],
            $ssl = null,
            $relativeProtocol = false,
            $addEcSl = false
        ): string {
            return '';
        }

        /**
         * @param array<string, mixed>|null $params
         */
        public function getPageLink($controller, $ssl = null, $idLang = null, $params = null, $addAnchor = false): string
        {
            return '';
        }
    }

    class Currency
    {
        /** @var int */
        public $id = 1;

        /** @var string */
        public $iso_code = '';
    }

    class Shop
    {
        public const CONTEXT_ALL = 1;

        /** @var int */
        public $id = 0;

        public static function isFeatureActive(): bool
        {
            return false;
        }

        public static function setContext($type, $idShop = null): void {}
    }

    class Cookie
    {
        /** @var array<string, mixed> */
        public $_content = [];

        public function __get($key)
        {
            return false;
        }

        public function __set($key, $value): void {}

        public function write(): bool
        {
            return true;
        }
    }

    class Cart
    {
        public const ONLY_PRODUCTS = 1;

        public const BOTH = 3;

        /** @var int */
        public $id = 0;

        /** @var int */
        public $id_customer = 0;

        /** @var int */
        public $id_address_delivery = 0;

        /** @var int */
        public $id_address_invoice = 0;

        /** @var int */
        public $id_currency = 0;

        public function __construct($id = null) {}

        /**
         * @param bool $withTax
         * @param int  $type
         */
        public function getOrderTotal($withTax = true, $type = 3, $products = null, $id_carrier = null, $use_cache = true): float
        {
            return 0.0;
        }

        public function nbProducts(): int
        {
            return 0;
        }

        /**
         * @param mixed $quantity
         * @param int   $id_product
         * @param int|null $id_product_attribute
         * @param mixed $id_customization
         * @param string $operator
         * @param int $id_address_delivery
         * @return bool|int
         */
        public function updateQty(
            $quantity,
            $id_product,
            $id_product_attribute = null,
            $id_customization = false,
            $operator = 'up',
            $id_address_delivery = 0,
            $shop = null,
            $auto_add_cart_rule = true,
            $skipAvailabilityCheckOutOfStock = false,
            $preserveGiftRemoval = true,
            $useOrderPrices = false
        ) {
            return true;
        }

        /**
         * @return array<int, array<string, mixed>>
         */
        public function getProducts($refresh = false, $id_product = false, $id_country = null, $fullInfos = true, $horizontal = true): array
        {
            return [];
        }
    }

    class Customer
    {
        /** @var int */
        public $id = 0;

        /** @var string */
        public $firstname = '';

        /** @var string */
        public $lastname = '';

        /** @var string */
        public $email = '';

        /** @var int */
        public $id_lang = 1;

        /** @var string */
        public $secure_key = '';

        public function __construct($id = null) {}

        /**
         * @return array<int, array<string, mixed>>
         */
        public function getAddresses($idLang): array
        {
            return [];
        }

        public function isLogged($withGuest = false): bool
        {
            return false;
        }
    }

    class Context
    {
        /** @var Controller|null */
        public $controller;

        /** @var Currency */
        public $currency;

        /** @var Link */
        public $link;

        /** @var Shop */
        public $shop;

        /** @var Smarty */
        public $smarty;

        /** @var Cart|null */
        public $cart;

        /** @var Customer|null */
        public $customer;

        /** @var Language|null */
        public $language;

        /** @var Cookie */
        public $cookie;

        public function __construct()
        {
            $this->controller = new Controller();
            $this->currency = new Currency();
            $this->link = new Link();
            $this->shop = new Shop();
            $this->smarty = new Smarty();
            $this->cart = new Cart();
            $this->customer = new Customer();
            $this->language = new Language();
            $this->cookie = new Cookie();
        }
    }

    class Media
    {
        public static function getMediaPath($filepath): string
        {
            return '';
        }
    }

    abstract class Module
    {
        /** @var bool */
        public $active = true;

        /** @var string */
        public $name;

        /** @var string */
        public $tab;

        /** @var string */
        public $version;

        /** @var string */
        public $author;

        /** @var int */
        public $need_instance;

        /** @var bool */
        public $bootstrap;

        /** @var string */
        public $displayName;

        /** @var string */
        public $description;

        /** @var string */
        public $confirmUninstall;

        /** @var array<string, string> */
        public $ps_versions_compliancy;

        /** @var Context */
        public $context;

        public function __construct() {}

        /**
         * @param array<string, string|int|float> $parameters
         */
        public function trans($id, $parameters = [], $domain = null, $locale = null)
        {
            return '';
        }

        public function l($string, $specific = false, $id_lang = null)
        {
            return $string;
        }

        public function install(): bool
        {
            return false;
        }

        public function uninstall(): bool
        {
            return false;
        }

        public function registerHook($hookName): bool
        {
            return false;
        }

        public function isRegisteredInHook($hookName): bool
        {
            return false;
        }

        public function displayError($string)
        {
            return '';
        }

        public function display($file, $template)
        {
            return '';
        }

        public static function getInstanceByName($name)
        {
            return null;
        }

        /**
         * @return array<int, array{name: string}>
         */
        public static function getPaymentModules(): array
        {
            return [];
        }

        public function fetch($templatePath, $cache_id = null, $compile_id = null)
        {
            return '';
        }

        public function getLocalPath(): string
        {
            return '';
        }
    }

    abstract class PaymentModule extends Module
    {
        /** @var int */
        public $id = 0;

        /** @var int */
        public $currentOrder = 0;

        /** @var bool */
        public $currencies = true;

        /** @var string checkbox|radio */
        public $currencies_mode = 'checkbox';

        /**
         * @return Currency|array<int, array{id_currency: int}>|false
         */
        public function getCurrency($current_id_currency = null)
        {
            return false;
        }

        public function checkCurrency($cart): bool
        {
            return true;
        }

        /**
         * @param array<string, mixed> $extra_vars
         */
        public function validateOrder(
            $id_cart,
            $id_order_state,
            $amount_paid,
            $payment_method = 'Unknown',
            $message = null,
            $extra_vars = [],
            $currency_special = null,
            $dont_touch_amount = false,
            $secure_key = false,
            $shop = null,
            ?string $order_reference = null
        ): bool {
            return true;
        }
    }

    /**
     * @see classes/controller/FrontController.php (PrestaShop core)
     */
    abstract class FrontController
    {
        public function initContent() {}
    }

    /**
     * @see classes/controller/ModuleFrontController.php (PrestaShop core)
     */
    abstract class ModuleFrontController extends FrontController
    {
        /** @var bool */
        public $ajax = false;

        /** @var Context|null */
        public $context;

        /** @var Module|null */
        public $module;

        public function postProcess() {}

        public function initContent()
        {
            parent::initContent();
        }

        public function displayAjax() {}
    }

    class Configuration
    {
        public static function get($key, $idLang = null, $idShopGroup = null, $idShop = null, $default = false)
        {
            return $default;
        }

        public static function hasKey($key, $idLang = null, $idShopGroup = null, $idShop = null): bool
        {
            return false;
        }

        public static function updateValue($key, $values, $html = false, $idShopGroup = null, $idShop = null): bool
        {
            return true;
        }

        public static function deleteByName($key, $idShopGroup = null, $idShop = null): bool
        {
            return true;
        }
    }

    class Language
    {
        /** @var int */
        public $id = 1;

        /**
         * @return array<int, array{id_lang:int}>
         */
        public static function getLanguages($active = true, $idShop = false): array
        {
            return [];
        }
    }

    class Order
    {
        /** @var int */
        public $id = 0;

        public function __construct($id = null, $idLang = null) {}

        public function getCurrentState(): int
        {
            return 0;
        }
    }

    class OrderState
    {
        /** @var array<int, string> */
        public $name = [];
        /** @var bool */
        public $send_mail = false;
        /** @var string */
        public $template = '';
        /** @var bool */
        public $invoice = false;
        /** @var string */
        public $color = '';
        /** @var bool */
        public $unremovable = false;
        /** @var bool */
        public $logable = false;
        /** @var string */
        public $module_name = '';
        /** @var int */
        public $id = 0;

        public function __construct($id = null, $idLang = null) {}

        public function add($autodate = true, $nullValues = false): bool
        {
            return true;
        }

        public function delete(): bool
        {
            return true;
        }
    }

    class Product
    {
        /** @var int */
        public $id_category_default = 0;

        public function __construct($id_product = null, $full = false, $id_lang = null, $id_shop = null, $context = null) {}

        /**
         * @return array<int, int>
         */
        public static function getProductCategories($idProduct, $idLang = null): array
        {
            return [];
        }

        /**
         * @param int|null $idProductAttribute
         * @param int|null $idCustomer
         * @param int|null $idCart
         */
        public static function getPriceStatic(
            $idProduct,
            $usetax = true,
            $idProductAttribute = null,
            $decimals = 6,
            $divisor = null,
            $only_reduc = false,
            $usereduc = true,
            $quantity = 1,
            $force_associated_tax = false,
            $id_customer = null,
            $id_cart = null,
            $id_address = null,
            $specificPriceOutput = null,
            $with_ecotax = true,
            $use_group_reduction = true,
            $context = null,
            $use_customer_price = true,
            $id_customization = null
        ): float {
            return 0.0;
        }
    }

    class Mail
    {
        /**
         * @param array<string, string> $templateVars
         * @param string|array<string> $to
         */
        public static function send(
            $idLang,
            $template,
            $subject,
            $templateVars,
            $to,
            $toName = null,
            $from = null,
            $fromName = null,
            $fileAttachment = null,
            $mode_smtp = null,
            $templatePath = null,
            $die = false,
            $idShop = null,
            $bcc = null,
            $replyTo = null,
            $replyToName = null
        ) {
            return true;
        }
    }

    class Validate
    {
        public static function isLoadedObject($object): bool
        {
            return true;
        }
    }

    class Tools
    {
        /**
         * @param mixed $defaultValue
         * @return mixed
         */
        public static function getValue($key, $defaultValue = false)
        {
            return $defaultValue;
        }

        public static function getIsset($key): bool
        {
            return false;
        }

        public static function getToken($inline = false): string
        {
            return '';
        }

        public static function redirectAdmin($url): void {}

        public static function clearSf2Cache($env = null): bool
        {
            return true;
        }

        /**
         * @param mixed $data
         */
        public static function jsonEncode($data, $options = 0, $depth = 512): string
        {
            return '';
        }

        public static function redirect($url, $base_uri = __PS_BASE_URI__, ?Link $link = null, $headers = null): void {}

        public static function usingSecureMode(): bool
        {
            return false;
        }
    }

    /**
     * @see \PaymentOptionsFinderCore
     */
    class PaymentOptionsFinder
    {
        /**
         * @param bool $free
         *
         * @return array<string, array<int, array<string, mixed>>>
         */
        public function present($free = false): array
        {
            return [];
        }
    }
}

namespace PrestaShop\PrestaShop\Core\Payment {
    class PaymentOption
    {
        public function setModuleName($name): self
        {
            return $this;
        }

        public function setCallToActionText($text): self
        {
            return $this;
        }

        public function setAction($action): self
        {
            return $this;
        }

        public function setLogo($logo): self
        {
            return $this;
        }

        public function setAdditionalInformation($html): self
        {
            return $this;
        }
    }
}

namespace PrestaShop\PrestaShop\Adapter {
    class SymfonyContainer
    {
        public static function getInstance()
        {
            return new class {
                public function get($service)
                {
                    return new class {
                        public function generate($name, array $parameters = [], $referenceType = 1)
                        {
                            return '';
                        }
                    };
                }
            };
        }
    }
}
