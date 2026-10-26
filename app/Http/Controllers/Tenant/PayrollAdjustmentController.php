<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Actions\Finance\CancelAdjustment;
use App\Actions\Finance\CreateAdjustment;
use App\Enums\AdjustmentKind;
use App\Enums\PayeeType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PayrollAdjustmentResource;
use App\Models\PayrollAdjustment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

final class PayrollAdjustmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'payee_type' => ['sometimes', Rule::enum(PayeeType::class)],
            'payee_id' => ['sometimes', 'integer'],
            'kind' => ['sometimes', Rule::enum(AdjustmentKind::class)],
            'status' => ['sometimes', Rule::enum(PaymentStatus::class)],
            'pending' => ['sometimes', 'boolean'],
        ]);

        $query = PayrollAdjustment::query()
            ->when($request->query('payee_type'), fn ($q, $v) => $q->where('payee_type', $v))
            ->when($request->query('payee_id'), fn ($q, $v) => $q->where('payee_id', $v))
            ->when($request->query('kind'), fn ($q, $v) => $q->where('kind', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->boolean('pending'), fn ($q) => $q->where('status', PaymentStatus::Valid->value)->whereNull('payslip_id'));

        return PayrollAdjustmentResource::collection($query->orderByDesc('id')->cursorPaginate((int) $request->query('per_page', 25)));
    }

    public function store(Request $request, CreateAdjustment $action): JsonResponse
    {
        $data = $request->validate([
            'payee_type' => ['required', Rule::enum(PayeeType::class)],
            'payee_id' => ['required', 'integer'],
            'kind' => ['required', Rule::enum(AdjustmentKind::class)],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'effective_on' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        return (new PayrollAdjustmentResource($action->handle($data, $request->user())))->response()->setStatusCode(201);
    }

    public function cancel(Request $request, string $id, CancelAdjustment $action): PayrollAdjustmentResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        return new PayrollAdjustmentResource($action->handle(PayrollAdjustment::query()->findOrFail($id), $data['reason'], $request->user()));
    }
}
