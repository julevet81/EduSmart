<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Actions\Impersonation\EndImpersonation;
use App\Http\Controllers\Controller;
use App\Models\ImpersonationSession;
use App\Models\PlatformAdmin;
use App\Support\Impersonation\Impersonation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ImpersonationController extends Controller
{
    /** The institution can see every time the platform support entered its data. */
    public function index(TenantContext $tenant): JsonResponse
    {
        $rows = ImpersonationSession::query()->where('tenant_id', $tenant->require()->getKey())->orderByDesc('id')->limit(100)->get();
        $admins = PlatformAdmin::query()->whereIn('id', $rows->pluck('platform_admin_id'))->get()->keyBy('id');

        return response()->json(['data' => $rows->map(fn (ImpersonationSession $s): array => $s->present($admins->get($s->platform_admin_id)))->all()]);
    }

    /** The support agent leaves the account. */
    public function end(Request $request, EndImpersonation $action): Response
    {
        $session = Impersonation::current($request);
        abort_if($session === null, 404, 'This is not a support session.');

        $action->handle($session, 'user');

        return response()->noContent();
    }
}
