<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\Billing\Subscription;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identifies the tenant from the subdomain (school.myapp.dz) or the X-Tenant header.
 * MUST run before authentication (registered in the priority list in bootstrap/app.php),
 * so the tenant scope is already active when the token's user is loaded.
 */
final class ResolveTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $slug = $this->extractSlug($request);

        if ($slug === null) {
            abort(400, 'Tenant not specified.');
        }

        $tenant = Tenant::resolveBySlug($slug);

        if ($tenant === null) {
            abort(404, 'Tenant not found.');
        }

        if (! $tenant->status->allowsAccess()) {
            abort(403, 'This account is suspended. Please contact support.');
        }

        // Subscription over (trial / paid period / grace): everything can still be read and people can still sign in,
        // but nothing may be written until the institution renews.
        if (! $request->isMethodSafe() && ! $request->is('api/v1/auth/*', 'api/v1/inbox/*')
            && Subscription::accessLevel($tenant) === Subscription::READ_ONLY) {
            abort(402, 'Your subscription has expired: the account is read-only until it is renewed.');
        }

        $this->context->set($tenant);
        $request->attributes->set('tenant', $tenant);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->context->forget();
    }

    private function extractSlug(Request $request): ?string
    {
        $base = config('saas.tenancy.base_domain');

        if (is_string($base) && $base !== '') {
            $host = $request->getHost();
            $suffix = '.'.$base;

            if (str_ends_with($host, $suffix)) {
                $sub = substr($host, 0, -strlen($suffix));

                if ($sub !== '' && ! str_contains($sub, '.') && ! in_array($sub, ['www', 'api'], true)) {
                    return $sub;
                }
            }
        }

        $header = $request->header((string) config('saas.tenancy.header'));

        return is_string($header) && $header !== '' ? mb_strtolower($header) : null;
    }
}
