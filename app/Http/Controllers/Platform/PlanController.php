<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Support\Audit\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The plan catalogue. Plans are never deleted (tenants and history refer to them); retire one with is_active=false. */
final class PlanController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Plan::query()->orderBy('sort_order')->orderBy('id')->get()->map(fn (Plan $p): array => $this->present($p))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[a-z0-9_]{2,32}$/', 'unique:plans,code'],
        ] + $this->rules(creating: true));

        $plan = Plan::query()->create($data);
        AuditRecorder::event('plan.created', ['plan' => $plan->code]);

        return response()->json(['data' => $this->present($plan)], 201);
    }

    /** The code is immutable. */
    public function update(Request $request, string $id): JsonResponse
    {
        $plan = Plan::query()->findOrFail($id);
        $request->validate(['code' => ['prohibited']]);
        $plan->update($request->validate($this->rules(creating: false)));
        AuditRecorder::event('plan.updated', ['plan' => $plan->code, 'changes' => $plan->getChanges()]);

        return response()->json(['data' => $this->present($plan->refresh())]);
    }

    /** @return array<string, list<mixed>> */
    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:100'],
            'price_monthly' => [$required, 'integer', 'min:0', 'max:100000000000'],
            'max_students' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'max_users' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /** @return array<string, mixed> */
    private function present(Plan $p): array
    {
        return [
            'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'price_monthly' => $p->price_monthly,
            'max_students' => $p->max_students, 'max_users' => $p->max_users, 'is_active' => $p->is_active, 'sort_order' => $p->sort_order,
        ];
    }
}
