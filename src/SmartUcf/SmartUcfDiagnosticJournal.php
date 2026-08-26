<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\SmartUcf;

use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;

final class SmartUcfDiagnosticJournal
{
    private const REDACTED = '[REDACTED]';
    private const SENSITIVE_KEYS = [
        'authorization',
        'bearer',
        'token',
        'access_token',
        'refresh_token',
        'secret',
        'secret_key',
        'password',
        'pass',
        'private_key',
        'private_key_pem',
        'certificate_pem',
        'certificate_password',
        'user',
        'clientfirstname',
        'clientlastname',
        'clientphone',
        'clientemail',
        'clientdeliveryaddress',
        'egn',
    ];

    /** @var ConfigurationRepository */
    private $configuration;
    /** @var SmartUcfDebugLogStoreInterface */
    private $store;

    public function __construct(ConfigurationRepository $configuration, SmartUcfDebugLogStoreInterface $store)
    {
        $this->configuration = $configuration;
        $this->store = $store;
    }

    /**
     * @param mixed $request
     * @param mixed $response
     */
    public function record(
        int $idShop,
        int $idOrder,
        string $orderId,
        int $httpCode,
        $request,
        $response,
        ?string $transportError = null
    ): bool {
        if ($idShop <= 0 || !$this->configuration->isDebugEnabled()) {
            return false;
        }

        return $this->store->insert([
            'id_shop' => $idShop,
            'ps_order_id' => max(0, $idOrder),
            'order_id' => trim($orderId),
            'http_code' => max(0, $httpCode),
            'request' => $this->sanitizeBody($request),
            'response' => $this->sanitizeBody($response),
            'transport_error' => $transportError !== null ? $this->sanitizeText($transportError) : null,
            'created_at_gmt' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function findLatestByOrderIdAndShop(string $orderId, int $idShop): ?array
    {
        if ($idShop <= 0) {
            return null;
        }
        $entry = $this->store->findLatestByOrderIdAndShop($orderId, $idShop);

        return $entry === null ? null : $this->sanitizeEntry($entry);
    }

    /** @return array<string, mixed> */
    public function buildExport(): array
    {
        $entries = array_map(function (array $entry): array {
            return $this->sanitizeEntry($entry);
        }, $this->store->findAll());

        return [
            'module' => 'unipayment',
            'module_version' => '2.0.1',
            'exported_at_gmt' => gmdate('c'),
            'debug_enabled' => $this->configuration->isDebugEnabled(),
            'total_entries' => count($entries),
            'entries' => $entries,
        ];
    }

    /** @param array<string, mixed> $entry @return array<string, mixed> */
    private function sanitizeEntry(array $entry): array
    {
        if (array_key_exists('request', $entry)) {
            $entry['request'] = $this->sanitizeBody($entry['request']);
        }
        if (array_key_exists('response', $entry)) {
            $entry['response'] = $this->sanitizeBody($entry['response']);
        }
        if (isset($entry['transport_error'])) {
            $entry['transport_error'] = $this->sanitizeText((string) $entry['transport_error']);
        }

        return $entry;
    }

    /** @param mixed $body @return mixed */
    private function sanitizeBody($body)
    {
        if (is_string($body)) {
            try {
                $body = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                return '[REDACTED: unparseable payload]';
            }
        }

        return $this->sanitizeValue($body);
    }

    /** @param mixed $value @return mixed */
    private function sanitizeValue($value, string $key = '')
    {
        if (in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
            return self::REDACTED;
        }
        if (!is_array($value)) {
            return is_string($value) ? $this->sanitizeText($value) : $value;
        }
        foreach ($value as $itemKey => $item) {
            $value[$itemKey] = $this->sanitizeValue($item, (string) $itemKey);
        }

        return $value;
    }

    private function sanitizeText(string $value): string
    {
        $value = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer ' . self::REDACTED, $value) ?? self::REDACTED;

        return preg_replace('/\b(secret|token|password|pass|private[_ -]?key)\b\s*[:=]\s*[^\s,;]+/i', '$1=' . self::REDACTED, $value) ?? self::REDACTED;
    }
}
