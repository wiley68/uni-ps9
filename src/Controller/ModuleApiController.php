<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Controller;

use PrestaShop\Module\Unipayment\Api\Exception\ModuleApiException;
use PrestaShop\Module\Unipayment\Api\ModuleApiError;
use PrestaShop\Module\Unipayment\Api\ModuleApiResponse;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Security\BoundedRawBodyReader;
use PrestaShop\Module\Unipayment\Security\ModuleRequestAuthenticator;
use PrestaShop\Module\Unipayment\Security\ModuleRequestSignatureProtocol;

abstract class ModuleApiController extends \ModuleFrontController
{
    /** @var bool */
    public $ssl = true;

    /** @var bool */
    public $auth = false;

    /** @var bool */
    public $ajax = true;

    public function postProcess(): void
    {
        try {
            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
                throw new ModuleApiException(
                    'Only POST requests are allowed.',
                    405,
                    ModuleApiError::METHOD_NOT_ALLOWED
                );
            }

            $rawBody = $this->readRawBody();
            $headers = $this->extractRequestHeaders();
            [$payload, $unicid] = (new ModuleRequestAuthenticator(new ConfigurationRepository()))
                ->authenticate($rawBody, $headers);
            $this->assertExpectedOperation($payload);
            $response = $this->handleAuthenticatedRequest($payload, $unicid);
            $this->sendJson($this->normalizeSuccessEnvelope($response), 200);
        } catch (ModuleApiException $exception) {
            $this->sendJson(
                ModuleApiResponse::failure(
                    $exception->getErrorCode() ?? ModuleApiError::INTERNAL_ERROR,
                    $exception->getMessage(),
                    $exception->getResponseData() ?? []
                ),
                $exception->getStatusCode()
            );
        } catch (\Throwable $exception) {
            \PrestaShopLogger::addLog(
                sprintf('UniPayment module API failure in %s.', static::class),
                3
            );
            $this->sendJson(
                ModuleApiResponse::failure(
                    ModuleApiError::INTERNAL_ERROR,
                    'The module could not process the request.'
                ),
                500
            );
        }
    }

    /**
     * Code-defined expected canonical operation for this endpoint.
     * Must not be derived from caller input.
     */
    abstract protected function expectedOperation(): string;

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    abstract protected function handleAuthenticatedRequest(array $payload, string $unicid): array;

    private function readRawBody(): string
    {
        $maxBytes = ModuleRequestSignatureProtocol::MAX_REQUEST_BODY_BYTES;
        $contentLengthHeader = $_SERVER['CONTENT_LENGTH'] ?? null;
        if (is_string($contentLengthHeader) && $contentLengthHeader !== '' && ctype_digit($contentLengthHeader)) {
            if ((int) $contentLengthHeader > $maxBytes) {
                throw new ModuleApiException(
                    'The request body exceeds the maximum allowed size.',
                    413,
                    ModuleApiError::PAYLOAD_TOO_LARGE
                );
            }
        }

        $stream = fopen('php://input', 'rb');
        if ($stream === false) {
            throw new ModuleApiException(
                'A JSON request body is required.',
                400,
                ModuleApiError::INVALID_PAYLOAD
            );
        }

        try {
            $read = BoundedRawBodyReader::read($stream, $maxBytes);
        } finally {
            fclose($stream);
        }

        if ($read['oversized']) {
            throw new ModuleApiException(
                'The request body exceeds the maximum allowed size.',
                413,
                ModuleApiError::PAYLOAD_TOO_LARGE
            );
        }

        if ($read['body'] === '') {
            throw new ModuleApiException(
                'A JSON request body is required.',
                400,
                ModuleApiError::INVALID_PAYLOAD
            );
        }

        return $read['body'];
    }

    /** @param array<string, mixed> $payload */
    private function assertExpectedOperation(array $payload): void
    {
        $operation = $payload['operation'] ?? null;
        $expected = $this->expectedOperation();
        if (!is_string($operation) || $operation === '' || $operation !== $expected) {
            throw new ModuleApiException(
                'The request operation is not supported by this endpoint.',
                400,
                ModuleApiError::UNSUPPORTED_OPERATION
            );
        }
    }

    /**
     * @param array<string, mixed> $response
     * @return array{success: true, error: null, message: string, data: array<string, mixed>|\stdClass}
     */
    private function normalizeSuccessEnvelope(array $response): array
    {
        $message = isset($response['message']) && is_string($response['message'])
            ? $response['message']
            : 'OK';
        $data = isset($response['data']) && is_array($response['data'])
            ? $response['data']
            : [];

        return ModuleApiResponse::success($message, $data);
    }

    /** @return array<string, string> */
    private function extractRequestHeaders(): array
    {
        $headers = [];

        if (function_exists('getallheaders')) {
            $requestHeaders = getallheaders();
            if (is_array($requestHeaders)) {
                foreach ($requestHeaders as $name => $value) {
                    if (is_string($name) && is_string($value)) {
                        $headers[$name] = $value;
                    }
                }
            }
        }

        foreach ($_SERVER as $key => $value) {
            if (!is_string($value) || strpos($key, 'HTTP_') !== 0) {
                continue;
            }

            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            $headers[$name] = $value;
        }

        return $headers;
    }

    /** @param array<string, mixed> $payload */
    private function sendJson(array $payload, int $statusCode): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        try {
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            http_response_code(500);
            echo '{"success":false,"error":"internal_error","message":"The module could not encode its response.","data":{}}';
        }

        exit;
    }
}
