<?php

declare(strict_types=1);

use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureTenantUser;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Http\Problem;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'tenant.user' => EnsureTenantUser::class,
            'platform' => EnsurePlatformAdmin::class,
        ]);

        // The tenant must be known BEFORE authentication so the tenant scope applies to the token's user.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: ResolveTenant::class);

        // Behind a reverse proxy / load balancer: TRUSTED_PROXIES=* or a comma separated list of addresses.
        // Needed so the client IP (rate limits, audit trail) and the https scheme are the real ones.
        if (($proxies = env('TRUSTED_PROXIES')) !== null && $proxies !== '') {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', (string) $proxies)));
        }

        $middleware->api(prepend: [ForceJsonResponse::class, SecurityHeaders::class]);
        $middleware->throttleApi('api');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request): bool => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return match (true) {
                $e instanceof ValidationException => Problem::validation($e),
                $e instanceof AuthenticationException => Problem::make(401, 'Unauthenticated.'),
                $e instanceof HttpExceptionInterface => Problem::make(
                    $e->getStatusCode(),
                    $e->getStatusCode() === 404 ? null : $e->getMessage(),
                    headers: $e->getHeaders(),
                ),
                // In debug mode keep Laravel's detailed output; in production never leak internals.
                default => config('app.debug') ? null : Problem::make(500, 'Server error.'),
            };
        });
    })
    ->create();
