<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/** Recipient-specific leasing email / admin presentation audience. */
final class EmailAudience
{
    public const CUSTOMER = 'customer';

    public const ADMIN = 'admin';

    private function __construct() {}
}
