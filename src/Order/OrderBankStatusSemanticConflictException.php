<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/**
 * Inbound bank-status callback rejected an incompatible terminal progression.
 */
final class OrderBankStatusSemanticConflictException extends \RuntimeException
{
}
