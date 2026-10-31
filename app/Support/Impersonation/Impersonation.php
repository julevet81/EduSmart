<?php

declare(strict_types=1);

namespace App\Support\Impersonation;

use App\Models\ImpersonationSession;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/** Answers "is this request made by a support agent acting as a user?" and enforces the limits of such a session. */
final class Impersonation
{
    public const ABILITY = 'impersonated';

    /** Never allowed while impersonating: credentials, second factor, account takeover paths, privileges. */
    private const FORBIDDEN = [
        'api/v1/auth/password', 'api/v1/auth/two-factor', 'api/v1/auth/two-factor/*', 'api/v1/auth/logout', 'api/v1/auth/logout-all',
        'api/v1/users/*/two-factor/reset', 'api/v1/users/*/unlock', 'api/v1/portal-accounts/*',
    ];

    /** Forbidden for writes only (a support agent may look, but not hand out access or message people). */
    private const NO_WRITE = [
        'api/v1/users', 'api/v1/users/*', 'api/v1/roles', 'api/v1/roles/*',
        'api/v1/messages', 'api/v1/messages/*', 'api/v1/alerts/*',   // support never texts the parents
    ];

    public static function current(?Request $request = null): ?ImpersonationSession
    {
        $request ??= request();
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken || ! $token->can(self::ABILITY)) {
            return null;
        }

        if (! $request->attributes->has('impersonation')) {
            $request->attributes->set('impersonation', ImpersonationSession::query()->where('token_id', $token->getKey())->first());
        }

        return $request->attributes->get('impersonation');
    }

    public static function adminId(): ?int
    {
        $session = self::current();

        return $session === null ? null : (int) $session->platform_admin_id;
    }

    public static function guard(Request $request, ImpersonationSession $session): void
    {
        if (! $session->isActive()) {
            abort(401, 'This support session has ended.');
        }

        if ($request->is(...self::FORBIDDEN) || (! $request->isMethodSafe() && $request->is(...self::NO_WRITE))) {
            abort(403, 'This action is not available in a support session.');
        }

        if (! $session->can_write && ! $request->isMethodSafe() && ! $request->is('api/v1/auth/impersonation/end')) {
            abort(403, 'This support session is read-only.');
        }
    }
}
