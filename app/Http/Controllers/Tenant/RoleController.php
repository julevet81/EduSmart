<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Support\Rbac\RoleBlueprint;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Role;

final class RoleController extends Controller
{
    public function index(TenantContext $tenant): JsonResponse
    {
        $roles = Role::query()
            ->where('tenant_id', $tenant->id())
            ->where('guard_name', RoleBlueprint::GUARD)
            ->with('permissions')
            ->orderBy('id')
            ->get()
            ->map(static fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->sort()->values(),
            ]);

        return response()->json(['data' => $roles]);
    }
}
