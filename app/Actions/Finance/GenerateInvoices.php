<?php

declare(strict_types=1);

namespace App\Actions\Finance;

use App\Enums\BillingMode;
use App\Enums\InvoiceStatus;
use App\Enums\LessonStatus;
use App\Models\Absence;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\Lesson;
use App\Models\StudentFee;
use App\Models\Term;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Finance\FinanceLock;
use App\Support\Sequences\Sequence;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Bills a month. Idempotent: (student_fee, period) is unique, so running it twice never double-bills.
 *  - monthly / per_lesson: one invoice for the month (per_lesson = completed lessons x price)
 *  - term: one invoice per term that has started by the end of the month
 *  - yearly / one_time: one invoice, the first time the month reaches the fee's start
 */
final class GenerateInvoices
{
    /** @return array{created: int, skipped: int, total_amount: int} */
    public function handle(string $month, ?int $studentId, User $actor): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month);
        $end = $start->endOfMonth();
        $tenantId = (int) app(TenantContext::class)->require()->getKey();
        $created = 0;
        $skipped = 0;
        $total = 0;

        DB::transaction(function () use ($start, $end, $studentId, $actor, $tenantId, &$created, &$skipped, &$total): void {
            FinanceLock::acquire();

            $fees = StudentFee::query()
                ->with('plan')
                ->where('is_active', true)
                ->when($studentId !== null, fn ($q) => $q->where('student_id', $studentId))
                ->whereDate('starts_on', '<=', $end->toDateString())
                ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $start->toDateString()))
                ->whereHas('plan', fn ($q) => $q->where('is_active', true))
                ->whereHas('student', fn ($q) => $q->where('is_active', true))
                ->orderBy('id')
                ->get();

            $candidates = [];

            foreach ($fees as $fee) {
                foreach ($this->periods($fee, $start, $end) as $period) {
                    $candidates[] = [$fee, $period];
                }
            }

            $existing = $candidates === [] ? [] : Invoice::query()
                ->whereIn('student_fee_id', $fees->modelKeys())
                ->get(['student_fee_id', 'period_key'])
                ->mapWithKeys(fn (Invoice $i): array => [$i->student_fee_id.'|'.$i->period_key => true])
                ->all();

            $rows = [];

            foreach ($candidates as [$fee, $period]) {
                if (isset($existing[$fee->getKey().'|'.$period['key']])) {
                    $skipped++;

                    continue;
                }

                $quantity = $fee->plan->billing_mode === BillingMode::PerLesson
                    ? $this->billableLessons($fee, $start, $end)
                    : 1;

                if ($quantity < 1) {
                    continue;
                }

                $gross = $quantity * $fee->plan->amount;
                $discount = $fee->discountFor($gross);

                $rows[] = [
                    'tenant_id' => $tenantId,
                    'student_id' => $fee->student_id,
                    'student_fee_id' => $fee->getKey(),
                    'fee_plan_id' => $fee->fee_plan_id,
                    'academic_year_id' => $fee->plan->academic_year_id,
                    'description' => $period['label'] === '' ? $fee->plan->name : $fee->plan->name.' — '.$period['label'],
                    'period_key' => $period['key'],
                    'period_start' => $period['start'],
                    'period_end' => $period['end'],
                    'issue_date' => today()->toDateString(),
                    'due_date' => $start->day(min($fee->plan->due_day, $start->daysInMonth))->toDateString(),
                    'quantity' => $quantity,
                    'unit_price' => $fee->plan->amount,
                    'discount_amount' => $discount,
                    'total_amount' => $gross - $discount,
                    'paid_amount' => 0,
                    'status' => InvoiceStatus::Open->value,
                    'created_by' => $actor->getKey(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($rows === []) {
                return;
            }

            $year = now()->format('Y');
            $first = Sequence::reserve('invoice:'.$year, count($rows));

            foreach ($rows as $i => &$row) {
                $row['number'] = Invoice::formatNumber($year, $first + $i);
                $total += $row['total_amount'];
            }
            unset($row);

            foreach (array_chunk($rows, 500) as $chunk) {
                Invoice::query()->insert($chunk);
            }

            $created = count($rows);

            AuditRecorder::event('invoice.generated', ['month' => $start->format('Y-m'), 'created' => $created, 'total_amount' => $total], $tenantId);
        });

        return ['created' => $created, 'skipped' => $skipped, 'total_amount' => $total];
    }

    /** @return list<array{key: string, label: string, start: ?string, end: ?string}> */
    private function periods(StudentFee $fee, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $month = $start->format('Y-m');

        return match ($fee->plan->billing_mode) {
            BillingMode::Monthly, BillingMode::PerLesson => [[
                'key' => $month, 'label' => $month, 'start' => $start->toDateString(), 'end' => $end->toDateString(),
            ]],
            BillingMode::Yearly => [['key' => 'year', 'label' => '', 'start' => null, 'end' => null]],
            BillingMode::OneTime => [['key' => 'once', 'label' => '', 'start' => null, 'end' => null]],
            BillingMode::Term => Term::query()
                ->where('academic_year_id', $fee->plan->academic_year_id)
                ->whereDate('starts_on', '<=', $end->toDateString())
                ->orderBy('number')
                ->get()
                ->map(fn (Term $t): array => [
                    'key' => 'term-'.$t->number, 'label' => $t->name,
                    'start' => $t->starts_on->toDateString(), 'end' => $t->ends_on->toDateString(),
                ])->all(),
        };
    }

    /** Completed lessons of the plan's subject (or all) that the student was enrolled for, minus absences unless the plan bills them. */
    private function billableLessons(StudentFee $fee, CarbonImmutable $start, CarbonImmutable $end): int
    {
        $count = 0;

        $enrollments = Enrollment::query()
            ->where('student_id', $fee->student_id)
            ->where('academic_year_id', $fee->plan->academic_year_id)
            ->whereDate('enrolled_on', '<=', $end->toDateString())
            ->where(fn ($q) => $q->whereNull('ended_on')->orWhereDate('ended_on', '>=', $start->toDateString()))
            ->get();

        foreach ($enrollments as $enrollment) {
            $from = max($start->toDateString(), $enrollment->enrolled_on->toDateString());
            $to = $enrollment->ended_on === null ? $end->toDateString() : min($end->toDateString(), $enrollment->ended_on->toDateString());

            $lessonIds = Lesson::query()
                ->where('group_id', $enrollment->group_id)
                ->where('status', LessonStatus::Completed->value)
                ->whereBetween('date', [$from, $to])
                ->when($fee->plan->subject_id !== null, fn ($q) => $q->where('subject_id', $fee->plan->subject_id))
                ->pluck('id');

            $absent = $fee->plan->bill_absences || $lessonIds->isEmpty() ? 0 : Absence::query()
                ->where('student_id', $fee->student_id)
                ->whereIn('lesson_id', $lessonIds)
                ->count();

            $count += $lessonIds->count() - $absent;
        }

        return $count;
    }
}
