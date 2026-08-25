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
            'Сигурни ли сте, че искате да деинсталирате модула?',
            [],
            'Modules.Unipayment.Admin'
        );
    }

    public function install(): bool
    {
        return parent::install();
    }

    public function uninstall(): bool
    {
        return parent::uninstall();
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    public function getContent(): string
    {
        return $this->displayConfirmation(
            $this->trans(
                'UniPayment PrestaShop 9 module foundation is installed. Configuration will be available in the next implementation phase.',
                [],
                'Modules.Unipayment.Admin'
            )
        );
    }
}
