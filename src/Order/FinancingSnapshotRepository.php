<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/**
 * Phase 4 authorization table name only.
 *
 * Full financing snapshot persistence is a later phase. Order-bank-status
 * still joins this table (AUD-011) so only UniPayment financing orders resolve.
 * The table is not created by Phase 4 install.
 */
final class FinancingSnapshotRepository
{
    public const TABLE = 'unipayment_financing_snapshot';
}
