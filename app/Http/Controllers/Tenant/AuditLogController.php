<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class AuditLogController extends Controller
{
    public function index(Request $request, TenantContext $tenant): AnonymousResourceCollection
    {
        $request->validate([
            'event' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $logs = AuditLog::query()
            ->forTenant($tenant->require()->getKey())
            ->when($request->query('event'), fn ($q, string $event) => $q->where('event', $event))
            ->orderByDesc('id')
            ->cursorPaginate((int) $request->query('per_page', 50));

        return AuditLogResource::collection($logs);
    }
}
