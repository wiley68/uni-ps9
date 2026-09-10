<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/**
 * Durable outbound CP PATCH /orders/status synchronization states.
 *
 * Local bank_sent_* remains "business handoff proven"; this tracks CP confirmation separately.
 */
final class ControlPanelStatusSyncStates
{
    public const NOT_NEEDED = 'not_needed';

    public const PENDING = 'pending';

    public const CONFIRMED = 'confirmed';

    public const TERMINAL_FAILED = 'terminal_failed';
}
