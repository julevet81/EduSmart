<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FinanceReportController extends Controller
{
    /** Collected (by method), billed and outstanding for a date range. */
    public function summary(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $collected = Payment::query()->where('status', PaymentStatus::Valid->value)
            ->whereBetween('paid_on', [$data['from'], $data['to']])
            ->selectRaw('method, COUNT(*) as count, SUM(amount) as total')->groupBy('method')->get();

        $billed = Invoice::query()->where('status', '!=', InvoiceStatus::Void->value)
            ->whereBetween('issue_date', [$data['from'], $data['to']])->sum('total_amount');

        $open = [InvoiceStatus::Open->value, InvoiceStatus::PartiallyPaid->value];
        $outstanding = (int) Invoice::query()->whereIn('status', $open)
            ->selectRaw('COALESCE(SUM(total_amount - paid_amount), 0) as due')->value('due');
        $overdue = (int) Invoice::query()->whereIn('status', $open)->where('due_date', '<', today()->toDateString())
            ->selectRaw('COALESCE(SUM(total_amount - paid_amount), 0) as due')->value('due');

        return response()->json([
            'from' => $data['from'],
            'to' => $data['to'],
            'collected_total' => (int) $collected->sum('total'),
            'collected_by_method' => $collected->map(fn ($r): array => [
                'method' => $r->method instanceof \BackedEnum ? $r->method->value : $r->method,
                'count' => (int) $r->count, 'total' => (int) $r->total,
            ])->values(),
            'billed_total' => (int) $billed,
            'outstanding_total' => $outstanding,
            'overdue_total' => $overdue,
        ]);
    }

    /** Students who owe money, biggest debt first. */
    public function receivables(Request $request): JsonResponse
    {
        $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:200']]);

        $rows = Invoice::query()
            ->whereIn('status', [InvoiceStatus::Open->value, InvoiceStatus::PartiallyPaid->value])
            ->selectRaw('student_id, COUNT(*) as invoices, SUM(total_amount - paid_amount) as due, MIN(due_date) as oldest_due')
            ->groupBy('student_id')->orderByDesc('due')->limit((int) $request->query('limit', 50))->get();

        $names = \App\Models\Student::query()->withTrashed()->whereIn('id', $rows->pluck('student_id'))->get()->keyBy('id');

        return response()->json(['data' => $rows->map(fn ($r): array => [
            'student_id' => (int) $r->student_id,
            'registration_number' => $names[$r->student_id]->registration_number ?? null,
            'name' => isset($names[$r->student_id]) ? $names[$r->student_id]->first_name.' '.$names[$r->student_id]->last_name : null,
            'invoices' => (int) $r->invoices,
            'due' => (int) $r->due,
            'oldest_due' => (string) $r->oldest_due,
        ])->values()]);
    }
}
