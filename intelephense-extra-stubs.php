<?php

/**
 * Extra IDE stubs for standalone module workspace.
 * Not used by PrestaShop runtime.
 */

namespace {
    if (!defined('_PS_BASE_URL_')) {
        define('_PS_BASE_URL_', 'https://example.com');
    }

    class Smarty
    {
        public function assign(array $tplVars): void {}
    }

    class Currency
    {
        /** @var string */
        public $iso_code = '';
    }

    class Context
    {
        /** @var Smarty */
        public $smarty;

        /** @var Currency */
        public $currency;

        public function __construct()
        {
            $this->smarty = new Smarty();
            $this->currency = new Currency();
        }
    }
}

namespace PrestaShop\PrestaShop\Adapter {
    class SymfonyContainer
    {
        /**
         * @return object
         */
        public static function getInstance()
        {
            return new class {
                /**
                 * @return object
                 */
                public function get($service)
                {
                    return new class {
                        public function generate($name, array $parameters = [], $referenceType = 1): string
                        {
                            return '';
                        }
                    };
                }
            };
        }
    }
}

namespace Symfony\Component\Form {
    interface FormBuilderInterface
    {
        public function add($child, $type = null, array $options = []);
    }
}

namespace Symfony\Component\Form\Extension\Core\Type {
    class TextType {}

    class NumberType {}
}

namespace PrestaShopBundle\Form\Admin\Type {
    /**
     * @see src/PrestaShopBundle/Form/Admin/Type/TranslatorAwareType.php (PrestaShop root)
     */
    abstract class TranslatorAwareType
    {
        /**
         * @param array<string, mixed> $parameters
         *
         * @return string
         */
        protected function trans($key, $domain, $parameters = [])
        {
            return '';
        }

        /**
         * @return \Symfony\Contracts\Translation\TranslatorInterface
         */
        protected function getTranslator()
        {
            throw new \RuntimeException('stub');
        }
    }

    class SwitchType {}
}

namespace {
    abstract class Module
    {
        /** @var Context */
        public $context;

        public function __construct()
        {
            $this->context = new Context();
        }

        public function display($file, $template)
        {
            return '';
        }

        public function isRegisteredInHook($hookName): bool
        {
            return false;
        }
    }
}

namespace Symfony\Component\HttpFoundation {
    class Request
    {
        public function getContent(): string
        {
            return '';
        }
    }

    class Response {}

    class JsonResponse extends Response
    {
        public function __construct($data = null, int $status = 200, array $headers = [], bool $json = false) {}
    }
}

namespace PrestaShopBundle\Controller\Admin {

    use Symfony\Component\HttpFoundation\Response;

    class FrameworkBundleAdminController
    {
        public function get($id)
        {
            if ($id === 'translator') {
                return new class implements \Symfony\Contracts\Translation\TranslatorInterface {
                    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
                    {
                        return '';
                    }
                };
            }

            return new class {
                public function getForm()
                {
                    return new class {
                        public function handleRequest($request): void {}
                        public function isSubmitted(): bool
                        {
                            return false;
                        }
                        public function isValid(): bool
                        {
                            return false;
                        }
                        public function getData(): array
                        {
                            return [];
                        }
                        public function createView()
                        {
                            return null;
                        }
                    };
                }
                public function save(array $data): array
                {
                    return [];
                }
            };
        }

        public function addFlash(string $type, $message): void {}

        public function redirectToRoute(string $route, array $parameters = [], int $status = 302)
        {
            return new Response();
        }

        public function generateUrl(string $route, array $parameters = [], int $referenceType = 1): string
        {
            return '';
        }

        public function flashErrors(array $errors): void {}

        public function render(string $view, array $parameters = [])
        {
            return new Response();
        }

        /**
         * @param array<string, mixed> $parameters
         */
        protected function trans($key, $domain, array $parameters = [])
        {
            return '';
        }
    }
}

namespace PrestaShop\PrestaShop\Core\Form {
    interface FormDataProviderInterface
    {
        public function getData(): array;

        public function setData(array $data): array;
    }
}

namespace PrestaShop\PrestaShop\Core\Configuration {
    interface DataConfigurationInterface
    {
        public function getConfiguration(): array;

        public function updateConfiguration(array $configuration): array;

        public function validateConfiguration(array $configuration): bool;
    }
}

namespace Symfony\Contracts\Translation {
    /**
     * @see vendor/symfony/contracts/Translation/TranslatorInterface.php (PrestaShop root)
     */
    interface TranslatorInterface
    {
        /**
         * @param array<string, mixed> $parameters
         */
        public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string;
    }
}

namespace {
    class Category
    {
        /** @var int */
        public $id = 0;
        /** @var string|array<int, string> */
        public $name = '';

        public function __construct($id = null, $idLang = null)
        {
            $this->id = (int) $id;
        }

        /**
         * @return array<int, array{id_category:int}>
         */
        public function getSubCategories($idLang, $active = true): array
        {
            return [];
        }
    }
}
