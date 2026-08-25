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
        $apiNonce = new PrestaShop\Module\Unipayment\Security\ApiNonceRepository();
        $bankStatus = new PrestaShop\Module\Unipayment\Order\OrderBankStatusRepository();
        $debugLog = new PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDebugLogRepository();

        if (
            !$repository->install()
            || !$cache->install()
            || !$apiNonce->install()
            || !$bankStatus->install()
            || !$debugLog->install()
        ) {
            $debugLog->uninstall();
            $bankStatus->uninstall();
            $apiNonce->uninstall();
            $cache->uninstall();
            $repository->uninstall();
            parent::uninstall();

            return false;
        }

        return true;
    }

    public function uninstall(): bool
    {
        $debugLog = new PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDebugLogRepository();
        $bankStatus = new PrestaShop\Module\Unipayment\Order\OrderBankStatusRepository();
        $apiNonce = new PrestaShop\Module\Unipayment\Security\ApiNonceRepository();
        $cache = new PrestaShop\Module\Unipayment\Configuration\ShopConfigurationCache();
        $repository = new PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository();

        if (
            !$debugLog->uninstall()
            || !$bankStatus->uninstall()
            || !$apiNonce->uninstall()
            || !$cache->uninstall()
            || !$repository->uninstall()
        ) {
            return false;
        }

        return parent::uninstall();
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    /**
     * Display-only currency suffix for UI amounts (Woo: евро / лв. / лева).
     * Catalog registration only in Phase 5 — no FO consumers yet.
     */
    public function getDisplayCurrencyLabel(string $iso): string
    {
        $iso = strtoupper(trim($iso));
        if ($iso === 'EUR') {
            return $this->trans('евро', [], 'Modules.Unipayment.Shop');
        }
        if ($iso === 'BGN') {
            return $this->trans('лв.', [], 'Modules.Unipayment.Shop');
        }

        return $iso;
    }

    /** Dual-button BGN suffix used by installment labels (Woo: лева). */
    public function getDisplayCurrencyLabelDualBgn(): string
    {
        return $this->trans('лева', [], 'Modules.Unipayment.Shop');
    }

    public function getContent(): string
    {
        $repository = new PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository();
        $requestReader = new PrestaShop\Module\Unipayment\Configuration\AdminConfigurationRequestReader();
        $output = '';
        $configurationSubmitted = $requestReader->isConfigurationSubmit();
        $refreshSubmitted = $requestReader->isBankRefreshSubmit();

        if (array_key_exists('submitUnipaymentDownloadJournal', $_POST)) {
            $output .= $this->displayWarning(
                $this->trans(
                    'Изтеглянето на журнал с операции ще бъде налично след имплементацията на SmartUCF диагностиката.',
                    [],
                    'Modules.Unipayment.Admin'
                )
            );
        }

        if ($configurationSubmitted) {
            $output .= $this->handleConfigurationSubmit($repository, $requestReader);
        }

        // Never treat a Save request as Refresh. Refresh is POST-only via request reader.
        if ($refreshSubmitted && !$configurationSubmitted) {
            $output .= $this->handleBankDataRefresh();
        }

        $cache = new PrestaShop\Module\Unipayment\Configuration\ShopConfigurationCache();
        $cacheRowsAfterSubmit = $cache->countRows();
        $cacheMetadata = null;
        $metadataTouchedCache = false;
        $rowsBeforeMetadata = $cacheRowsAfterSubmit;
        try {
            $cacheMetadata = $this->createShopConfigurationService()->getMetadata();
            $cacheRowsAfterMetadata = $cache->countRows();
            $metadataTouchedCache = $cacheRowsAfterMetadata !== $rowsBeforeMetadata;
        } catch (Throwable $exception) {
            $cacheMetadata = null;
            $cacheRowsAfterMetadata = $cache->countRows();
        }

        if (
            PrestaShop\Module\Unipayment\Configuration\BoConfigurationDiag::enabled()
            && ($configurationSubmitted || $refreshSubmitted)
        ) {
            PrestaShop\Module\Unipayment\Configuration\BoConfigurationDiag::write([
                'phase' => 'getContent_after_handlers',
                'configuration_submit' => $configurationSubmitted,
                'refresh_submit' => $refreshSubmitted,
                'both_submit_flags' => $configurationSubmitted && $refreshSubmitted,
                'cache_rows_after_submit' => $cacheRowsAfterSubmit,
                'cache_rows_after_metadata' => $cacheRowsAfterMetadata ?? $cache->countRows(),
                'metadata_touched_cache' => $metadataTouchedCache,
                'token_present' => (new PrestaShop\Module\Unipayment\Security\TokenRepository())->hasToken(),
            ]);
        }

        $this->context->smarty->assign([
            'unipayment_form_action' => $this->context->link->getAdminLink(
                'AdminModules',
                true,
                [],
                ['configure' => $this->name]
            ),
            'unipayment_enabled' => $configurationSubmitted
                ? (bool) $requestReader->getField('UNIPAYMENT_ENABLED', false)
                : $repository->isEnabled(),
            'unipayment_unicid' => $configurationSubmitted
                ? $requestReader->getUnicid()
                : $repository->getUnicid(),
            'unipayment_advertising_enabled' => $configurationSubmitted
                ? (bool) $requestReader->getField('UNIPAYMENT_ADVERTISING_ENABLED', false)
                : $repository->isAdvertisingEnabled(),
            'unipayment_debug_enabled' => $configurationSubmitted
                ? (bool) $requestReader->getField('UNIPAYMENT_DEBUG_ENABLED', false)
                : $repository->isDebugEnabled(),
            'unipayment_product_button_action' => $configurationSubmitted
                ? (string) $requestReader->getField(
                    'UNIPAYMENT_PRODUCT_BUTTON_ACTION',
                    PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository::DEFAULT_PRODUCT_BUTTON_ACTION
                )
                : $repository->getProductButtonAction(),
            'unipayment_button_top_spacing' => $configurationSubmitted
                ? (string) $requestReader->getField('UNIPAYMENT_BUTTON_TOP_SPACING', '0')
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
        PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository $repository,
        ?PrestaShop\Module\Unipayment\Configuration\AdminConfigurationRequestReader $requestReader = null
    ): string {
        $requestReader = $requestReader
            ?? new PrestaShop\Module\Unipayment\Configuration\AdminConfigurationRequestReader();
        $validator = new PrestaShop\Module\Unipayment\Configuration\ConfigurationValidator();
        $tokens = new PrestaShop\Module\Unipayment\Security\TokenRepository();
        $cache = new PrestaShop\Module\Unipayment\Configuration\ShopConfigurationCache();

        $unicid = $requestReader->getUnicid();
        $secret = $requestReader->getSecret();
        $buttonAction = (string) $requestReader->getField('UNIPAYMENT_PRODUCT_BUTTON_ACTION', '');
        $buttonTopSpacing = $requestReader->getField('UNIPAYMENT_BUTTON_TOP_SPACING', '');
        $oldSecretFingerprint = PrestaShop\Module\Unipayment\Configuration\BoConfigurationDiag::fingerprint(
            $repository->getSecret()
        );
        $tokenPresentBefore = $tokens->hasToken();
        $cacheRowsBefore = $cache->countRows();

        $errors = $validator->validate(
            $unicid,
            $secret,
            $repository->hasSecret(),
            $buttonAction,
            $buttonTopSpacing
        );

        if ($errors !== []) {
            if (PrestaShop\Module\Unipayment\Configuration\BoConfigurationDiag::enabled()) {
                PrestaShop\Module\Unipayment\Configuration\BoConfigurationDiag::write([
                    'phase' => 'configuration_submit_validation_failed',
                    'configuration_submit' => true,
                    'secret_field_present' => $requestReader->hasSecretField(),
                    'secret_in_POST' => $requestReader->secretInPost(),
                    'secret_in_REQUEST' => $requestReader->secretInRequestSuperglobal(),
                    'secret_in_symfony_request' => $requestReader->secretInSymfonyRequest(),
                    'secret_length' => strlen($secret),
                    'tools_getValue_secret_length' => $requestReader->secretViaToolsGetValueLength(),
                    'validation_error_count' => count($errors),
                ]);
            }

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

        $unicidChanged = $repository->getUnicid() !== $unicid;
        $secretNonEmpty = $secret !== '';
        $credentialsChanged = $unicidChanged || $secretNonEmpty;
        $saved = $repository->save(
            (bool) $requestReader->getField('UNIPAYMENT_ENABLED', false),
            $unicid,
            $secretNonEmpty ? $secret : null,
            (bool) $requestReader->getField('UNIPAYMENT_ADVERTISING_ENABLED', false),
            (bool) $requestReader->getField('UNIPAYMENT_DEBUG_ENABLED', false),
            $buttonAction,
            (int) $buttonTopSpacing,
            false
        );

        $newSecretFingerprint = PrestaShop\Module\Unipayment\Configuration\BoConfigurationDiag::fingerprint(
            $repository->getSecret()
        );
        $secretChangedInStorage = $oldSecretFingerprint !== $newSecretFingerprint;

        if (!$saved) {
            return $this->displayError(
                $this->trans('Настройките на модула не могат да бъдат записани.', [], 'Modules.Unipayment.Admin')
            );
        }

        $handlerCalled = false;
        $sideEffectsApplied = true;
        $tokenInvalidateResult = null;
        $cacheClearResult = null;
        $cacheRowsAfter = $cacheRowsBefore;
        $tokenPresentAfter = $tokenPresentBefore;

        if ($credentialsChanged) {
            $handlerCalled = true;
            $sideEffectsApplied = (new PrestaShop\Module\Unipayment\Configuration\CredentialChangeSideEffectHandler(
                $tokens,
                $cache
            ))->onCredentialsChanged();
            $tokenPresentAfter = $tokens->hasToken();
            $cacheRowsAfter = $cache->countRows();
            $tokenInvalidateResult = !$tokenPresentAfter;
            $cacheClearResult = $cacheRowsAfter === 0;
            // Prefer explicit boolean from handler; row/token checks catch false positives.
            if ($sideEffectsApplied && ($tokenPresentAfter || $cacheRowsAfter !== 0)) {
                $sideEffectsApplied = false;
            }
        }

        if (PrestaShop\Module\Unipayment\Configuration\BoConfigurationDiag::enabled()) {
            PrestaShop\Module\Unipayment\Configuration\BoConfigurationDiag::write([
                'phase' => 'configuration_submit',
                'configuration_submit' => true,
                'secret_field_present' => $requestReader->hasSecretField(),
                'unicid_field_present' => $requestReader->hasUnicidField(),
                'secret_in_POST' => $requestReader->secretInPost(),
                'secret_in_REQUEST' => $requestReader->secretInRequestSuperglobal(),
                'secret_in_symfony_request' => $requestReader->secretInSymfonyRequest(),
                'secret_length' => strlen($secret),
                'tools_getValue_secret_length' => $requestReader->secretViaToolsGetValueLength(),
                'secret_non_empty' => $secretNonEmpty,
                'unicid_changed' => $unicidChanged,
                'credentials_changed' => $credentialsChanged,
                'old_secret_fingerprint' => $oldSecretFingerprint,
                'new_secret_fingerprint' => $newSecretFingerprint,
                'secret_changed_in_storage' => $secretChangedInStorage,
                'fingerprint_method' => 'sha256(decrypted_secret)[:12]',
                'handler_called' => $handlerCalled,
                'token_present_before_save' => $tokenPresentBefore,
                'token_invalidate_result' => $tokenInvalidateResult,
                'token_present_after_save' => $tokenPresentAfter,
                'cache_clear_called' => $handlerCalled,
                'cache_clear_result' => $cacheClearResult,
                'cache_rows_before' => $cacheRowsBefore,
                'cache_rows_after' => $cacheRowsAfter,
                'side_effects_applied' => $sideEffectsApplied,
            ]);
        }

        if ($credentialsChanged && !$sideEffectsApplied) {
            return $this->displayError(
                $this->trans(
                    'Настройките са записани, но локалният кеш/токен не могат да бъдат инвалидирани. Моля, опитайте отново или изчистете кеша ръчно.',
                    [],
                    'Modules.Unipayment.Admin'
                )
            );
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
