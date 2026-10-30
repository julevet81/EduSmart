<?php

declare(strict_types=1);

namespace App\Support\Docs;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Builds an OpenAPI 3.1 document from the registered routes, so it can never drift from the code:
 * paths, methods, path parameters, authentication, the required permission ("can:" middleware), the X-Tenant header,
 * rate limits and, where a controller uses a FormRequest, its top-level fields. Inline validation is not read.
 */
final class OpenApiBuilder
{
    public function __construct(private readonly Router $router) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $paths = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $paths['/'.$route->uri()][strtolower($method)] = $this->operation($route, $method);
            }
        }

        ksort($paths);

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => (string) config('app.name').' API',
                'version' => '1.0.0',
                'description' => 'Multi-tenant management API for schools and training centres. Tenant routes need the X-Tenant header (or the tenant sub-domain). Errors use application/problem+json. Money is integer centimes; scores are integer hundredths.',
            ],
            'servers' => [['url' => (string) config('app.url')]],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Token from POST /auth/login (or /platform/auth/login).']],
                'responses' => [
                    'Problem' => ['description' => 'Error', 'content' => ['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Problem']]]],
                ],
                'schemas' => [
                    'Problem' => ['type' => 'object', 'properties' => [
                        'title' => ['type' => 'string'], 'status' => ['type' => 'integer'], 'detail' => ['type' => 'string'],
                        'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
                    ]],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function operation(Route $route, string $method): array
    {
        $middleware = array_map('strval', $route->gatherMiddleware());
        $segments = explode('/', substr($route->uri(), strlen('api/v1/')));
        $tag = $segments[0] === 'platform' ? 'platform/'.($segments[1] ?? 'auth') : $segments[0];
        $permissions = [];
        $throttle = null;

        foreach ($middleware as $m) {
            str_starts_with($m, 'can:') && $permissions[] = substr($m, 4);
            str_starts_with($m, 'throttle:') && $throttle = substr($m, 9);
        }

        $isTenant = in_array('tenant', $middleware, true);
        $secured = in_array('auth:sanctum', $middleware, true);
        $action = $route->getActionMethod();
        $controller = class_basename((string) ($route->getControllerClass() ?? ''));

        $parameters = [];

        foreach ($route->parameterNames() as $name) {
            $numeric = ($route->wheres[$name] ?? null) === '[0-9]+';
            $parameters[] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => $numeric ? 'integer' : 'string']];
        }

        $isTenant && $parameters[] = ['name' => 'X-Tenant', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'Institution slug (not needed on a tenant sub-domain).'];

        $notes = array_filter([
            $permissions !== [] ? 'Requires permission `'.implode('`, `', $permissions).'`.' : null,
            $throttle !== null ? "Rate limited ({$throttle})." : null,
        ]);

        $operation = [
            'tags' => [$tag],
            'summary' => trim(Str::headline($action).' — '.Str::headline(str_replace('Controller', '', $controller))),
            'operationId' => Str::camel(strtolower($method).'_'.str_replace(['/', '{', '}', '-'], ['_', '', '', '_'], substr($route->uri(), 7))),
            'parameters' => $parameters,
            'responses' => $this->responses($method, $secured, $permissions !== [], $isTenant),
        ];

        $notes !== [] && $operation['description'] = implode(' ', $notes);
        $permissions !== [] && $operation['x-permission'] = $permissions;
        $secured && $operation['security'] = [['bearerAuth' => []]];

        if (! in_array($method, ['GET', 'DELETE'], true) && ($body = $this->body($route)) !== null) {
            $operation['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => $body]]];
        }

        return $operation;
    }

    /** @return array<string, mixed> */
    private function responses(string $method, bool $secured, bool $permission, bool $tenant): array
    {
        $ok = match ($method) {
            'POST' => ['201' => ['description' => 'Created (or 200 with the result of an action)']],
            'DELETE' => ['204' => ['description' => 'Deleted']],
            default => ['200' => ['description' => 'OK']],
        };

        $problem = ['$ref' => '#/components/responses/Problem'];

        return $ok
            + ($secured ? ['401' => $problem] : [])
            + ($permission ? ['403' => $problem] : [])
            + ($tenant ? ['402' => ['description' => 'Subscription expired: read-only'] + $problem] : [])
            + ($method === 'GET' && $secured ? ['404' => $problem] : [])
            + ($method !== 'GET' && $method !== 'DELETE' ? ['422' => $problem] : []);
    }

    /** @return array<string, mixed>|null */
    private function body(Route $route): ?array
    {
        $class = $route->getControllerClass();

        if ($class === null || ! method_exists($class, $route->getActionMethod())) {
            return null;
        }

        foreach ((new ReflectionMethod($class, $route->getActionMethod()))->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin() || ! is_subclass_of($type->getName(), FormRequest::class)) {
                continue;
            }

            try {
                $rules = (new ReflectionMethod($type->getName(), 'rules'))->invoke((new \ReflectionClass($type->getName()))->newInstanceWithoutConstructor());
            } catch (Throwable) {
                return null;
            }

            return $this->schema(is_array($rules) ? $rules : []);
        }

        return null;
    }

    /** @param  array<string, mixed>  $rules @return array<string, mixed> */
    private function schema(array $rules): array
    {
        $properties = [];
        $required = [];

        foreach ($rules as $field => $rule) {
            if (str_contains((string) $field, '.') || str_contains((string) $field, '*')) {
                continue;
            }

            $list = array_values(array_filter(is_array($rule) ? $rule : explode('|', (string) $rule), 'is_string'));
            $property = match (true) {
                in_array('integer', $list, true) => ['type' => 'integer'],
                in_array('numeric', $list, true) => ['type' => 'number'],
                in_array('boolean', $list, true) => ['type' => 'boolean'],
                in_array('array', $list, true) => ['type' => 'array', 'items' => []],
                in_array('email', $list, true) => ['type' => 'string', 'format' => 'email'],
                (bool) preg_grep('/^date/', $list) => ['type' => 'string', 'format' => 'date'],
                default => ['type' => 'string'],
            };

            in_array('nullable', $list, true) && $property['type'] = [$property['type'], 'null'];
            $properties[(string) $field] = $property;
            in_array('required', $list, true) && $required[] = (string) $field;
        }

        return ['type' => 'object', 'properties' => $properties] + ($required !== [] ? ['required' => $required] : []);
    }
}
