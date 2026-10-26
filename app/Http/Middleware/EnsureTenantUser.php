<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Defense in depth: the authenticated account must be an active user of the resolved tenant. */
final class EnsureTenantUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $tenant = $request->attributes->get('tenant');

        if (! $user instanceof User || ! $tenant instanceof Tenant || (int) $user->tenant_id !== (int) $tenant->getKey()) {
            abort(403, 'This token does not belong to this tenant.');
        }

        if (! $user->is_active) {
            abort(403, 'This account is disabled.');
        }

        return $next($request);
    }
}
