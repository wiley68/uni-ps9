<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Configuration;

/** Internal reconciliation boundary; never a public bank-status value. */
final class ControlPanelOriginMismatchException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Control Panel origin provenance is unproven; reconciliation is required.');
    }
}
