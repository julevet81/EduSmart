<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Actions\Finance\RecordPayment;
use App\Actions\Finance\ReversePayment;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Models\Student;
use App\Support\Validation\TenantRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

final class PaymentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'student_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::enum(PaymentStatus::class)],
            'method' => ['sometimes', Rule::enum(PaymentMethod::class)],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $query = Payment::query()->with('allocations')
            ->when($request->query('student_id'), fn ($q, $v) => $q->where('student_id', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('method'), fn ($q, $v) => $q->where('method', $v))
            ->when($request->query('from'), fn ($q, $v) => $q->where('paid_on', '>=', $v))
            ->when($request->query('to'), fn ($q, $v) => $q->where('paid_on', '<=', $v));

        return PaymentResource::collection($query->orderByDesc('id')->cursorPaginate((int) $request->query('per_page', 25)));
    }

    public function show(string $id): PaymentResource
    {
        return new PaymentResource(Payment::query()->with('allocations')->findOrFail($id));
    }

    public function store(Request $request, RecordPayment $action): JsonResponse
    {
        $request->merge(['idempotency_key' => $request->input('idempotency_key', $request->header('Idempotency-Key'))]);

        $data = $request->validate([
            'student_id' => ['required', 'integer', TenantRules::exists('students')],
            'guardian_id' => ['nullable', 'integer', TenantRules::exists('guardians')],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:100'],
            'paid_on' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'allocations' => ['sometimes', 'array', 'min:1', 'max:100'],
            'allocations.*.invoice_id' => ['required', 'integer', 'distinct', TenantRules::exists('invoices')],
            'allocations.*.amount' => ['required', 'integer', 'min:1'],
        ]);

        [$payment, $created] = $action->handle(Student::query()->findOrFail($data['student_id']), $data, $request->user());

        return (new PaymentResource($payment))->response()->setStatusCode($created ? 201 : 200);
    }

    public function reverse(Request $request, string $id, ReversePayment $action): PaymentResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        return new PaymentResource($action->handle(Payment::query()->findOrFail($id), $data['reason'], $request->user()));
    }
}
