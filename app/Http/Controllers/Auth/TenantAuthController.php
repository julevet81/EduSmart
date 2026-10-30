<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\AuthFlow;
use App\Support\Auth\Realm;
use App\Support\Auth\Sessions;
use App\Support\Billing\Subscription;
use App\Support\Impersonation\Impersonation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Database\Eloquent\Model;

final class TenantAuthController extends Controller
{
    public function __construct(private readonly AuthFlow $flow, private readonly Sessions $sessions) {}

    public function login(LoginRequest $request): JsonResponse
    {
        return $this->flow->login($this->realm($request), $request->validated('email'), $request->validated('password'), $request->validated('device_name'));
    }

    /** Second step of a login for accounts with two-factor authentication. */
    public function twoFactorChallenge(Request $request): JsonResponse
    {
        $data = $request->validate(['challenge' => ['required', 'string', 'size:64'], 'code' => ['required', 'string', 'max:32']]);

        return $this->flow->challenge($this->realm($request), $data['challenge'], $data['code']);
    }

    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate(['refresh_token' => ['required', 'string', 'size:64']]);

        return $this->flow->refresh($this->realm($request), $data['refresh_token']);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'user' => new UserResource($user->load('roles')),
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
            'two_factor_enabled' => $user->hasTwoFactor(),
            'subscription' => Subscription::state($request->attributes->get('tenant')),
            'impersonation' => ($session = Impersonation::current($request)) === null ? null
                : ['can_write' => $session->can_write, 'expires_at' => $session->expires_at->toIso8601String(), 'reason' => $session->reason],
        ]);
    }

    public function logout(Request $request): Response
    {
        $this->sessions->revokeCurrent($request->user());

        return response()->noContent();
    }

    public function logoutAll(Request $request): Response
    {
        $this->sessions->revokeAll($request->user());

        return response()->noContent();
    }

    private function realm(Request $request): Realm
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');

        return new Realm('user', User::class, (int) $tenant->getKey(), ['tenant'], 'auth',
            fn (Model $user): array => ['user' => new UserResource($user->load('roles'))]);
    }
}
