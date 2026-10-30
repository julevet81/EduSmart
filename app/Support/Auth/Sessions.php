<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\RefreshToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Access tokens are short; a rotating, single-use refresh token renews them.
 * Every login starts a "family": presenting a refresh token twice (a stolen copy) revokes the whole family.
 */
final class Sessions
{
    /**
     * @param  list<string>  $abilities
     * @return array{token: string, token_type: string, expires_at: string, refresh_token: string, refresh_expires_at: string}
     */
    public function issue(Model $who, ?int $tenantId, string $device, array $abilities, ?string $familyId = null, ?Carbon $familyExpires = null): array
    {
        $realm = $tenantId === null ? 'platform' : 'tenant';

        $accessExpires = now()->addMinutes((int) config("saas.tokens.{$realm}_ttl_minutes"));
        $access = $who->createToken($device, $abilities, $accessExpires);

        $familyExpires ??= now()->addDays((int) config('saas.tokens.refresh_max_days'));
        $refreshExpires = now()->addDays((int) config("saas.tokens.{$realm}_refresh_days"));
        $refreshExpires = $refreshExpires->greaterThan($familyExpires) ? $familyExpires->copy() : $refreshExpires;

        $plain = Str::random(64);

        RefreshToken::query()->create([
            'subject_type' => $who->getMorphClass(), 'subject_id' => $who->getKey(), 'tenant_id' => $tenantId,
            'token_hash' => hash('sha256', $plain), 'family_id' => $familyId ?? (string) Str::uuid(),
            'access_token_id' => $access->accessToken->getKey(),
            'expires_at' => $refreshExpires, 'family_expires_at' => $familyExpires,
            'ip_address' => request()->ip(), 'user_agent' => Str::limit((string) request()->userAgent(), 250, ''),
        ]);

        return [
            'token' => $access->plainTextToken, 'token_type' => 'Bearer', 'expires_at' => $accessExpires->toIso8601String(),
            'refresh_token' => $plain, 'refresh_expires_at' => $refreshExpires->toIso8601String(),
        ];
    }

    /**
     * Claims a refresh token for rotation. Returns the row, or null when it is unknown, foreign, revoked, expired or REUSED
     * (reuse revokes the family). The caller then issues the new pair inside the same family.
     */
    public function claim(string $plain, string $subjectType, ?int $tenantId, string $auditPrefix): ?RefreshToken
    {
        $row = RefreshToken::query()->where('token_hash', hash('sha256', $plain))->first();

        if ($row === null || $row->subject_type !== $subjectType || ($row->tenant_id === null ? null : (int) $row->tenant_id) !== $tenantId
            || $row->revoked_at !== null || $row->expires_at->isPast()) {
            return null;
        }

        $claimed = RefreshToken::query()->whereKey($row->getKey())->whereNull('used_at')->whereNull('revoked_at')->update(['used_at' => now()]);

        if ($claimed !== 1) {
            $this->revokeFamily($row->family_id);
            \App\Support\Audit\AuditRecorder::event($auditPrefix.'.refresh_reuse', ['subject_id' => $row->subject_id, 'family' => $row->family_id], $tenantId);

            return null;
        }

        PersonalAccessToken::query()->whereKey($row->access_token_id)->delete();

        return $row;
    }

    public function revokeFamily(string $familyId): void
    {
        $rows = RefreshToken::query()->where('family_id', $familyId)->get(['id', 'access_token_id']);

        PersonalAccessToken::query()->whereIn('id', $rows->pluck('access_token_id')->filter())->delete();
        RefreshToken::query()->where('family_id', $familyId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    /** Logout of the current device. */
    public function revokeCurrent(Model $who): void
    {
        $token = $who->currentAccessToken();
        $family = RefreshToken::query()->where('access_token_id', $token->getKey())->value('family_id');

        $family !== null ? $this->revokeFamily($family) : null;
        $token->delete();
    }

    /** Every device (password reset, deactivation, 2FA reset, logout-all). */
    public function revokeAll(Model $who): void
    {
        $who->tokens()->delete();
        RefreshToken::query()->where('subject_type', $who->getMorphClass())->where('subject_id', $who->getKey())
            ->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    /** Every device except the current one (password change). */
    public function revokeOthers(Model $who): void
    {
        $current = $who->currentAccessToken();
        $keep = RefreshToken::query()->where('access_token_id', $current->getKey())->value('family_id');

        $who->tokens()->whereKeyNot($current->getKey())->delete();
        RefreshToken::query()->where('subject_type', $who->getMorphClass())->where('subject_id', $who->getKey())
            ->whereNull('revoked_at')->when($keep !== null, fn ($q) => $q->where('family_id', '!=', $keep))->update(['revoked_at' => now()]);
    }
}
