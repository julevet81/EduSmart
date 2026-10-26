<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\PayslipStatus;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Payslip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cash-basis result for a period: what was collected minus what was spent and paid in salaries.
 * "billed" is shown for reference (accrual view) but is not part of the result.
 * A reversed item is simply left out, so corrections never distort the figures.
 */
final class ProfitLossController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $range = [$data['from'], $data['to']];

        $collected = (int) Payment::query()->where('status', PaymentStatus::Valid->value)->whereBetween('paid_on', $range)->sum('amount');
        $billed = (int) Invoice::query()->where('status', '!=', InvoiceStatus::Void->value)->whereBetween('issue_date', $range)->sum('total_amount');

        $byCategory = Expense::query()->where('expenses.status', PaymentStatus::Valid->value)->whereBetween('expenses.paid_on', $range)
            ->join('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
            ->selectRaw('expense_categories.id as category_id, expense_categories.name as category, SUM(expenses.amount) as total')
            ->groupBy('expense_categories.id', 'expense_categories.name')->orderByDesc('total')->get();

        $expenses = (int) $byCategory->sum('total');
        $salaries = (int) Payslip::query()->where('status', PayslipStatus::Paid->value)->whereBetween('paid_on', $range)->sum('net_amount');

        $months = [];
        $add = static function (string $key, $rows) use (&$months): void {
            foreach ($rows as $row) {
                $months[$row->month][$key] = (int) $row->total;
            }
        };

        $add('collected', Payment::query()->where('status', PaymentStatus::Valid->value)->whereBetween('paid_on', $range)
            ->selectRaw('SUBSTRING(paid_on, 1, 7) as month, SUM(amount) as total')->groupBy('month')->get());
        $add('expenses', Expense::query()->where('status', PaymentStatus::Valid->value)->whereBetween('paid_on', $range)
            ->selectRaw('SUBSTRING(paid_on, 1, 7) as month, SUM(amount) as total')->groupBy('month')->get());
        $add('salaries', Payslip::query()->where('status', PayslipStatus::Paid->value)->whereBetween('paid_on', $range)
            ->selectRaw('SUBSTRING(paid_on, 1, 7) as month, SUM(net_amount) as total')->groupBy('month')->get());

        ksort($months);

        return response()->json([
            'from' => $data['from'],
            'to' => $data['to'],
            'collected' => $collected,
            'billed' => $billed,
            'expenses' => $expenses,
            'salaries' => $salaries,
            'result' => $collected - $expenses - $salaries,
            'expenses_by_category' => $byCategory->map(fn ($r): array => [
                'category_id' => (int) $r->category_id, 'category' => $r->category, 'total' => (int) $r->total,
            ])->values(),
            'by_month' => collect($months)->map(fn (array $m, string $month): array => [
                'month' => $month,
                'collected' => $m['collected'] ?? 0,
                'expenses' => $m['expenses'] ?? 0,
                'salaries' => $m['salaries'] ?? 0,
                'result' => ($m['collected'] ?? 0) - ($m['expenses'] ?? 0) - ($m['salaries'] ?? 0),
            ])->values(),
        ]);
    }
}
