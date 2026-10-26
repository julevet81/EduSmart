<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Actions\Finance\GenerateInvoices;
use App\Actions\Finance\IssueInvoice;
use App\Actions\Finance\VoidInvoice;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\Student;
use App\Support\Validation\TenantRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

final class InvoiceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'student_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::enum(InvoiceStatus::class)],
            'overdue' => ['sometimes', 'boolean'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'q' => ['sometimes', 'string', 'max:50'],
        ]);

        $query = Invoice::query()->with('student')
            ->when($request->query('student_id'), fn ($q, $v) => $q->where('student_id', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->boolean('overdue'), fn ($q) => $q
                ->whereIn('status', [InvoiceStatus::Open->value, InvoiceStatus::PartiallyPaid->value])
                ->where('due_date', '<', today()->toDateString()))
            ->when($request->query('from'), fn ($q, $v) => $q->where('issue_date', '>=', $v))
            ->when($request->query('to'), fn ($q, $v) => $q->where('issue_date', '<=', $v))
            ->when($request->query('q'), fn ($q, $v) => $q->where('number', 'like', '%'.addcslashes((string) $v, '%_\\').'%'));

        return InvoiceResource::collection($query->orderByDesc('id')->cursorPaginate((int) $request->query('per_page', 25)));
    }

    public function show(string $id): InvoiceResource
    {
        return new InvoiceResource(Invoice::query()->with('student')->findOrFail($id));
    }

    /** One-off invoice (registration, books...). */
    public function store(Request $request, IssueInvoice $action): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', TenantRules::exists('students')],
            'description' => ['required', 'string', 'max:255'],
            'quantity' => ['integer', 'min:1', 'max:10000'],
            'unit_price' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'discount_amount' => ['integer', 'min:0', 'max:100000000000'],
            'due_date' => ['required', 'date_format:Y-m-d'],
        ]);

        $invoice = $action->handle(Student::query()->findOrFail($data['student_id']), $data, $request->user());

        return (new InvoiceResource($invoice))->response()->setStatusCode(201);
    }

    /** Bills a month for every active student fee (idempotent). */
    public function generate(Request $request, GenerateInvoices $action): JsonResponse
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'student_id' => ['sometimes', 'integer', TenantRules::exists('students')],
        ]);

        return response()->json($action->handle($data['month'], $data['student_id'] ?? null, $request->user()));
    }

    public function void(Request $request, string $id, VoidInvoice $action): InvoiceResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        return new InvoiceResource($action->handle(Invoice::query()->findOrFail($id), $data['reason'], $request->user()));
    }
}
