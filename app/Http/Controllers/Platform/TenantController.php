<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Actions\Tenants\ProvisionTenant;
use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTenantRequest;
use App\Http\Requests\UpdateTenantRequest;
use App\Http\Resources\TenantResource;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

final class TenantController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['sometimes', Rule::enum(TenantStatus::class)],
            'q' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $tenants = Tenant::query()
            ->when($request->query('status'), fn ($q, string $status) => $q->where('status', $status))
            ->when($request->query('q'), function ($q, string $term): void {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('slug', 'like', $like));
            })
            ->orderByDesc('id')
            ->cursorPaginate((int) $request->query('per_page', 25));

        return TenantResource::collection($tenants);
    }

    public function store(StoreTenantRequest $request, ProvisionTenant $provision): JsonResponse
    {
        $result = $provision->handle($request->toData());

        return (new TenantResource($result->tenant))
            ->additional(['owner' => ['id' => $result->owner->getKey(), 'email' => $result->owner->email]])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Tenant $tenant): TenantResource
    {
        return new TenantResource($tenant);
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant): TenantResource
    {
        $tenant->update($request->validated());

        return new TenantResource($tenant->refresh());
    }
}
