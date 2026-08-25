<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Product;

/**
 * Product Popup submission lifecycle (AUD-002A).
 *
 * Phase 7 adds IDENTITY_ACCEPTED as the terminal state before order creation exists.
 * Later order-lifecycle phases mark ORDER_CREATED from PROCESSING instead.
 */
final class PopupSubmissionStates
{
    public const ISSUED = 'issued';
    public const PROCESSING = 'processing';
    public const IDENTITY_ACCEPTED = 'identity_accepted';
    public const ORDER_CREATED = 'order_created';
    public const FAILED = 'failed';
}
