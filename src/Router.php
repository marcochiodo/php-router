<?php

namespace mrblue\PhpRouter;

final class Router {

    private array $map;

    function __construct(
        public readonly string $api_prefix,
        string $map_file,
    ) {
        if (!is_file($map_file)) {
            throw new RouterException("Route map file not found: $map_file");
        }

        $map = include $map_file;

        if (!is_array($map)) {
            throw new RouterException("Route map file must return an array: $map_file");
        }

        $this->map = $map;
    }

    /**
     * Walks the route map, instantiates the matched controller and invokes
     * the method matching the HTTP verb. Returns the controller's return value:
     * output handling (echo, json, headers, status codes) is up to the app.
     *
     * @param array $server the $_SERVER superglobal
     * @throws RouteNotMatchException no route for the request path (maps to 404)
     * @throws MethodNotAllowedException route matched but method is missing (maps to 405)
     * @throws RouterException map stale or class missing (maps to 500)
     */
    function dispatch(array $server): mixed {

        [$method, $path] = $this->parseRequest($server);

        if (!str_starts_with($path, $this->api_prefix)) {
            throw new RouteNotMatchException(
                "Path '$path' is outside the API prefix '{$this->api_prefix}'"
            );
        }

        $path = substr($path, strlen($this->api_prefix));
        $segments = array_values(array_filter(explode('/', $path), fn($s) => $s !== ''));

        $params = [];
        $node = $this->map;

        foreach ($segments as $segment) {
            $segment = rawurldecode($segment);
            if (isset($node['static'][$segment])) {
                $node = $node['static'][$segment];
            } elseif (isset($node['param'])) {
                $params[$node['param']['name']] = $segment;
                $node = $node['param']['child'];
            } else {
                throw new RouteNotMatchException("No route for '$path'");
            }
        }

        $class = $node['handler'] ?? null;

        if ($class === null) {
            throw new RouteNotMatchException("No handler for '$path'");
        }

        if (!class_exists($class)) {
            throw new RouterException(
                "Handler class '$class' not found. Check the project autoload and re-run the route build."
            );
        }

        $controller_method = strtolower($method);

        if (!method_exists($class, $controller_method)) {
            throw new MethodNotAllowedException(
                "Method $method not allowed on '$path'",
                $this->allowedMethods($class),
            );
        }

        return (new $class)->$controller_method($params);
    }

    /**
     * @return array{0: string, 1: string} [HTTP method, request path without query string]
     */
    private function parseRequest(array $server): array {

        $method = strtoupper($server['REQUEST_METHOD'] ?? 'GET');

        if (($method === 'HEAD') && isset($server['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
            $method = strtoupper($server['HTTP_X_HTTP_METHOD_OVERRIDE']);
        }

        $request_uri = $server['REQUEST_URI'] ?? '/';
        $path = parse_url($request_uri, PHP_URL_PATH);

        if (!is_string($path) || $path === '') {
            throw new RouteNotMatchException("Malformed request URI: '$request_uri'");
        }

        return [$method, $path];
    }

    /**
     * @return string[]
     */
    private function allowedMethods(string $class): array {

        $allowed = [];
        foreach (['get', 'post', 'put', 'patch', 'delete', 'head', 'options'] as $m) {
            if (method_exists($class, $m)) {
                $allowed[] = strtoupper($m);
            }
        }
        return $allowed;
    }
}
