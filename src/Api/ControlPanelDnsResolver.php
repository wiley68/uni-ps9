<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Api;

use PrestaShop\Module\Unipayment\Api\Exception\ConnectionException;

final class ControlPanelDnsResolver
{
    /** @var callable(string): array|false */
    private $lookup;

    public function __construct(?callable $lookup = null)
    {
        $this->lookup = $lookup ?? static fn (string $host): array|false => dns_get_record($host, DNS_A | DNS_AAAA | DNS_CNAME);
    }

    /** @return list<string> */
    public function resolve(string $hostname): array
    {
        $seen = [];
        $budget = 16;
        $addresses = $this->resolveHost($hostname, $seen, 0, $budget);
        if ($addresses === []) {
            throw new ConnectionException('Control Panel DNS returned no public address.');
        }

        return array_values(array_unique($addresses));
    }

    /** @param array<string, bool> $seen @return list<string> */
    private function resolveHost(string $host, array &$seen, int $depth, int &$budget): array
    {
        ControlPanelDestinationPolicy::assertPublicHostname($host);
        if ($depth > 8 || --$budget < 0 || isset($seen[$host])) {
            throw new ConnectionException('Control Panel DNS alias chain is unsafe.');
        }
        $seen[$host] = true;
        $records = call_user_func($this->lookup, $host);
        if (!is_array($records) || count($records) > 64) {
            throw new ConnectionException('Control Panel DNS resolution failed.');
        }
        $addresses = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                throw new ConnectionException('Control Panel DNS response is invalid.');
            }
            $type = $record['type'] ?? '';
            if ($type === 'A' || $type === 'AAAA') {
                $address = $record[$type === 'A' ? 'ip' : 'ipv6'] ?? null;
                if (!is_string($address) || !ControlPanelDestinationPolicy::isPublicAddress($address)) {
                    throw new ConnectionException('Control Panel DNS contains a non-public address.');
                }
                $addresses[] = $address;
            } elseif ($type === 'CNAME') {
                $target = $record['target'] ?? null;
                if (!is_string($target)) {
                    throw new ConnectionException('Control Panel DNS alias is invalid.');
                }
                $target = strtolower(rtrim($target, '.'));
                $addresses = array_merge($addresses, $this->resolveHost($target, $seen, $depth + 1, $budget));
            }
        }
        unset($seen[$host]);

        return $addresses;
    }
}
