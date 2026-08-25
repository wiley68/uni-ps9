<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Configuration;

use PrestaShop\Module\Unipayment\Security\TokenRepository;

/**
 * Boundary for side effects after UNICID/secret change.
 *
 * Phase 2: invalidate Control Panel auth tokens.
 * Phase 3: clear local shop configuration cache.
 */
final class CredentialChangeSideEffectHandler
{
    /** @var TokenRepository */
    private $tokens;

    /** @var ShopConfigurationCacheInterface */
    private $cache;

    public function __construct(
        ?TokenRepository $tokens = null,
        ?ShopConfigurationCacheInterface $cache = null
    ) {
        $this->tokens = $tokens ?? new TokenRepository();
        $this->cache = $cache ?? new ShopConfigurationCache();
    }

    /**
     * @return bool true only when both token invalidation and cache clear succeed
     */
    public function onCredentialsChanged(): bool
    {
        $tokensInvalidated = $this->tokens->invalidate();
        $cacheCleared = $this->cache->clear();

        return $tokensInvalidated && $cacheCleared;
    }
}
