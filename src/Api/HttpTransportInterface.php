<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Api;

interface HttpTransportInterface
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $payload
     */
    public function request(string $method, string $url, array $headers, ?array $payload): HttpResponse;
}
