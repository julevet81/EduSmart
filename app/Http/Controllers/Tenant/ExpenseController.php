<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Actions\Finance\RecordExpense;
use App\Actions\Finance\ReverseExpense;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use App\Support\Validation\TenantRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

final class ExpenseController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'expense_category_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::enum(PaymentStatus::class)],
            'method' => ['sometimes', Rule::enum(PaymentMethod::class)],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'q' => ['sometimes', 'string', 'max:100'],
        ]);

        $query = Expense::query()->with('category')
            ->when($request->query('expense_category_id'), fn ($q, $v) => $q->where('expense_category_id', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('method'), fn ($q, $v) => $q->where('method', $v))
            ->when($request->query('from'), fn ($q, $v) => $q->where('paid_on', '>=', $v))
            ->when($request->query('to'), fn ($q, $v) => $q->where('paid_on', '<=', $v))
            ->when($request->query('q'), fn ($q, $v) => $q->where(fn ($w) => $w
                ->where('description', 'like', '%'.addcslashes((string) $v, '%_\\').'%')
                ->orWhere('supplier', 'like', '%'.addcslashes((string) $v, '%_\\').'%')));

        return ExpenseResource::collection($query->orderByDesc('paid_on')->orderByDesc('id')->cursorPaginate((int) $request->query('per_page', 25)));
    }

    public function show(string $id): ExpenseResource
    {
        return new ExpenseResource(Expense::query()->with('category')->findOrFail($id));
    }

    public function store(Request $request, RecordExpense $action): JsonResponse
    {
        $request->merge(['idempotency_key' => $request->input('idempotency_key', $request->header('Idempotency-Key'))]);

        $data = $request->validate([
            'expense_category_id' => ['required', 'integer', TenantRules::exists('expense_categories')],
            'description' => ['required', 'string', 'max:255'],
            'supplier' => ['nullable', 'string', 'max:150'],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:100'],
            'paid_on' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        [$expense, $created] = $action->handle($data, $request->user());

        return (new ExpenseResource($expense->load('category')))->response()->setStatusCode($created ? 201 : 200);
    }

    public function reverse(Request $request, string $id, ReverseExpense $action): ExpenseResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        return new ExpenseResource($action->handle(Expense::query()->findOrFail($id), $data['reason'], $request->user())->load('category'));
    }
}
