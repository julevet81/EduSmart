<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Support\Audit\AuditRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

/** Login, second-factor challenge and token refresh, shared by tenant users and platform admins. */
final class AuthFlow
{
    /** Valid bcrypt hash used to equalize timing when the email does not exist. */
    private const DUMMY_HASH = '$2y$12$QFSvym3EPaeTqeWJVVoXr.2dwpdy.Gm5BQDSITFGKbkoeu3AkQbSK';

    public function __construct(
        private readonly Sessions $sessions,
        private readonly TwoFactor $twoFactor,
        private readonly Challenges $challenges,
        private readonly LoginThrottle $throttle,
    ) {}

    public function login(Realm $realm, string $email, string $password, ?string $device): JsonResponse
    {
        $email = mb_strtolower(trim($email));
        $account = $realm->model::query()->where('email', $email)->first();

        $matches = Hash::check($password, $account?->password ?? self::DUMMY_HASH);
        $locked = $account !== null && $this->throttle->isLocked($account);

        if ($account === null || ! $matches || ! $account->is_active || $locked) {
            if ($account !== null && ! $matches && ! $locked) {
                $this->throttle->recordFailure($account, $realm->auditPrefix, $realm->tenantId);
            }

            AuditRecorder::event($realm->auditPrefix.'.login_failed', ['email' => $email, 'locked' => $locked], $realm->tenantId);

            abort(401, 'Invalid credentials.');
        }

        if ($account->hasTwoFactor()) {
            return response()->json([
                'two_factor_required' => true,
                'challenge' => $this->challenges->create($realm->type, $account->getKey(), $realm->tenantId, ['device' => $device]),
                'expires_in' => (int) config('saas.two_factor.challenge_ttl_seconds'),
            ]);
        }

        return $this->finish($realm, $account, $device);
    }

    public function challenge(Realm $realm, string $id, string $code): JsonResponse
    {
        $data = $this->challenges->find($id, $realm->type, $realm->tenantId);
        $account = $data === null ? null : $realm->model::query()->find($data['subject_id']);

        if ($data === null || $account === null || ! $account->is_active || $this->throttle->isLocked($account)) {
            abort(401, 'Invalid or expired challenge.');
        }

        if (! $this->twoFactor->check($account, $code)) {
            $this->challenges->fail($id);
            $this->throttle->recordFailure($account, $realm->auditPrefix, $realm->tenantId);
            AuditRecorder::event($realm->auditPrefix.'.two_factor_failed', ['account_id' => $account->getKey()], $realm->tenantId);

            abort(401, 'Invalid code.');
        }

        $this->challenges->forget($id);

        return $this->finish($realm, $account, $data['payload']['device'] ?? null);
    }

    public function refresh(Realm $realm, string $plain): JsonResponse
    {
        $row = $this->sessions->claim($plain, $realm->type, $realm->tenantId, $realm->auditPrefix);
        $account = $row === null ? null : $realm->model::query()->find($row->subject_id);

        if ($row === null || $account === null || ! $account->is_active || $this->throttle->isLocked($account)) {
            if ($row !== null) {
                $this->sessions->revokeFamily($row->family_id);
            }

            abort(401, 'Invalid refresh token.');
        }

        return response()->json($this->sessions->issue(
            $account, $realm->tenantId, 'refresh', $realm->abilities, $row->family_id, $row->family_expires_at,
        ) + ($realm->present)($account));
    }

    private function finish(Realm $realm, Model $account, ?string $device): JsonResponse
    {
        $this->throttle->recordSuccess($account);
        AuditRecorder::event($realm->auditPrefix.'.login', ['account_id' => $account->getKey()], $realm->tenantId);

        return response()->json($this->sessions->issue($account, $realm->tenantId, $device ?? 'api', $realm->abilities) + ($realm->present)($account));
    }
}
