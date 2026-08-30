<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http;

/**
 * HTTP Request abstraction.
 *
 * Wraps PHP superglobals into an immutable value object.
 * The raw $_POST, $_GET, and php://input are never accessed outside this class.
 */
final class Request
{
    /**
     * @param array<string, mixed>  $query    $_GET
     * @param array<string, mixed>  $body     Parsed JSON body or $_POST
     * @param array<string, string> $headers  HTTP headers (lowercase keys)
     * @param array<string, string> $server   $_SERVER
     * @param array<string, mixed>  $params   Route parameters (injected by router)
     */
    public function __construct(
        private readonly string $method,
        private readonly string $uri,
        private readonly array  $query   = [],
        private readonly array  $body    = [],
        private readonly array  $headers = [],
        private readonly array  $server  = [],
        private array           $params  = [],
    ) {}

    /**
     * Create a Request from PHP superglobals.
     */
    public static function fromGlobals(): self
    {
        $method  = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri     = strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/';
        $query   = $_GET ?? [];
        $server  = $_SERVER ?? [];
        $headers = self::extractHeaders($server);

        // Parse JSON body
        $body = [];
        $contentType = $headers['content-type'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $raw  = file_get_contents('php://input');
            $body = json_decode($raw ?: '{}', true) ?? [];
        } elseif ($method !== 'GET') {
            $body = $_POST ?? [];
        }

        return new self($method, $uri, $query, $body, $headers, $server);
    }

    /**
     * @param array<string, string> $server
     * @return array<string, string>
     */
    private static function extractHeaders(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name           = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $name           = strtolower(str_replace('_', '-', $key));
                $headers[$name] = $value;
            }
        }
        return $headers;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    /** @return array<string, mixed> */
    public function getQuery(): array
    {
        return $this->query;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function getBody(): array
    {
        return $this->body;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getServer(string $key): mixed
    {
        return $this->server[$key] ?? null;
    }

    public function getIp(): string
    {
        return $this->server['HTTP_X_FORWARDED_FOR']
            ?? $this->server['REMOTE_ADDR']
            ?? '0.0.0.0';
    }

    /** @return array<string, mixed> */
    public function getParams(): array
    {
        return $this->params;
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    /** @param array<string, mixed> $params */
    public function withParams(array $params): self
    {
        $clone         = clone $this;
        $clone->params = $params;
        return $clone;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function getBearerToken(): ?string
    {
        $header = $this->getHeader('authorization');
        if ($header === null) {
            return null;
        }
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }
        return null;
    }
}
