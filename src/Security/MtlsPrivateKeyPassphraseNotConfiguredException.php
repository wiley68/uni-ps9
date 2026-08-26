<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Security;

/**
 * Thrown when mTLS private-key passphrase is required but not configured at runtime.
 */
final class MtlsPrivateKeyPassphraseNotConfiguredException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'mTLS private-key passphrase is not configured ('
            . MtlsPrivateKeyPassphraseProvider::ENV_VAR
            . ').'
        );
    }
}
