<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/**
 * Runtime context for post-CP lifecycle (shop scope, currency, replay semantics).
 */
final class PostControlPanelLifecycleContext
{
    /** @var int */
    public $idShop;

    /** @var string */
    public $currencyIso;

    /** @var bool Use SmartUcfSessionCoordinator::resume() instead of run(). */
    public $resumeSmartUcf;

    /** @var bool When false, skip leasing email and Process 2 bank-status persistence (popup replay). */
    public $sendLeasingEmail;

    public function __construct(
        int $idShop,
        string $currencyIso,
        bool $resumeSmartUcf = false,
        bool $sendLeasingEmail = true
    ) {
        $this->idShop = $idShop;
        $this->currencyIso = $currencyIso;
        $this->resumeSmartUcf = $resumeSmartUcf;
        $this->sendLeasingEmail = $sendLeasingEmail;
    }
}
