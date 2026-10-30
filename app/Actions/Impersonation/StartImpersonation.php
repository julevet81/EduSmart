<?php

declare(strict_types=1);

namespace App\Actions\Impersonation;

use App\Models\ImpersonationSession;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Impersonation\Impersonation;
use App\Support\Rbac\RoleBlueprint;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Gives a platform administrator a short, audited, read-only-by-default token of one tenant user. No refresh token. */
final class StartImpersonation
{
    public function __construct(private readonly TenantContext $context) {}

    /** @return array{token: string, token_type: string, expires_at: string, session: ImpersonationSession, user: User} */
    public function handle(PlatformAdmin $admin, Tenant $tenant, ?int $userId, string $reason, bool $write, int $minutes): array
    {
        if (config('saas.impersonation.require_two_factor') && ! $admin->hasTwoFactor()) {
            abort(403, 'Enable two-factor authentication before using support access.');
        }

        if (! $tenant->status->allowsAccess()) {
            abort(422, 'This tenant is suspended.');
        }

        return $this->context->run($tenant, function () use ($admin, $tenant, $userId, $reason, $write, $minutes): array {
            $user = $userId === null
                ? User::query()->role(RoleBlueprint::OWNER)->where('is_active', true)->orderBy('id')->first()
                : User::query()->whereKey($userId)->first();

            if ($user === null || ! $user->is_active) {
                abort(422, 'No active user to act as.');
            }

            $expires = now()->addMinutes(max(1, min($minutes, (int) config('saas.impersonation.max_minutes'))));

            return DB::transaction(function () use ($admin, $tenant, $user, $reason, $write, $expires): array {
                $token = $user->createToken('support:'.$admin->getKey(), ['tenant', Impersonation::ABILITY], $expires);

                $session = ImpersonationSession::query()->create([
                    'tenant_id' => $tenant->getKey(), 'platform_admin_id' => $admin->getKey(), 'user_id' => $user->getKey(),
                    'token_id' => $token->accessToken->getKey(), 'reason' => $reason, 'can_write' => $write,
                    'ip_address' => request()->ip(), 'started_at' => now(), 'expires_at' => $expires,
                ]);

                AuditRecorder::event('platform.impersonation_started', [
                    'session_id' => $session->id, 'user_id' => $user->getKey(), 'reason' => $reason,
                    'can_write' => $write, 'expires_at' => $expires->toIso8601String(),
                ], (int) $tenant->getKey());

                return ['token' => $token->plainTextToken, 'token_type' => 'Bearer', 'expires_at' => $expires->toIso8601String(), 'session' => $session, 'user' => $user];
            });
        });
    }
}
