<?php

declare(strict_types=1);

namespace App\Actions\Finance;

use App\Enums\AdjustmentKind;
use App\Enums\ContractType;
use App\Enums\LessonStatus;
use App\Enums\PayeeType;
use App\Enums\PaymentStatus;
use App\Enums\PayslipStatus;
use App\Models\Lesson;
use App\Models\PayrollAdjustment;
use App\Models\Payslip;
use App\Models\SalaryContract;
use App\Models\User;
use App\Support\Finance\FinanceLock;
use App\Support\Sequences\Sequence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Computes a month of salaries as DRAFT payslips. Idempotent: a payee with a live payslip for the month is skipped.
 *  gross = monthly contracts (full amount, no proration) + per-hour/per-lesson rates x completed lessons of the month
 *  net   = gross + bonuses - deductions - advances. Adjustments that would push the net below zero stay pending for the next month.
 */
final class GeneratePayslips
{
    /** @return array{created: int, skipped: int, total_net: int} */
    public function handle(string $month, ?PayeeType $type, ?int $payeeId, User $actor): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month);
        $end = $start->endOfMonth();
        $created = 0;
        $skipped = 0;
        $totalNet = 0;

        DB::transaction(function () use ($month, $type, $payeeId, $actor, $start, $end, &$created, &$skipped, &$totalNet): void {
            FinanceLock::acquire();

            $contracts = SalaryContract::query()
                ->where('is_active', true)
                ->whereDate('starts_on', '<=', $end->toDateString())
                ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $start->toDateString()))
                ->when($type !== null, fn ($q) => $q->where('payee_type', $type->value))
                ->when($payeeId !== null, fn ($q) => $q->where('payee_id', $payeeId))
                ->orderBy('id')->get()
                ->groupBy(fn (SalaryContract $c): string => $c->payee_type->value.':'.$c->payee_id);

            foreach ($contracts as $group) {
                $first = $group->first();
                $live = Payslip::query()->where('month', $month)->where('payee_type', $first->payee_type->value)
                    ->where('payee_id', $first->payee_id)->where('live_marker', 1)->exists();

                if ($live) {
                    $skipped++;

                    continue;
                }

                $fixed = 0;
                $variable = 0;
                $count = 0;
                $minutes = 0;

                foreach ($group as $contract) {
                    if ($contract->type === ContractType::Monthly) {
                        $fixed += $contract->amount;

                        continue;
                    }

                    if ($contract->payee_type !== PayeeType::Teacher) {
                        continue;
                    }

                    [$n, $m] = $this->lessons((int) $contract->payee_id, $start, $end, $contract);
                    $count += $n;
                    $minutes += $m;
                    $variable += $contract->type === ContractType::PerLesson ? $n * $contract->amount : intdiv($m * $contract->amount, 60);
                }

                $gross = $fixed + $variable;

                if ($gross <= 0) {
                    continue;
                }

                [$bonus, $deduction, $advance, $used] = $this->settle($first->payee_type, (int) $first->payee_id, $end->toDateString(), $gross);

                $year = $start->format('Y');
                $payslip = Payslip::query()->create([
                    'number' => Payslip::formatNumber($year, Sequence::next('payslip:'.$year)),
                    'payee_type' => $first->payee_type,
                    'payee_id' => $first->payee_id,
                    'month' => $month,
                    'fixed_amount' => $fixed,
                    'lessons_count' => $count,
                    'lessons_minutes' => $minutes,
                    'variable_amount' => $variable,
                    'bonus_amount' => $bonus,
                    'deduction_amount' => $deduction,
                    'advance_amount' => $advance,
                    'net_amount' => $gross + $bonus - $deduction - $advance,
                    'status' => PayslipStatus::Draft,
                    'live_marker' => 1,
                    'created_by' => $actor->getKey(),
                ]);

                if ($used !== []) {
                    PayrollAdjustment::query()->whereIn('id', $used)->update(['payslip_id' => $payslip->getKey(), 'updated_at' => now()]);
                }

                $created++;
                $totalNet += $payslip->net_amount;
            }
        });

        return ['created' => $created, 'skipped' => $skipped, 'total_net' => $totalNet];
    }

    /** @return array{0: int, 1: int} completed lessons and minutes taught inside the contract window */
    private function lessons(int $teacherId, CarbonImmutable $start, CarbonImmutable $end, SalaryContract $contract): array
    {
        $from = max($start->toDateString(), (string) $contract->starts_on);
        $to = $contract->ends_on === null ? $end->toDateString() : min($end->toDateString(), (string) $contract->ends_on);

        $lessons = Lesson::query()
            ->where('teacher_id', $teacherId)
            ->where('status', LessonStatus::Completed->value)
            ->whereBetween('date', [$from, $to])
            ->get(['starts_at', 'ends_at']);

        $minutes = 0;

        foreach ($lessons as $lesson) {
            $minutes += max((int) ((strtotime("1970-01-01 {$lesson->ends_at}") - strtotime("1970-01-01 {$lesson->starts_at}")) / 60), 0);
        }

        return [$lessons->count(), $minutes];
    }

    /** @return array{0: int, 1: int, 2: int, 3: list<int>} bonus, deduction, advance totals and the adjustment ids used */
    private function settle(PayeeType $type, int $payeeId, string $until, int $gross): array
    {
        $pending = PayrollAdjustment::query()
            ->where('payee_type', $type->value)->where('payee_id', $payeeId)
            ->where('status', PaymentStatus::Valid->value)->whereNull('payslip_id')
            ->whereDate('effective_on', '<=', $until)
            ->orderBy('effective_on')->orderBy('id')->lockForUpdate()->get();

        $net = $gross;
        $totals = [AdjustmentKind::Bonus->value => 0, AdjustmentKind::Deduction->value => 0, AdjustmentKind::Advance->value => 0];
        $used = [];

        foreach ($pending->where('kind', AdjustmentKind::Bonus) as $a) {
            $net += $a->amount;
            $totals['bonus'] += $a->amount;
            $used[] = $a->getKey();
        }

        foreach ($pending->where('kind', '!=', AdjustmentKind::Bonus) as $a) {
            if ($net - $a->amount < 0) {
                continue;
            }

            $net -= $a->amount;
            $totals[$a->kind->value] += $a->amount;
            $used[] = $a->getKey();
        }

        return [$totals['bonus'], $totals['deduction'], $totals['advance'], $used];
    }
}
