<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Api;

use PrestaShop\Module\Unipayment\Api\Exception\ConnectionException;
use PrestaShop\Module\Unipayment\Api\Exception\TimeoutException;

final class CurlHttpTransport implements HttpTransportInterface
{
    /** @var int */
    private $connectTimeout;

    /** @var int */
    private $timeout;

    public function __construct(int $connectTimeout = 5, int $timeout = 15)
    {
        $this->connectTimeout = $connectTimeout;
        $this->timeout = $timeout;
    }

    public function request(string $method, string $url, array $headers, ?array $payload): HttpResponse
    {
        if (!function_exists('curl_init')) {
            throw new ConnectionException('The cURL PHP extension is not available.');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new ConnectionException('The Control Panel request could not be initialized.');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headerLines,
        ];

        if ($payload !== null) {
            try {
                $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                curl_close($handle);
                throw new ConnectionException('The Control Panel request payload could not be encoded.', 0, $exception);
            }
        }

        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);

        if ($body === false) {
            $errorNumber = curl_errno($handle);
            $error = curl_error($handle);
            curl_close($handle);

            if ($errorNumber === CURLE_OPERATION_TIMEDOUT) {
                throw new TimeoutException('The Control Panel request timed out.');
            }

            throw new ConnectionException('The Control Panel connection failed: ' . $error);
        }

        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return new HttpResponse($statusCode, (string) $body);
    }
}
