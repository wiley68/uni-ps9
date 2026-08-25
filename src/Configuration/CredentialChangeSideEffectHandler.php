<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Configuration;

use PrestaShop\Module\Unipayment\Security\TokenRepository;

/**
 * Boundary for side effects after UNICID/secret change.
 *
 * Phase 2: invalidate Control Panel auth tokens.
 * Phase 3 will also clear the local shop configuration cache here.
 */
final class CredentialChangeSideEffectHandler
{
    /** @var TokenRepository */
    private $tokens;

    public function __construct(?TokenRepository $tokens = null)
    {
        $this->tokens = $tokens ?? new TokenRepository();
    }

    public function onCredentialsChanged(): void
    {
        $this->tokens->invalidate();
        // Phase 3: clear local shop configuration cache.
    }
}
