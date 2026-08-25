<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Controller;

use PrestaShop\Module\Unipayment\Api\Exception\ModuleApiException;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Security\ModuleRequestAuthenticator;

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
                throw new ModuleApiException('Разрешени са само POST заявки.', 405);
            }

            [$payload, $rawBody] = $this->readJsonRequest();
            $headers = $this->extractRequestHeaders();
            $unicid = (new ModuleRequestAuthenticator(new ConfigurationRepository()))->authenticate($payload, $rawBody, $headers);
            $response = $this->handleAuthenticatedRequest($payload, $unicid);
            $this->sendJson($response, 200);
        } catch (ModuleApiException $exception) {
            $body = [
                'success' => false,
                'message' => $exception->getMessage(),
            ];
            if ($exception->getErrorCode() !== null) {
                $body['error'] = $exception->getErrorCode();
            }
            if ($exception->getResponseData() !== null) {
                $body['data'] = $exception->getResponseData();
            }
            $this->sendJson($body, $exception->getStatusCode());
        } catch (\Throwable $exception) {
            if (class_exists('\PrestaShopLogger', false)) {
                \PrestaShopLogger::addLog(
                    sprintf('UniPayment module API failure in %s.', static::class),
                    3
                );
            }
            $this->sendJson([
                'success' => false,
                'message' => 'Модулът не можа да обработи заявката.',
            ], 500);
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    abstract protected function handleAuthenticatedRequest(array $payload, string $unicid): array;

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function readJsonRequest(): array
    {
        $rawBody = file_get_contents('php://input');
        if (!is_string($rawBody) || $rawBody === '') {
            throw new ModuleApiException('Изисква се JSON тяло на заявката.', 400);
        }

        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ModuleApiException('JSON тялото на заявката е невалидно.', 400);
        }

        if (!is_array($payload)) {
            throw new ModuleApiException('JSON тялото на заявката трябва да бъде обект.', 400);
        }

        return [$payload, $rawBody];
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
            echo '{"success":false,"message":"Модулът не можа да кодира отговора."}';
        }

        exit;
    }
}
