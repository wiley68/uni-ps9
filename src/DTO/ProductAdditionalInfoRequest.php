<?php

/**
 * Copyright since 2024 Ilko Ivanov
 *
 * @author    Ilko Ivanov
 * @copyright Ilko Ivanov
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\DTO;

/**
 * Входни данни за блока под „Купи“ на продуктова страница (без PrestaShop Context).
 *
 * @phpstan-type ParamsUni array<string, mixed>
 */
final class ProductAdditionalInfoRequest
{
    /**
     * @param ParamsUni $paramsuni
     * @param callable(string $cid, string $deviceis): ?array $getCachedUniCalculation
     */
    public function __construct(
        public readonly int $productId,
        public readonly float $uniPrice,
        public readonly string $currencyCode,
        public readonly int $uniStatus,
        public readonly int $uniCart,
        public readonly int $unipaymentGap,
        public readonly string $uniUnicid,
        public readonly string $userAgent,
        public readonly string $moduleVersion,
        public readonly string $csrfToken,
        public readonly string $shopSslBaseLink,
        public readonly string $getProductModuleLink,
        public readonly array $paramsuni,
        /** Етикети за калкулатора (преведени в модула с литерален {@see Module::trans} за екстрактор). */
        public readonly string $shopLabelMonthsMobile,
        public readonly string $shopLabelMonthsDesktop,
        public readonly string $shopLabelInstallmentMobile,
        public readonly string $shopLabelInstallmentDesktop,
        public $getCachedUniCalculation,
    ) {
    }
}
