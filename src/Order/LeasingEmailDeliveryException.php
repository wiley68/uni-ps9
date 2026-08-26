<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/**
 * Thrown when one or more required leasing audience sends failed.
 *
 * Bank lifecycle is unaffected; callers should report withEmailSent(false).
 */
final class LeasingEmailDeliveryException extends \RuntimeException {}
