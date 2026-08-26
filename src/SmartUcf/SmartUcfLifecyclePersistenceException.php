<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\SmartUcf;

/**
 * Thrown when a required SmartUCF lifecycle DB transition did not update exactly one row.
 */
final class SmartUcfLifecyclePersistenceException extends \RuntimeException {}
