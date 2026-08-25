<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Product;

use Customer;

/**
 * Decides whether popup apply may use the Context customer as order identity.
 *
 * Only a real authenticated (non-guest) login is trusted. Cookie leftovers with
 * id_customer alone — including previous guests or poisoned registered cookies —
 * must not select Customer identity.
 */
final class PopupCustomerIdentityGate
{
    public function shouldUseAuthenticatedCustomer(mixed $customer): bool
    {
        if (!$customer instanceof Customer) {
            return false;
        }

        if ((int) $customer->id <= 0) {
            return false;
        }

        // PrestaShop: guests always fail isLogged() when $withGuest is false.
        return $customer->isLogged();
    }
}
