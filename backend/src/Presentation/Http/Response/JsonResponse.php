<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Response;

/**
 * JSON HTTP response.
 *
 * All API responses use this class.
 * Enforces Content-Type: application/json.
 * Provides standard QRIVO API envelope format.
 */
final class JsonResponse
{
    /**
     * @param array<string, mixed>|null $data
     * @param array<string, string>     $headers
     */
    public function __construct(
        private readonly mixed  $data,
        private readonly int    $statusCode = 200,
        private readonly array  $headers    = [],
    ) {}

    /**
     * Success response.
     *
     * @param array<string, mixed>|null $data
     */
    public static function success(
        mixed  $data    = null,
        string $message = 'OK',
        int    $status  = 200,
    ): self {
        return new self([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $status);
    }

    /**
     * Error response.
     * Security: does NOT expose stack traces or internal error details in production.
     *
     * @param array<string, mixed>|null $errors
     */
    public static function error(
        string $message,
        int    $status  = 400,
        mixed  $errors  = null,
    ): self {
        $body = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return new self($body, $status);
    }

    /**
     * Validation error response (422).
     *
     * @param array<string, string[]> $errors
     */
    public static function validationError(
        string $message,
        array  $errors,
    ): self {
        return new self([
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
        ], 422);
    }

    /**
     * Created response (201).
     *
     * @param array<string, mixed>|null $data
     */
    public static function created(mixed $data = null, string $message = 'Created.'): self
    {
        return self::success($data, $message, 201);
    }

    /**
     * No content response (204).
     */
    public static function noContent(): self
    {
        return new self(null, 204);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getData(): mixed
    {
        return $this->data;
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self($this->data, $this->statusCode, array_merge($this->headers, $headers));
    }

    /**
     * Send the response to the client.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->statusCode);
            header('Content-Type: application/json; charset=utf-8');

            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }
        }

        if ($this->data !== null) {
            echo json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }
}
