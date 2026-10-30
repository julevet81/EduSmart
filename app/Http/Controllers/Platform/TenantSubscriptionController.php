<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Actions\Billing\RecordSubscription;
use App\Actions\Billing\VoidSubscription;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Support\Billing\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Payments of a tenant's subscription, recorded by hand by a platform administrator. */
final class TenantSubscriptionController extends Controller
{
    public function show(Tenant $tenant): JsonResponse
    {
        return response()->json(['data' => Subscription::state($tenant)]);
    }

    public function index(Tenant $tenant): JsonResponse
    {
        return response()->json(['data' => TenantSubscription::query()->with('plan:id,code,name')->where('tenant_id', $tenant->getKey())
            ->orderByDesc('period_end')->orderByDesc('id')->get()->map(fn (TenantSubscription $s): array => $this->present($s))->all()]);
    }

    public function store(Request $request, Tenant $tenant, RecordSubscription $action): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'string', 'exists:plans,code'],
            'months' => ['required', 'integer', 'min:1', 'max:24'],
            'amount' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000000'],
            'method' => ['required', 'in:cash,ccp,bank_transfer,cheque,other'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:120'],
            'paid_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:64'],
            'force' => ['sometimes', 'boolean'],
        ]);

        $result = $action->handle($tenant, $data, (int) $request->user()->getKey());

        return response()->json([
            'data' => $this->present($result['subscription']->load('plan:id,code,name')),
            'subscription' => Subscription::state($tenant->refresh()),
            'replayed' => $result['replayed'],
        ], $result['replayed'] ? 200 : 201);
    }

    public function void(Request $request, Tenant $tenant, string $id, VoidSubscription $action): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $row = $action->handle($tenant, (int) $id, $data['reason']);

        return response()->json(['data' => $this->present($row->load('plan:id,code,name')), 'subscription' => Subscription::state($tenant->refresh())]);
    }

    /** @return array<string, mixed> */
    private function present(TenantSubscription $s): array
    {
        return [
            'id' => $s->id, 'plan' => $s->plan === null ? null : ['code' => $s->plan->code, 'name' => $s->plan->name],
            'period_start' => $s->period_start->toDateString(), 'period_end' => $s->period_end->toDateString(), 'months' => $s->months,
            'amount' => $s->amount, 'method' => $s->method, 'reference' => $s->reference, 'paid_on' => $s->paid_on->toDateString(),
            'note' => $s->note, 'status' => $s->status, 'void_reason' => $s->void_reason, 'created_at' => $s->created_at?->toIso8601String(),
        ];
    }
}
