<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\PlatformAdmin;
use App\Support\Audit\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;

final class PlatformAuthController extends Controller
{
    /** Valid bcrypt hash used to equalize timing when the email does not exist. */
    private const DUMMY_HASH = '$2y$12$QFSvym3EPaeTqeWJVVoXr.2dwpdy.Gm5BQDSITFGKbkoeu3AkQbSK';

    public function login(LoginRequest $request): JsonResponse
    {
        $email = mb_strtolower(trim($request->validated('email')));
        $admin = PlatformAdmin::query()->where('email', $email)->first();

        $passwordMatches = Hash::check($request->validated('password'), $admin?->password ?? self::DUMMY_HASH);

        if ($admin === null || ! $passwordMatches || ! $admin->is_active) {
            AuditRecorder::event('platform.login_failed', ['email' => $email]);

            abort(401, 'Invalid credentials.');
        }

        $admin->forceFill(['last_login_at' => now()])->save();

        $expiresAt = now()->addMinutes((int) config('saas.tokens.platform_ttl_minutes'));
        $token = $admin->createToken($request->validated('device_name') ?? 'platform', ['platform'], $expiresAt);

        AuditRecorder::event('platform.login', ['admin_id' => $admin->getKey()]);

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
            'admin' => ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user();

        return response()->json(['admin' => ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email]]);
    }

    public function logout(Request $request): Response
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user();
        $admin->currentAccessToken()->delete();

        return response()->noContent();
    }
}
