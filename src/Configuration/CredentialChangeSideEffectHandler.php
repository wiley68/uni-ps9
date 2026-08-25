<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Configuration;

/**
 * Boundary for side effects after UNICID/secret change.
 *
 * Phase 1 keeps detection of credential changes but does not invalidate tokens
 * or clear shop configuration cache until those components exist:
 *
 * - Phase 2: TokenRepository::invalidate()
 * - Phase 3: ShopConfigurationCache::clear()
 */
final class CredentialChangeSideEffectHandler
{
    public function onCredentialsChanged(): void
    {
        // Intentionally no-op in Phase 1.
        // Token and shop-cache invalidation activate with Phase 2 / Phase 3.
    }
}
