<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\SmartUcf\Certificate;

/**
 * Thrown when certificate synchronization cannot complete safely before SmartUCF.
 */
final class CertificateSyncException extends \RuntimeException
{
    /** Explicit CP "no certificate available" (HTTP 404). */
    public const REASON_CP_UNAVAILABLE = 'cp_unavailable';

    /** Transient CP/transport error and local pair unusable. */
    public const REASON_CP_TRANSPORT = 'cp_transport';

    /** Local/CP mismatch and bundle could not be installed. */
    public const REASON_REFRESH_FAILED = 'refresh_failed';

    /** Lock / filesystem / permission failure. */
    public const REASON_LOCAL_FS = 'local_fs';

    /** Invalid bundle or validation failure. */
    public const REASON_INVALID_BUNDLE = 'invalid_bundle';

    /** @var string */
    private $reason;

    public function __construct(string $message, string $reason = self::REASON_REFRESH_FAILED)
    {
        parent::__construct($message);
        $this->reason = $reason;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
