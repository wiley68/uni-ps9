<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (is_file($autoloadPath)) {
    require_once $autoloadPath;
}

class Unipayment extends PaymentModule
{
    /** @var int Whether the module exposes a configuration page. */
    public $is_configurable = 1;

    public function __construct()
    {
        $this->name = 'unipayment';
        $this->tab = 'payments_gateways';
        $this->version = '2.0.1';
        $this->author = 'Avalon Ltd';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = [
            'min' => '9.0.0',
            'max' => '9.99.99',
        ];

        parent::__construct();

        $this->displayName = $this->trans(
            'УниКредит покупки на Кредит',
            [],
            'Modules.Unipayment.Admin'
        );
        $this->description = $this->trans(
            'Дава възможност на Вашите клиенти да закупуват стока на изплащане с УниКредит.',
            [],
            'Modules.Unipayment.Admin'
        );
        $this->confirmUninstall = $this->trans(
            'Сигурни ли сте, че искате да деинсталирате модула? Настройките на UniPayment ще бъдат изтрити.',
            [],
            'Modules.Unipayment.Admin'
        );
    }

    public function install(): bool
    {
        if (!parent::install()) {
            return false;
        }

        $repository = new PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository();
        $cache = new PrestaShop\Module\Unipayment\Configuration\ShopConfigurationCache();
        if (!$repository->install() || !$cache->install()) {
            $cache->uninstall();
            $repository->uninstall();
            parent::uninstall();

            return false;
        }

        return true;
    }

    public function uninstall(): bool
    {
        $cache = new PrestaShop\Module\Unipayment\Configuration\ShopConfigurationCache();
        $repository = new PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository();
        if (!$cache->uninstall() || !$repository->uninstall()) {
            return false;
        }

        return parent::uninstall();
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    public function getContent(): string
    {
        $repository = new PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository();
        $output = '';

        if (Tools::isSubmit('submitUnipaymentDownloadJournal')) {
            $output .= $this->displayWarning(
                $this->trans(
                    'Изтеглянето на журнал с операции ще бъде налично след имплементацията на SmartUCF диагностиката.',
                    [],
                    'Modules.Unipayment.Admin'
                )
            );
        }

        if (Tools::isSubmit('submitUnipaymentConfiguration')) {
            $output .= $this->handleConfigurationSubmit($repository);
        }

        if (Tools::isSubmit('submitUnipaymentRefresh')) {
            $output .= $this->handleBankDataRefresh();
        }

        $configurationSubmitted = Tools::isSubmit('submitUnipaymentConfiguration');
        $cacheMetadata = null;
        try {
            $cacheMetadata = $this->createShopConfigurationService()->getMetadata();
        } catch (Throwable $exception) {
            $cacheMetadata = null;
        }

        $this->context->smarty->assign([
            'unipayment_form_action' => $this->context->link->getAdminLink(
                'AdminModules',
                true,
                [],
                ['configure' => $this->name]
            ),
            'unipayment_enabled' => $configurationSubmitted
                ? (bool) Tools::getValue('UNIPAYMENT_ENABLED', false)
                : $repository->isEnabled(),
            'unipayment_unicid' => $configurationSubmitted
                ? trim((string) Tools::getValue('UNIPAYMENT_UNICID', ''))
                : $repository->getUnicid(),
            'unipayment_advertising_enabled' => $configurationSubmitted
                ? (bool) Tools::getValue('UNIPAYMENT_ADVERTISING_ENABLED', false)
                : $repository->isAdvertisingEnabled(),
            'unipayment_debug_enabled' => $configurationSubmitted
                ? (bool) Tools::getValue('UNIPAYMENT_DEBUG_ENABLED', false)
                : $repository->isDebugEnabled(),
            'unipayment_product_button_action' => $configurationSubmitted
                ? (string) Tools::getValue(
                    'UNIPAYMENT_PRODUCT_BUTTON_ACTION',
                    PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository::DEFAULT_PRODUCT_BUTTON_ACTION
                )
                : $repository->getProductButtonAction(),
            'unipayment_button_top_spacing' => $configurationSubmitted
                ? (string) Tools::getValue('UNIPAYMENT_BUTTON_TOP_SPACING', '0')
                : (string) $repository->getButtonTopSpacing(),
            'unipayment_has_secret' => $repository->hasSecret(),
            'unipayment_secret_readable' => $repository->isSecretReadable(),
            'unipayment_bank_refresh_available' => true,
            'unipayment_journal_available' => false,
            'unipayment_cache_present' => is_array($cacheMetadata),
            'unipayment_cache_is_fresh' => is_array($cacheMetadata) ? (bool) ($cacheMetadata['is_fresh'] ?? false) : false,
            'unipayment_cache_fetched_at' => is_array($cacheMetadata) ? (string) ($cacheMetadata['fetched_at'] ?? '') : '',
            'unipayment_cache_expires_at' => is_array($cacheMetadata) ? (string) ($cacheMetadata['expires_at'] ?? '') : '',
        ]);

        return $output . $this->display(__FILE__, 'views/templates/admin/configuration.tpl');
    }

    private function handleConfigurationSubmit(
        PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository $repository
    ): string {
        $validator = new PrestaShop\Module\Unipayment\Configuration\ConfigurationValidator();
        $unicid = trim((string) Tools::getValue('UNIPAYMENT_UNICID', ''));
        $secret = trim((string) Tools::getValue('UNIPAYMENT_SECRET', ''));
        $buttonAction = (string) Tools::getValue('UNIPAYMENT_PRODUCT_BUTTON_ACTION', '');
        $buttonTopSpacing = Tools::getValue('UNIPAYMENT_BUTTON_TOP_SPACING', '');
        $errors = $validator->validate(
            $unicid,
            $secret,
            $repository->hasSecret(),
            $buttonAction,
            $buttonTopSpacing
        );

        if ($errors !== []) {
            return $this->displayError(array_map(function (string $error): string {
                if ($error === PrestaShop\Module\Unipayment\Configuration\ConfigurationValidator::ERROR_UNICID_REQUIRED) {
                    return $this->trans('Полето „Уникален идентификационен код на магазина Ви“ е задължително.', [], 'Modules.Unipayment.Admin');
                }

                if ($error === PrestaShop\Module\Unipayment\Configuration\ConfigurationValidator::ERROR_UNICID_INVALID) {
                    return $this->trans('Идентификационният код трябва да е валиден UUID и не може да надвишава 36 символа.', [], 'Modules.Unipayment.Admin');
                }

                if ($error === PrestaShop\Module\Unipayment\Configuration\ConfigurationValidator::ERROR_SECRET_REQUIRED) {
                    return $this->trans('Полето „Секретен код на магазина Ви“ е задължително.', [], 'Modules.Unipayment.Admin');
                }

                if ($error === PrestaShop\Module\Unipayment\Configuration\ConfigurationValidator::ERROR_BUTTON_ACTION_INVALID) {
                    return $this->trans('Моля, изберете валидно действие за бутона Купи.', [], 'Modules.Unipayment.Admin');
                }

                if ($error === PrestaShop\Module\Unipayment\Configuration\ConfigurationValidator::ERROR_BUTTON_TOP_SPACING_INVALID) {
                    return $this->trans('Свободното място над бутона трябва да е цяло число между 0 и 200 px.', [], 'Modules.Unipayment.Admin');
                }

                return $this->trans('Секретният код не може да надвишава 64 символа.', [], 'Modules.Unipayment.Admin');
            }, $errors));
        }

        $credentialsChanged = $repository->getUnicid() !== $unicid || $secret !== '';
        $saved = $repository->save(
            (bool) Tools::getValue('UNIPAYMENT_ENABLED', false),
            $unicid,
            $secret !== '' ? $secret : null,
            (bool) Tools::getValue('UNIPAYMENT_ADVERTISING_ENABLED', false),
            (bool) Tools::getValue('UNIPAYMENT_DEBUG_ENABLED', false),
            $buttonAction,
            (int) $buttonTopSpacing,
            false
        );

        if (!$saved) {
            return $this->displayError(
                $this->trans('Настройките на модула не могат да бъдат записани.', [], 'Modules.Unipayment.Admin')
            );
        }

        if ($credentialsChanged) {
            (new PrestaShop\Module\Unipayment\Configuration\CredentialChangeSideEffectHandler())
                ->onCredentialsChanged();
        }

        return $this->displayConfirmation(
            $this->trans('Настройките са записани успешно.', [], 'Modules.Unipayment.Admin')
        );
    }

    private function handleBankDataRefresh(): string
    {
        try {
            $this->createShopConfigurationService()->get(true);

            return $this->displayConfirmation(
                $this->trans('Данните от банката са обновени успешно.', [], 'Modules.Unipayment.Admin')
            );
        } catch (PrestaShop\Module\Unipayment\Configuration\Exception\ShopConfigurationSnapshotValidationException $exception) {
            return $this->displayError(
                $this->trans(
                    'Данните от банката са невалидни и не бяха приложени. Предишната конфигурация е запазена.',
                    [],
                    'Modules.Unipayment.Admin'
                )
            );
        } catch (PrestaShop\Module\Unipayment\Api\Exception\TimeoutException $exception) {
            return $this->displayError(
                $this->trans('Връзката с банката изтече. Моля, опитайте отново.', [], 'Modules.Unipayment.Admin')
            );
        } catch (PrestaShop\Module\Unipayment\Api\Exception\ConnectionException $exception) {
            return $this->displayError(
                $this->trans('Неуспешна връзка с банката. Моля, опитайте отново.', [], 'Modules.Unipayment.Admin')
            );
        } catch (PrestaShop\Module\Unipayment\Api\Exception\AuthenticationException $exception) {
            return $this->displayError(
                $this->trans('Удостоверенията към банката бяха отхвърлени.', [], 'Modules.Unipayment.Admin')
            );
        } catch (PrestaShop\Module\Unipayment\Api\Exception\MalformedJsonException $exception) {
            return $this->displayError(
                $this->trans('Банката върна нечетим отговор.', [], 'Modules.Unipayment.Admin')
            );
        } catch (PrestaShop\Module\Unipayment\Api\Exception\InvalidPayloadException $exception) {
            return $this->displayError(
                $this->trans('Банката върна невалиден отговор.', [], 'Modules.Unipayment.Admin')
            );
        } catch (PrestaShop\Module\Unipayment\Api\Exception\HttpException $exception) {
            return $this->displayError(
                $this->trans('Данните не могат да бъдат обновени. Проверете настройките и опитайте отново.', [], 'Modules.Unipayment.Admin')
            );
        }
    }

    /**
     * Outbound Control Panel client (auth + GET /shop). Not used for FO financing yet.
     */
    public function getControlPanelClient(): PrestaShop\Module\Unipayment\Api\ControlPanelClient
    {
        return $this->createControlPanelClient();
    }

    public function getShopConfigurationService(): PrestaShop\Module\Unipayment\Configuration\ShopConfigurationService
    {
        return $this->createShopConfigurationService();
    }

    private function createControlPanelClient(): PrestaShop\Module\Unipayment\Api\ControlPanelClient
    {
        $shopUrl = rtrim(Tools::getShopDomainSsl(true) . __PS_BASE_URI__, '/');

        return new PrestaShop\Module\Unipayment\Api\ControlPanelClient(
            new PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository(),
            new PrestaShop\Module\Unipayment\Security\TokenRepository(),
            new PrestaShop\Module\Unipayment\Api\CurlHttpTransport(),
            $shopUrl
        );
    }

    private function createShopConfigurationService(
        ?PrestaShop\Module\Unipayment\Api\ControlPanelClient $client = null
    ): PrestaShop\Module\Unipayment\Configuration\ShopConfigurationService {
        $repository = new PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository();
        $tokens = new PrestaShop\Module\Unipayment\Security\TokenRepository();

        return new PrestaShop\Module\Unipayment\Configuration\ShopConfigurationService(
            $repository,
            new PrestaShop\Module\Unipayment\Configuration\ShopConfigurationCache(),
            $client ?? $this->createControlPanelClient(),
            $tokens
        );
    }
}
