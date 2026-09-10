<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Api;

/**
 * Stable machine-readable error codes for CP↔module JSON envelopes.
 */
final class ModuleApiError
{
    public const INVALID_PAYLOAD = 'invalid_payload';

    public const PAYLOAD_TOO_LARGE = 'payload_too_large';

    public const MALFORMED_JSON = 'malformed_json';

    public const METHOD_NOT_ALLOWED = 'method_not_allowed';

    public const INVALID_SIGNATURE = 'invalid_signature';

    public const UNKNOWN_STORE = 'unknown_store';

    public const MODULE_DISABLED = 'module_disabled';

    public const UNSUPPORTED_OPERATION = 'unsupported_operation';

    public const SHOP_SNAPSHOT_INVALID = 'shop_snapshot_invalid';

    public const ORDER_NOT_FOUND = 'order_not_found';

    public const ORDER_AMBIGUOUS = 'order_ambiguous';

    public const WRONG_PAYMENT_METHOD = 'wrong_payment_method';

    public const UNSUPPORTED_STATUS = 'unsupported_status';

    public const INTERNAL_ERROR = 'internal_error';

    public const AUTHENTICATION_FAILED = 'authentication_failed';

    public const SEMANTIC_CONFLICT = 'semantic_conflict';
}
