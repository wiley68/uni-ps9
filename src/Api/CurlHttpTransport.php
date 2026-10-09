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

    private ControlPanelDnsResolver $dns;

    public function __construct(int $connectTimeout = 5, int $timeout = 15, ?ControlPanelDnsResolver $dns = null)
    {
        $this->connectTimeout = $connectTimeout;
        $this->timeout = $timeout;
        $this->dns = $dns ?? new ControlPanelDnsResolver();
    }

    public function request(string $method, string $url, array $headers, ?array $payload): HttpResponse
    {
        $environment = new \PrestaShop\Module\Unipayment\Configuration\ModuleDeploymentEnvironment();
        $origin = $environment->controlPanelUrl();
        $prefix = $environment->controlPanelApiBaseUrl() . '/';
        if (!str_starts_with($url, $prefix) || preg_match('/[\x00-\x20\x7f?#\\\\]/', $url)) {
            throw new ConnectionException('Control Panel request destination does not match deployment configuration.');
        }
        $host = substr($origin, strlen('https://'));
        $addresses = $this->dns->resolve($host);
        if (!function_exists('curl_init')) {
            throw new ConnectionException('The cURL PHP extension is not available.');
        }

        if (!defined('CURLOPT_RESOLVE') || curl_version()['version_number'] < 0x071503) {
            throw new ConnectionException('Control Panel transport requires cURL DNS pinning support.');
        }
        if (str_contains($addresses[0], ':') && curl_version()['version_number'] < 0x073900) {
            throw new ConnectionException('Control Panel IPv6 pinning requires cURL 7.57.0 or newer.');
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
            // One validated address per fresh handle works on older libcurl too.
            CURLOPT_RESOLVE => [$host . ':443:' . (str_contains($addresses[0], ':') ? '[' . $addresses[0] . ']' : $addresses[0])],
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
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

        if (!curl_setopt_array($handle, $options)) {
            curl_close($handle);
            throw new ConnectionException('Control Panel transport security options could not be applied.');
        }
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
