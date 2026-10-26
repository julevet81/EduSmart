<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passes when the user holds AT LEAST ONE of the listed permissions.
 * Usage on a route: ->middleware(EnsureAnyPermission::class.':schedule.view,teaching.own')
 * Row-level checks (e.g. "only my own lessons") remain the controller's job.
 */
final class EnsureAnyPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->canAny($permissions)) {
            abort(403, 'You do not have permission to perform this action.');
        }

        return $next($request);
    }
}
