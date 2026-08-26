<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\SmartUcf;

/**
 * Persisted SmartUCF create-session lifecycle (AUD-002B / AUD-008).
 */
final class SmartUcfLifecycleStates
{
    public const NOT_STARTED = 'not_started';
    public const SUBMITTING = 'submitting';
    public const CREATED = 'created';
    public const OUTCOME_UNKNOWN = 'outcome_unknown';
    public const FAILED = 'failed';
}
