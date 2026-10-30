<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Actions\Impersonation\EndImpersonation;
use App\Actions\Impersonation\StartImpersonation;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\ImpersonationSession;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Support login-as: short, audited, read-only unless write access is explicitly requested. */
final class ImpersonationController extends Controller
{
    public function store(Request $request, Tenant $tenant, StartImpersonation $action): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:10', 'max:250'],
            'write' => ['sometimes', 'boolean'],
            'minutes' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('saas.impersonation.max_minutes')],
        ]);

        /** @var PlatformAdmin $admin */
        $admin = $request->user();

        $result = $action->handle($admin, $tenant, $data['user_id'] ?? null, $data['reason'], (bool) ($data['write'] ?? false),
            (int) ($data['minutes'] ?? config('saas.impersonation.ttl_minutes')));

        return response()->json([
            'token' => $result['token'], 'token_type' => $result['token_type'], 'expires_at' => $result['expires_at'],
            'session' => $result['session']->present($admin), 'user' => new UserResource($result['user']->load('roles')),
        ], 201);
    }

    public function index(Tenant $tenant): JsonResponse
    {
        $rows = ImpersonationSession::query()->where('tenant_id', $tenant->getKey())->orderByDesc('id')->limit(100)->get();
        $admins = PlatformAdmin::query()->whereIn('id', $rows->pluck('platform_admin_id'))->get()->keyBy('id');

        return response()->json(['data' => $rows->map(fn (ImpersonationSession $s): array => $s->present($admins->get($s->platform_admin_id)))->all()]);
    }

    public function revoke(Tenant $tenant, string $id, EndImpersonation $action): JsonResponse
    {
        $session = ImpersonationSession::query()->where('tenant_id', $tenant->getKey())->findOrFail($id);
        $action->handle($session, 'admin');

        return response()->json(['data' => $session->refresh()->present(PlatformAdmin::query()->find($session->platform_admin_id))]);
    }
}
