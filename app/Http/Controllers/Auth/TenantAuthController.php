<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;

final class TenantAuthController extends Controller
{
    /** Valid bcrypt hash used to equalize timing when the email does not exist. */
    private const DUMMY_HASH = '$2y$12$QFSvym3EPaeTqeWJVVoXr.2dwpdy.Gm5BQDSITFGKbkoeu3AkQbSK';

    public function login(LoginRequest $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        $email = mb_strtolower(trim($request->validated('email')));

        // The tenant scope guarantees we only ever look inside the current tenant.
        $user = User::query()->where('email', $email)->first();

        $passwordMatches = Hash::check($request->validated('password'), $user?->password ?? self::DUMMY_HASH);

        if ($user === null || ! $passwordMatches || ! $user->is_active) {
            AuditRecorder::event('auth.login_failed', ['email' => $email], (int) $tenant->getKey());

            abort(401, 'Invalid credentials.');
        }

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        $expiresAt = now()->addMinutes((int) config('saas.tokens.tenant_ttl_minutes'));
        $token = $user->createToken($request->validated('device_name') ?? 'api', ['tenant'], $expiresAt);

        AuditRecorder::event('auth.login', ['user_id' => $user->getKey()], (int) $tenant->getKey());

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
            'user' => new UserResource($user->load('roles')),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'user' => new UserResource($user->load('roles')),
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
        ]);
    }

    public function logout(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $user->currentAccessToken()->delete();

        return response()->noContent();
    }

    public function logoutAll(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $user->tokens()->delete();

        return response()->noContent();
    }
}
