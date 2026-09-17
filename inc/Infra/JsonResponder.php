<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Infra;

/**
 * The single place that emits a JSON response for the plugin's AJAX endpoints.
 * Keeps controllers free of header/encoding boilerplate (DRY). Each method
 * terminates the request.
 */
final class JsonResponder
{
    /** @param array<string,mixed> $data */
    public function ok(array $data): void
    {
        $this->send(200, $data);
    }

    /** A validation/client error with a machine key + human message. */
    public function error(int $status, string $message, string $code = ''): void
    {
        $this->send($status, ['error' => $message, 'code' => $code]);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function send(int $status, array $payload): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($status);
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
