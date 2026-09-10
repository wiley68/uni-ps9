<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/**
 * Raised when multiple financing orders match the same shop-scoped reference.
 */
final class OrderBankStatusAmbiguousException extends \RuntimeException
{
}
