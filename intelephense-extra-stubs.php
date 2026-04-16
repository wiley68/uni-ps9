<?php

/**
 * Additional stubs for symbols normally coming from full PrestaShop core/app context.
 * Keep minimal and analysis-only.
 */

namespace PrestaShop\PrestaShop\Adapter {
    if (!class_exists('PrestaShop\\PrestaShop\\Adapter\\SymfonyRouterStub')) {
        class SymfonyRouterStub
        {
            public function generate($name, array $parameters = [], $referenceType = null): string
            {
                return '';
            }
        }
    }

    if (!class_exists('PrestaShop\\PrestaShop\\Adapter\\SymfonyContainer')) {
        class SymfonyContainer
        {
            public static function getInstance(): self
            {
                return new self();
            }

            public function has($id): bool
            {
                return false;
            }

            /**
             * @return \PrestaShop\PrestaShop\Adapter\SymfonyRouterStub|\PrestaShop\PrestaShop\Adapter\KopMappingServiceStub
             */
            public function get($id): \PrestaShop\PrestaShop\Adapter\SymfonyRouterStub|\PrestaShop\PrestaShop\Adapter\KopMappingServiceStub
            {
                if ((string) $id === 'prestashop.module.unipayment.service.kop_mapping') {
                    return new KopMappingServiceStub();
                }
                return new SymfonyRouterStub();
            }
        }
    }

    if (!class_exists('PrestaShop\\PrestaShop\\Adapter\\KopMappingServiceStub')) {
        class KopMappingServiceStub
        {
            public function refreshMappings()
            {
                return true;
            }
        }
    }
}

namespace PrestaShop\PrestaShop\Core\Form {
    if (!class_exists('PrestaShop\\PrestaShop\\Core\\Form\\FormStub')) {
        class FormStub
        {
            public function handleRequest($request)
            {
                return $this;
            }

            public function isSubmitted(): bool
            {
                return false;
            }

            public function isValid(): bool
            {
                return true;
            }

            public function getData(): array
            {
                return [];
            }

            public function createView(): object
            {
                return new \stdClass();
            }
        }
    }

    if (!class_exists('PrestaShop\\PrestaShop\\Core\\Form\\Handler')) {
        class Handler
        {
            public function getForm(): FormStub
            {
                return new FormStub();
            }

            public function save(array $data = [])
            {
                return [];
            }
        }
    }
}

namespace PrestaShop\PrestaShop\Core\Configuration {
    if (!interface_exists('PrestaShop\\PrestaShop\\Core\\Configuration\\DataConfigurationInterface')) {
        interface DataConfigurationInterface
        {
            public function getConfiguration();

            public function updateConfiguration(array $configuration);

            public function validateConfiguration(array $configuration);
        }
    }
}

namespace PrestaShop\PrestaShop\Core\Form {
    if (!interface_exists('PrestaShop\\PrestaShop\\Core\\Form\\FormDataProviderInterface')) {
        interface FormDataProviderInterface
        {
            public function getData();

            public function setData(array $data);
        }
    }
}

namespace PrestaShopBundle\Controller\Admin {
    if (!class_exists('PrestaShopBundle\\Controller\\Admin\\PrestaShopAdminController')) {
        class PrestaShopAdminController
        {
            public function trans($id, array $parameters = [], $domain = null, $locale = null)
            {
                return (string) $id;
            }

            public function addFlash($type, $message) {}

            /**
             * @return \Symfony\Component\HttpFoundation\Response
             */
            public function render($view, array $parameters = [], ?\Symfony\Component\HttpFoundation\Response $response = null)
            {
                return $response ?? new \Symfony\Component\HttpFoundation\Response();
            }

            /**
             * @return \Symfony\Component\HttpFoundation\Response
             */
            public function redirectToRoute($route, array $parameters = [], $status = 302)
            {
                return new \Symfony\Component\HttpFoundation\Response();
            }

            public function generateUrl($route, array $parameters = [], $referenceType = null)
            {
                return '';
            }
        }
    }
}

namespace PrestaShopBundle\Form\Admin\Type {
    if (!class_exists('PrestaShopBundle\\Form\\Admin\\Type\\TranslatorAwareType')) {
        abstract class TranslatorAwareType
        {
            protected function trans($id, $domain = null, array $parameters = [])
            {
                return (string) $id;
            }
        }
    }

    if (!class_exists('PrestaShopBundle\\Form\\Admin\\Type\\SwitchType')) {
        class SwitchType {}
    }
}

namespace Symfony\Component\HttpFoundation {
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

    class Response
    {
        /** @var int */
        protected $statusCode = 200;
    }

    class JsonResponse extends Response
    {
        public function __construct($data = null, $status = 200, array $headers = [], $json = false)
        {
            $this->statusCode = (int) $status;
        }
    }
}

namespace Symfony\Contracts\Translation {
    if (!interface_exists('Symfony\\Contracts\\Translation\\TranslatorInterface')) {
        interface TranslatorInterface
        {
            public function trans($id, array $parameters = [], $domain = null, $locale = null);
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

namespace {
    if (!class_exists('PaymentOptionsFinder')) {
        class PaymentOptionsFinder
        {
            public function present($asArray = true)
            {
                return [];
            }

            public function findUnipaymentOptionId($paymentOptions)
            {
                return null;
            }
        }
    }
}
