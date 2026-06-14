<?php
namespace Vapor\Core;

/**
 * Wrapper sulla richiesta HTTP corrente.
 */
class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $params = [],
        private readonly ?string $rawBody = null,
    ) {}

    public static function fromGlobals(): self
    {
        $uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        // Override del metodo via _method per form HTML.
        if ($method === 'POST' && isset($_POST['_method'])) {
            $method = strtoupper($_POST['_method']);
        }
        return new self(
            method:  $method,
            path:    rtrim($uri, '/') ?: '/',
            query:   $_GET,
            post:    $_POST,
            rawBody: file_get_contents('php://input') ?: null,
        );
    }

    public function withParams(array $params): self
    {
        return new self($this->method, $this->path, $this->query, $this->post, $params, $this->rawBody);
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function json(): array
    {
        if ($this->rawBody === null || $this->rawBody === '') {
            return [];
        }
        $data = json_decode($this->rawBody, true);
        return is_array($data) ? $data : [];
    }

    public function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $xrw    = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        return str_contains($accept, 'application/json') || $xrw === 'XMLHttpRequest';
    }
}
