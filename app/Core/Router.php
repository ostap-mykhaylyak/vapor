<?php
namespace Vapor\Core;

/**
 * Router minimale con supporto a parametri dinamici {name}.
 */
class Router
{
    /** @var array<int,array{method:string,pattern:string,handler:callable|array}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable|array $handler): void
    {
        $this->routes[] = [
            'method'  => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
        ];
    }

    public function get(string $p, callable|array $h): void    { $this->add('GET', $p, $h); }
    public function post(string $p, callable|array $h): void   { $this->add('POST', $p, $h); }
    public function put(string $p, callable|array $h): void    { $this->add('PUT', $p, $h); }
    public function delete(string $p, callable|array $h): void { $this->add('DELETE', $p, $h); }

    /**
     * Risolve la richiesta e restituisce [handler, params] oppure null.
     */
    public function match(string $method, string $path): ?array
    {
        $method = strtoupper($method);
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            $regex = $this->compile($route['pattern']);
            if (preg_match($regex, $path, $m)) {
                $params = array_filter($m, fn($k) => !is_int($k), ARRAY_FILTER_USE_KEY);
                return [$route['handler'], $params];
            }
        }
        return null;
    }

    private function compile(string $pattern): string
    {
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?<$1>[^/]+)', $pattern);
        return '#^' . $regex . '$#';
    }
}
