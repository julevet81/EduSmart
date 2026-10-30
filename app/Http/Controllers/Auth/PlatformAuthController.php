<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\PlatformAdmin;
use App\Support\Auth\AuthFlow;
use App\Support\Auth\Realm;
use App\Support\Auth\Sessions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class PlatformAuthController extends Controller
{
    public function __construct(private readonly AuthFlow $flow, private readonly Sessions $sessions) {}

    public function login(LoginRequest $request): JsonResponse
    {
        return $this->flow->login($this->realm(), $request->validated('email'), $request->validated('password'), $request->validated('device_name'));
    }

    public function twoFactorChallenge(Request $request): JsonResponse
    {
        $data = $request->validate(['challenge' => ['required', 'string', 'size:64'], 'code' => ['required', 'string', 'max:32']]);

        return $this->flow->challenge($this->realm(), $data['challenge'], $data['code']);
    }

    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate(['refresh_token' => ['required', 'string', 'size:64']]);

        return $this->flow->refresh($this->realm(), $data['refresh_token']);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user();

        return response()->json(['admin' => ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email], 'two_factor_enabled' => $admin->hasTwoFactor()]);
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

    private function realm(): Realm
    {
        return new Realm('platform_admin', PlatformAdmin::class, null, ['platform'], 'platform',
            fn (Model $admin): array => ['admin' => ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email]]);
    }
}
