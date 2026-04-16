<?php

/**
 * Local stubs for IDE/static analysis in module-only repo.
 * These classes are intentionally lightweight and MUST NOT be loaded in production.
 */

namespace {

    if (!defined('_PS_VERSION_')) {
        define('_PS_VERSION_', '9.0.0');
    }
    if (!defined('_DB_PREFIX_')) {
        define('_DB_PREFIX_', 'ps_');
    }
    if (!defined('_PS_MODULE_DIR_')) {
        define('_PS_MODULE_DIR_', __DIR__ . '/');
    }
    if (!defined('__PS_BASE_URI__')) {
        define('__PS_BASE_URI__', '/');
    }

    if (!function_exists('pSQL')) {
        function pSQL($string, $htmlOK = false)
        {
            return (string) $string;
        }
    }

    if (!class_exists('ObjectModel')) {
        class ObjectModel
        {
            /** @var int */
            public $id = 0;
        }
    }

    if (!class_exists('Module')) {
        class Module
        {
            /** @var Context */
            public $context;
            /** @var int */
            public $id = 0;
            /** @var string */
            public $name = '';
            /** @var string */
            public $tab = '';
            /** @var string */
            public $version = '';
            /** @var string */
            public $author = '';
            /** @var int */
            public $need_instance = 0;
            /** @var array<string,string> */
            public $ps_versions_compliancy = [];
            /** @var bool */
            public $bootstrap = false;
            /** @var bool */
            public $active = true;
            /** @var string */
            public $confirmUninstall = '';
            /** @var string */
            public $displayName = '';
            /** @var string */
            public $description = '';

            public function __construct()
            {
                $this->context = Context::getContext();
            }

            public function install()
            {
                return true;
            }

            public function uninstall()
            {
                return true;
            }

            public function registerHook($hookName)
            {
                return true;
            }

            public function display($file, $template)
            {
                return '';
            }

            public function getLocalPath()
            {
                return '';
            }

            public function fetch($templatePath)
            {
                return '';
            }

            public function trans($id, array $parameters = [], $domain = null, $locale = null)
            {
                return (string) $id;
            }

            public function l($string, $specific = false, $locale = null)
            {
                return (string) $string;
            }

            public static function getInstanceByName($moduleName)
            {
                return null;
            }

            public static function getPaymentModules()
            {
                return [];
            }
        }
    }

    if (!class_exists('PaymentModule')) {
        class PaymentModule extends Module
        {
            /** @var int */
            public $currentOrder = 0;

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
                $shop = null
            ) {
                return true;
            }
        }
    }

    if (!class_exists('FrontController')) {
        class FrontController
        {
            /** @var Context */
            public $context;
            /** @var string */
            public $php_self = '';
            /** @var bool */
            public $ajax = false;

            public function __construct()
            {
                $this->context = Context::getContext();
            }

            public function initContent() {}
        }
    }

    if (!class_exists('ModuleFrontController')) {
        class ModuleFrontController extends FrontController
        {
            /** @var Module */
            public $module;
            /** @var bool */
            public $ajax = false;

            public function ajaxRender($value = null)
            {
                return;
            }
        }
    }

    if (!class_exists('Context')) {
        class Context
        {
            /** @var Cart */
            public $cart;
            /** @var Currency */
            public $currency;
            /** @var Language */
            public $language;
            /** @var Customer */
            public $customer;
            /** @var Shop */
            public $shop;
            /** @var Link */
            public $link;
            /** @var Controller */
            public $controller;
            /** @var mixed */
            public $cookie;
            /** @var mixed */
            public $smarty;

            public static function getContext()
            {
                static $ctx = null;
                if ($ctx === null) {
                    $ctx = new self();
                }

                return $ctx;
            }
        }
    }

    if (!class_exists('Shop')) {
        class Shop extends ObjectModel
        {
            const CONTEXT_ALL = 1;

            public static function isFeatureActive()
            {
                return false;
            }

            public static function setContext($context, $idShop = null) {}
        }
    }

    if (!class_exists('Configuration')) {
        class Configuration
        {
            public static function hasKey($key, $idLang = null, $idShopGroup = null, $idShop = null): bool
            {
                return false;
            }

            public static function get($key, $idLang = null, $idShopGroup = null, $idShop = null)
            {
                return null;
            }

            /**
             * @return bool
             */
            public static function updateValue($key, $values, $html = false, $idShopGroup = null, $idShop = null): bool
            {
                return true;
            }

            public static function deleteByName($key): bool
            {
                return true;
            }
        }
    }

    if (!class_exists('Tools')) {
        class Tools
        {
            public static function clearSf2Cache()
            {
                return true;
            }

            public static function redirectAdmin($url)
            {
                return;
            }

            public static function redirect($url, $baseUri = '/', $httpResponseCode = null)
            {
                return;
            }

            public static function getValue($key, $default_value = false)
            {
                return $default_value;
            }

            public static function getIsset($key): bool
            {
                return false;
            }

            public static function usingSecureMode()
            {
                return true;
            }

            public static function getToken($page = true)
            {
                return '';
            }
        }
    }

    if (!class_exists('Validate')) {
        class Validate
        {
            public static function isLoadedObject($object)
            {
                return is_object($object) && isset($object->id) && (int) $object->id > 0;
            }
        }
    }

    if (!class_exists('Db')) {
        class Db
        {
            public static function getInstance($master = true)
            {
                return new self();
            }

            public function execute($sql, $use_cache = true)
            {
                return true;
            }

            public function executeS($sql, $array = true, $use_cache = true)
            {
                return [];
            }

            public function getValue($sql, $use_cache = true)
            {
                return null;
            }

            public function insert($table, $data, $null_values = false, $use_cache = true, $type = null, $add_prefix = true)
            {
                return true;
            }

            public function update($table, $data, $where = '', $limit = 0, $null_values = false, $use_cache = true, $add_prefix = true)
            {
                return true;
            }
        }
    }

    if (!class_exists('Cart')) {
        class Cart extends ObjectModel
        {
            const ONLY_PRODUCTS = 1;
            /** @var int */
            public $id_currency = 0;
            /** @var int */
            public $id_customer = 0;
            /** @var int */
            public $id_address_delivery = 0;
            /** @var int */
            public $id_address_invoice = 0;

            public function __construct($id = null)
            {
                $this->id = (int) ($id ?? 0);
            }

            public function nbProducts()
            {
                return 0;
            }

            public function getOrderTotal($withTaxes = true, $type = null)
            {
                return 0.0;
            }

            public function getProducts($refresh = false)
            {
                return [];
            }

            public function updateQty(
                $quantity,
                $id_product,
                $id_product_attribute = null,
                $id_customization = false,
                $operator = 'up',
                $id_address_delivery = 0,
                $shop = null,
                $auto_add_cart_rule = true,
                $skipAvailabilityCheckOutOfStock = false
            ) {
                return true;
            }
        }
    }

    if (!class_exists('Currency')) {
        class Currency extends ObjectModel
        {
            /** @var string */
            public $iso_code = 'EUR';

            public function __construct($id = null)
            {
                $this->id = (int) ($id ?? 0);
            }
        }
    }

    if (!class_exists('Customer')) {
        class Customer extends ObjectModel
        {
            /** @var int */
            public $id_lang = 0;
            /** @var string */
            public $secure_key = '';
            /** @var string */
            public $firstname = '';
            /** @var string */
            public $lastname = '';
            /** @var string */
            public $email = '';

            public function __construct($id = null)
            {
                $this->id = (int) ($id ?? 0);
            }

            public function getAddresses($id_lang = null)
            {
                return [];
            }
        }
    }

    if (!class_exists('Language')) {
        class Language extends ObjectModel
        {
            public static function getLanguages($active = true, $id_shop = false, $id_shop_group = false, $ids_only = false)
            {
                return [];
            }
        }
    }

    if (!class_exists('Product')) {
        class Product extends ObjectModel
        {
            /** @var int */
            public $id_category_default = 0;

            public function __construct($id = null, $full = false, $id_lang = null, $id_shop = null, $context = null)
            {
                $this->id = (int) ($id ?? 0);
            }

            public static function getPriceStatic(
                $id_product,
                $usetax = true,
                $id_product_attribute = null,
                $decimals = 6,
                $divisor = null,
                $only_reduc = false,
                $usereduc = true,
                $quantity = 1,
                $force_associated_tax = false,
                $id_customer = null,
                $id_cart = null,
                $id_address = null,
                &$specific_price_output = null,
                $with_ecotax = true,
                $use_group_reduction = true,
                $id_shop = null
            ) {
                return 0.0;
            }

            public static function getProductCategories($id_product)
            {
                return [];
            }
        }
    }

    if (!class_exists('Category')) {
        class Category extends ObjectModel
        {
            /** @var string|array<int|string, string> */
            public $name = '';

            public function __construct($id = null, $id_lang = null, $id_shop = null)
            {
                $this->id = (int) ($id ?? 0);
            }

            public function getSubCategories($id_lang, $active = true)
            {
                return [];
            }
        }
    }

    if (!class_exists('Order')) {
        class Order extends ObjectModel
        {
            public function getCurrentState()
            {
                return 0;
            }
        }
    }

    if (!class_exists('OrderState')) {
        class OrderState extends ObjectModel
        {
            /** @var array<string,string> */
            public $name = [];
            /** @var string */
            public $color = '';
            /** @var bool */
            public $send_mail = false;
            /** @var string */
            public $template = '';
            /** @var bool */
            public $invoice = false;
            /** @var bool */
            public $unremovable = false;
            /** @var bool */
            public $logable = false;
            /** @var string */
            public $module_name = '';

            public function __construct($id = null, $id_lang = null)
            {
                $this->id = (int) ($id ?? 0);
            }

            public function add($auto_date = true, $null_values = false)
            {
                return true;
            }
        }
    }

    if (!class_exists('Link')) {
        class Link
        {
            public function getPageLink($controller, $ssl = null, $id_lang = null, $request = null, $id_shop = null, $relative_protocol = false)
            {
                return '';
            }

            public function getModuleLink($module, $controller = 'default', array $params = [], $ssl = null, $id_lang = null, $id_shop = null, $relative_protocol = false)
            {
                return '';
            }

            public function getBaseLink($id_shop = null, $ssl = null, $relative_protocol = false)
            {
                return '';
            }
        }
    }

    if (!class_exists('Media')) {
        class Media
        {
            public static function getMediaPath($path)
            {
                return (string) $path;
            }
        }
    }

    if (!class_exists('Hook')) {
        class Hook
        {
            public static function getIdByName($name)
            {
                return 0;
            }
        }
    }

    if (!class_exists('Mail')) {
        class Mail
        {
            public static function send(
                $id_lang,
                $template,
                $subject,
                $template_vars,
                $to,
                $to_name = null,
                $from = null,
                $from_name = null,
                $file_attachment = null,
                $mode_smtp = null,
                $template_path = _PS_MODULE_DIR_,
                $die = false,
                $id_shop = null,
                $bcc = null,
                $reply_to = null
            ) {
                return true;
            }
        }
    }
    if (!class_exists('Controller')) {
        class Controller
        {
            /** @var string */
            public $php_self = '';

            public function registerStylesheet($id, $path, array $options = [])
            {
                return true;
            }

            public function registerJavascript($id, $path, array $options = [])
            {
                return true;
            }
        }
    }
}

namespace PrestaShop\PrestaShop\Core\Payment {
    if (!class_exists('PrestaShop\\PrestaShop\\Core\\Payment\\PaymentOption')) {
        class PaymentOption
        {
            public function setModuleName($name)
            {
                return $this;
            }

            public function setCallToActionText($text)
            {
                return $this;
            }

            public function setAction($url)
            {
                return $this;
            }

            public function setLogo($logo)
            {
                return $this;
            }

            public function setAdditionalInformation($information)
            {
                return $this;
            }
        }
    }
}

namespace Symfony\Component\HttpFoundation {
    if (!class_exists('Symfony\\Component\\HttpFoundation\\Request')) {
        class Request
        {
            public $request;
            public $query;
            public $attributes;

            public function getContent()
            {
                return '';
            }
        }
    }

    if (!class_exists('Symfony\\Component\\HttpFoundation\\Response')) {
        class Response
        {
            /** @var int */
            protected $statusCode = 200;
        }
    }

    if (!class_exists('Symfony\\Component\\HttpFoundation\\JsonResponse')) {
        class JsonResponse extends Response
        {
            public function __construct($data = null, $status = 200, array $headers = [], $json = false)
            {
                $this->statusCode = (int) $status;
            }
        }
    }
}

namespace Symfony\Component\Form {
    if (!interface_exists('Symfony\\Component\\Form\\FormBuilderInterface')) {
        interface FormBuilderInterface
        {
            public function add($child, $type = null, array $options = []);
        }
    }
}

namespace Symfony\Component\Form\Extension\Core\Type {
    if (!class_exists('Symfony\\Component\\Form\\Extension\\Core\\Type\\TextType')) {
        class TextType {}
    }

    if (!class_exists('Symfony\\Component\\Form\\Extension\\Core\\Type\\NumberType')) {
        class NumberType {}
    }
}
