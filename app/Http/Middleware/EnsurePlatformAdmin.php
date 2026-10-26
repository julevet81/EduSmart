<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\PlatformAdmin;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePlatformAdmin
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Platform routes never operate inside a tenant.
        $this->context->forget();

        $admin = $request->user();

        if (! $admin instanceof PlatformAdmin || ! $admin->is_active) {
            abort(403, 'Platform administrators only.');
        }

        return $next($request);
    }
}
